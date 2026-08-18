<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Database\Seeders\PlanningItemSeeder;
use Database\Seeders\SystemThresholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompletionBackdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(SystemThresholdSeeder::class);
    }

    public function test_backdate_thresholds_are_seeded_and_editable_in_system_settings(): void
    {
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->assertSame('30', SystemThreshold::query()->where('key', 'backdate_self_service_days')->value('value'));
        $this->assertSame('90', SystemThreshold::query()->where('key', 'backdate_max_days')->value('value'));

        $threshold = SystemThreshold::query()->where('key', 'backdate_self_service_days')->firstOrFail();

        $this->actingAs($superadmin)
            ->get(route('system-thresholds.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SystemThresholds/Index')
                ->where('systemThresholds.data', fn ($rows) => collect($rows)->pluck('key')->contains('backdate_self_service_days')
                    && collect($rows)->pluck('key')->contains('backdate_max_days'))
            );

        $this->actingAs($superadmin)
            ->put(route('system-thresholds.update', $threshold), [
                'key' => 'backdate_self_service_days',
                'value' => '14',
                'description' => $threshold->description,
            ])
            ->assertRedirect(route('system-thresholds.index'));

        $this->assertSame('14', $threshold->refresh()->value);
    }

    public function test_completing_today_needs_no_note(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $item->refresh();

        $this->assertSame('complete', $item->status);
        $this->assertFalse($item->is_backdated);
        $this->assertNull($item->backdated_days);
    }

    public function test_backdate_within_self_service_allows_an_empty_note(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(10)->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $item->refresh();

        $this->assertSame('complete', $item->status);
        $this->assertTrue($item->is_backdated);
        $this->assertSame(10, $item->backdated_days);
        $this->assertNull($item->backdate_override_by);
    }

    public function test_backdate_above_self_service_allows_a_note_without_minimum_length(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(60)->toDateString(),
                'notes' => 'T',
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $item->refresh();

        $this->assertSame('complete', $item->status);
        $this->assertTrue($item->is_backdated);
        $this->assertSame(60, $item->backdated_days);
    }

    public function test_backdate_beyond_max_is_rejected_and_points_to_superadmin(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        $response = $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(120)->toDateString(),
                'notes' => 'Pekerjaan lama yang baru ditemukan berkasnya saat audit gudang.',
            ]);

        $response->assertSessionHasErrors('completed_date');
        $this->assertStringContainsString('Superadmin', session('errors')->first('completed_date'));
        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_completion_date_cannot_precede_previous_completion(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();
        $item->unitPlanning->update(['last_done_date' => today()->subDays(5)->toDateString()]);

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(20)->toDateString(),
                'notes' => 'Catatan cukup panjang untuk lolos tier singkat.',
            ])
            ->assertSessionHasErrors('completed_date');

        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_completion_date_cannot_be_in_the_future(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('completed_date');

        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_changing_self_service_threshold_does_not_make_notes_required(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        SystemThreshold::query()->where('key', 'backdate_self_service_days')->update(['value' => '45']);

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(40)->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame(40, $item->refresh()->backdated_days);
    }

    public function test_lowering_max_threshold_rejects_a_previously_allowed_backdate(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();

        SystemThreshold::query()->where('key', 'backdate_max_days')->update(['value' => '15']);

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(20)->toDateString(),
                'notes' => 'Alasan yang cukup rinci untuk lolos tier manapun sebelumnya.',
            ])
            ->assertSessionHasErrors('completed_date');

        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_superadmin_can_record_beyond_max_through_the_correction_form(): void
    {
        [, $workOrder, $item] = $this->makeInProgressItem();
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($superadmin)
            ->get(route('work-orders.items.backdate-completion.edit', [$workOrder, $item]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/BackdateCompletion')
                ->where('item.id', $item->id)
                ->where('backdateThresholds.max_days', 90)
            );

        $this->actingAs($superadmin)
            ->post(route('work-orders.items.backdate-completion.update', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(200)->toDateString(),
                'notes' => 'Berkas kerja ditemukan saat audit gudang, dikonfirmasi ke mekanik yang mengerjakan.',
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $item->refresh();

        $this->assertSame('complete', $item->status);
        $this->assertTrue($item->is_backdated);
        $this->assertSame(200, $item->backdated_days);
        $this->assertSame($superadmin->id, $item->backdate_override_by);
    }

    public function test_correction_form_still_requires_a_detailed_reason(): void
    {
        [, $workOrder, $item] = $this->makeInProgressItem();
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($superadmin)
            ->post(route('work-orders.items.backdate-completion.update', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(200)->toDateString(),
                'notes' => 'Telat',
            ])
            ->assertSessionHasErrors('notes');

        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_non_superadmin_cannot_reach_the_correction_form(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $workOrder->site_id]);

        $this->actingAs($planner)->get(route('work-orders.items.backdate-completion.edit', [$workOrder, $item]))->assertForbidden();
        $this->actingAs($mechanic)->get(route('work-orders.items.backdate-completion.edit', [$workOrder, $item]))->assertForbidden();

        $this->actingAs($planner)
            ->post(route('work-orders.items.backdate-completion.update', [$workOrder, $item]), [
                'completed_odo' => 80500,
                'completed_date' => today()->subDays(200)->toDateString(),
                'notes' => 'Alasan rinci yang panjangnya lebih dari tiga puluh karakter.',
            ])
            ->assertForbidden();

        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_unit_history_shows_the_backdated_badge_and_note(): void
    {
        [$mechanic, $workOrder, $item] = $this->makeInProgressItem();
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $item]), [
            'completed_odo' => 80500,
            'completed_date' => today()->subDays(12)->toDateString(),
            'notes' => 'Dikerjakan dua minggu lalu, baru sempat dicatat.',
        ])->assertRedirect(route('mechanic.tasks'));

        $this->actingAs($superadmin)
            ->get(route('units.history', $workOrder->unit_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.replacements.data.0.is_backdated', true)
                ->where('history.replacements.data.0.backdated_days', 12)
                ->where('history.replacements.data.0.is_backdate_override', false)
                ->where('history.replacements.data.0.notes', 'Dikerjakan dua minggu lalu, baru sempat dicatat.')
            );

        $historySource = file_get_contents(resource_path('js/Pages/Units/History.tsx'));

        $this->assertStringContainsString('Dicatat mundur (', $historySource);
        $this->assertStringContainsString('Koreksi Superadmin', $historySource);
    }

    /**
     * @return array{0: User, 1: WorkOrder, 2: WorkOrderItem}
     */
    private function makeInProgressItem(): array
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site Backdate', 'region' => 'Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'DD 4242 QA',
            'type' => 'Operasional',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 80000,
            'status' => 'active',
        ]);

        $planningItem = PlanningItem::query()->where('name', 'PM Check / Reguler Services')->firstOrFail();
        $planning = UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
            [
                'last_done_km' => 70000,
                'last_done_date' => today()->subDays(365)->toDateString(),
                'next_due_km' => 80000,
                'next_due_date' => today()->toDateString(),
            ],
        );

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
            'approved_at' => now(),
        ]);

        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'in_progress',
        ]);

        return [$mechanic, $workOrder->refresh(), $item->refresh()];
    }
}
