<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ManualProjection;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    #[DataProvider('manualCreationRoles')]
    public function test_manual_creation_permission_matches_policy_for_unit_card_actions(UserRole $role, bool $allowed): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $planning->update(['next_due_date' => '2026-11-09']);
        $user = User::factory()->create(['role' => $role, 'region_id' => $unit->site->region_id, 'site_id' => $unit->site_id]);
        $this->assertSame($allowed, $user->can('create', ManualProjection::class));

        $response = $this->actingAs($user)->get(route('projections.index'));

        if (! $allowed) {
            $response->assertForbidden();

            return;
        }

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Projections/Index')
            ->where('permissions.can_create_manual', $allowed)
            ->has('projection.by_unit.data', 1)
            ->where('projection.by_unit.data.0.unit_id', $unit->id));
    }

    /** @return array<string, array{UserRole, bool}> */
    public static function manualCreationRoles(): array
    {
        return [
            'superadmin' => [UserRole::Superadmin, true],
            'spv ho' => [UserRole::SpvHo, true],
            'planner area' => [UserRole::PlannerArea, true],
            'mekanik' => [UserRole::Mekanik, false],
        ];
    }

    public function test_authorized_roles_can_submit_replace_without_mutating_existing_planning(): void
    {
        foreach ([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea] as $role) {
            [$unit, $item, $planning] = $this->scenario();
            $before = $planning->fresh()->getAttributes();
            $user = User::factory()->create(['role' => $role, 'region_id' => $unit->site->region_id]);

            $this->actingAs($user)->from(route('projections.index'))->post(route('projections.manual.store'), [
                ...$this->payload($unit, $item), 'created_by' => 999, 'status' => 'fulfilled', 'unit_planning_id' => 999,
            ])->assertRedirect(route('projections.index'))->assertSessionHasNoErrors();

            $manual = ManualProjection::query()->where('unit_id', $unit->id)->sole();
            $this->assertSame($user->id, $manual->created_by);
            $this->assertSame($planning->id, $manual->unit_planning_id);
            $this->assertSame('active', $manual->status);
            $this->assertSame('replace', $manual->workOrderItem->status);
            $this->assertSame($before, $planning->fresh()->getAttributes());
        }

        $this->assertDatabaseCount('unit_plannings', 3);
        $this->assertDatabaseCount('work_orders', 3);
        $this->assertDatabaseCount('work_order_items', 3);
    }

    public function test_planner_cannot_create_or_cancel_in_another_region(): void
    {
        [$ownUnit] = $this->scenario();
        [$outsideUnit, $item] = $this->scenario('Other Region');
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $ownUnit->site->region_id]);

        $this->actingAs($planner)->postJson(route('projections.manual.store'), $this->payload($outsideUnit, $item))->assertForbidden();
        $manual = $this->manual($outsideUnit, $item);
        $this->patchJson(route('projections.manual.cancel', $manual))->assertForbidden();
        $this->assertSame('active', $manual->fresh()->status);
        $this->assertDatabaseCount('manual_projections', 1);
    }

    public function test_mechanic_is_forbidden_from_creating_cancelling_and_viewing_projections(): void
    {
        [$unit, $item] = $this->scenario();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $unit->site_id]);
        $manual = $this->manual($unit, $item);

        $this->actingAs($mechanic)->postJson(route('projections.manual.store'), $this->payload($unit, $item))->assertForbidden();
        $this->patchJson(route('projections.manual.cancel', $manual))->assertForbidden();
        $this->get(route('projections.index'))->assertForbidden();
    }

    public function test_planner_without_a_region_cannot_create_manual_entries(): void
    {
        [$unit, $item] = $this->scenario();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => null, 'site_id' => $unit->site_id]);
        $this->actingAs($planner)->postJson(route('projections.manual.store'), $this->payload($unit, $item))->assertForbidden();
    }

    public function test_unassigned_planner_cannot_see_manual_entries_or_unit_options(): void
    {
        [$unit, $item] = $this->scenario();
        $this->manual($unit, $item);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => null, 'site_id' => null]);
        $this->actingAs($planner)->get(route('projections.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('manual_projections.data', 0)->has('manual_units', 0)
                ->has('projection.by_unit.data', 0)->where('permissions.can_create_manual', false));
    }

    public function test_head_office_roles_can_create_and_cancel_in_another_region(): void
    {
        [$unit, $item] = $this->scenario('Other Region');
        $homeRegion = Region::query()->create(['name' => 'Home Region']);

        foreach ([UserRole::Superadmin, UserRole::SpvHo] as $role) {
            $user = User::factory()->create(['role' => $role, 'region_id' => $homeRegion->id]);
            $this->actingAs($user)->post(route('projections.manual.store'), $this->payload($unit, $item))
                ->assertRedirect()->assertSessionHasNoErrors();
            $manual = ManualProjection::query()->active()->sole();
            $this->patch(route('projections.manual.cancel', $manual))->assertRedirect();
            $this->assertSame('cancelled', $manual->fresh()->status);
        }
    }

    public function test_second_active_entry_for_the_same_unit_and_item_is_rejected(): void
    {
        [$unit, $item] = $this->scenario();
        $this->manual($unit, $item);
        $this->actingAs(User::factory()->create(['role' => UserRole::SpvHo]))
            ->postJson(route('projections.manual.store'), $this->payload($unit, $item))
            ->assertUnprocessable()->assertJsonValidationErrors('planning_item_id');
        $this->assertDatabaseCount('manual_projections', 1);
    }

    public function test_past_dates_invalid_reasons_and_other_without_notes_are_rejected(): void
    {
        [$unit, $item] = $this->scenario();
        $this->actingAs(User::factory()->create(['role' => UserRole::Superadmin]));

        foreach ([['target_date' => '2026-11-01'], ['reason' => 'invalid'], ['reason' => 'lainnya', 'notes' => '   '], ['unit_id' => 999999], ['planning_item_id' => 999999]] as $invalid) {
            $this->postJson(route('projections.manual.store'), [...$this->payload($unit, $item), ...$invalid])
                ->assertUnprocessable()->assertJsonValidationErrors(array_key_first($invalid) === 'reason' && $invalid['reason'] === 'lainnya' ? 'notes' : array_key_first($invalid));
        }

        $this->assertDatabaseCount('manual_projections', 0);
        $this->postJson(route('projections.manual.store'), [...$this->payload($unit, $item), 'reason' => 'lainnya', 'notes' => 'Ban sobek di sisi dalam', 'target_date' => '2026-11-02'])->assertRedirect();
    }

    public function test_manual_entries_without_a_planning_row_create_the_task_planning_link(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $planning->delete();
        $this->actingAs(User::factory()->create(['role' => UserRole::SpvHo]))
            ->postJson(route('projections.manual.store'), $this->payload($unit, $item))->assertRedirect();

        $manual = ManualProjection::query()->sole();
        $this->assertNotNull($manual->unit_planning_id);
        $this->assertSame($manual->unit_planning_id, $manual->workOrderItem->unit_planning_id);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);
        $this->assertDatabaseCount('unit_plannings', 1);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_items', 1);
    }

    public function test_excluded_planning_cannot_be_added_manually_and_keeps_its_exclusion(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $planning->update(['is_excluded' => true, 'excluded_reason' => 'Tidak Berlaku', 'next_due_date' => '2026-11-03']);
        $before = $planning->fresh()->getAttributes();
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);

        $this->actingAs(User::factory()->create(['role' => UserRole::SpvHo]))
            ->postJson(route('projections.manual.store'), $this->payload($unit, $item))
            ->assertUnprocessable()->assertJsonValidationErrors('planning_item_id')
            ->assertJsonPath('errors.planning_item_id.0', 'Item ini dikecualikan untuk unit tersebut');
        $this->assertDatabaseCount('manual_projections', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertSame($before, $planning->fresh()->getAttributes());
    }

    #[DataProvider('mergeDates')]
    public function test_system_and_manual_are_merged_using_the_earliest_date(string $manualDate, string $expectedDate, string $expectedTrigger): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $planning->update(['next_due_date' => '2026-11-12']);
        $this->manual($unit, $item, ['target_date' => $manualDate]);
        $before = $planning->fresh()->getAttributes();
        $result = app(ProjectionService::class)->calculate(1);
        $this->assertCount(1, $result['by_unit']);
        $this->assertCount(1, $result['by_unit'][0]['items']);
        $line = $result['by_unit'][0]['items'][0];
        $this->assertSame($expectedDate, $line['estimated_due_date']);
        $this->assertSame($expectedTrigger, $line['trigger']);
        $this->assertTrue($line['has_manual']);
        $this->assertSame($expectedTrigger === 'manual' ? 'Rusak' : null, $line['manual_reason']);
        $this->assertSame(1, $result['by_item'][0]['total_estimated_quantity']);
        $this->assertSame($result['by_item'], $result['by_part']);
        $this->assertSame($before, $planning->fresh()->getAttributes());
    }

    /** @return array<string, array{string, string, string}> */
    public static function mergeDates(): array
    {
        return [
            'manual earlier' => ['2026-11-07', '2026-11-07', 'manual'],
            'system earlier' => ['2026-11-17', '2026-11-12', 'date'],
            'tie keeps system' => ['2026-11-12', '2026-11-12', 'date'],
        ];
    }

    public function test_manual_supplies_a_date_to_an_undated_system_km_projection(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $unit->update(['has_odometer_reading' => true]);
        $planning->update(['next_due_date' => null, 'next_due_km' => 1200]);
        $this->assertNull(app(ProjectionService::class)->calculate(1)['by_unit'][0]['items'][0]['estimated_due_date']);
        $this->manual($unit, $item);
        $line = app(ProjectionService::class)->calculate(1)['by_unit'][0]['items'][0];
        $this->assertSame('manual', $line['trigger']);
        $this->assertSame('2026-11-09', $line['estimated_due_date']);
    }

    public function test_manual_projection_obeys_period_end_region_and_site_filters(): void
    {
        [$first, $firstItem] = $this->scenario();
        [$second, $secondItem] = $this->scenario();
        [$outside, $outsideItem] = $this->scenario('Other Region');
        $this->manual($first, $firstItem, ['target_date' => '2026-12-02']);
        $this->manual($second, $secondItem);
        $this->manual($outside, $outsideItem);
        [$tooLate, $lateItem] = $this->scenario();
        $this->manual($tooLate, $lateItem, ['target_date' => '2026-12-03']);

        $service = app(ProjectionService::class);
        $this->assertCount(3, $service->calculate(1)['by_unit']);
        $this->assertCount(2, $service->calculate(1, regionId: $first->site->region_id)['by_unit']);
        $this->assertSame([$first->id], array_column($service->calculate(1, $first->site_id, $first->site->region_id)['by_unit'], 'unit_id'));
        $this->assertSame([], $service->calculate(1, $outside->site_id, $first->site->region_id)['by_unit']);
    }

    public function test_manual_tab_and_unit_options_are_scoped_and_include_active_dates_beyond_the_period(): void
    {
        [$own, $ownItem] = $this->scenario();
        [$other, $otherItem] = $this->scenario('Other Region');
        $this->manual($own, $ownItem, ['target_date' => '2027-01-01']);
        $this->manual($other, $otherItem);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $own->site->region_id]);

        $this->actingAs($planner)->get(route('projections.index', ['region_id' => $other->site->region_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('manual_projections.data', 1)->where('manual_projections.data.0.unit_id', $own->id)
            ->where('manual_projections.data.0.can_cancel', true)
            ->has('manual_units', 1)->where('manual_units.0.id', $own->id)
            ->where('permissions.can_create_manual', true)->has('projection.by_unit.data', 0));

        $this->get(route('projections.index', ['site_id' => $other->site_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('manual_projections.data', 0)->has('projection.by_unit.data', 0));
    }

    public function test_cancelling_keeps_audit_history_removes_manual_demand_and_allows_a_new_entry(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $manual = $this->manual($unit, $item);
        $before = $planning->fresh()->getAttributes();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $unit->site->region_id]);
        $this->actingAs($planner)->patch(route('projections.manual.cancel', $manual))->assertRedirect();
        $this->assertSame('cancelled', $manual->refresh()->status);
        $this->assertSame($planner->id, $manual->cancelled_by);
        $this->assertNotNull($manual->cancelled_at);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);
        $this->get(route('projections.index'))->assertInertia(fn (Assert $page) => $page->has('manual_projections.data', 0));
        $this->assertSame($before, $planning->fresh()->getAttributes());
        $this->postJson(route('projections.manual.store'), $this->payload($unit, $item))->assertRedirect();
        $this->assertSame(1, ManualProjection::query()->active()->count());
    }

    public function test_complete_fulfills_a_same_day_manual_entry_and_removes_it_from_projection(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $manual = $this->manual($unit, $item);
        $workItem = $manual->workOrderItem;
        $workOrder = $workItem->workOrder;
        $mechanic = $workOrder->assignedMechanic;
        $this->assertCount(1, app(ProjectionService::class)->calculate(1)['by_unit']);
        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $workItem]), [
            'completed_odo' => 5000, 'completed_date' => '2026-11-02',
        ])->assertRedirect(route('mechanic.tasks'))->assertSessionHasNoErrors();

        $this->assertSame('complete', $workItem->fresh()->status);
        $this->assertSame('fulfilled', $manual->refresh()->status);
        $this->assertNotNull($manual->fulfilled_at);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);
        $this->actingAs(User::factory()->create(['role' => UserRole::SpvHo]))->get(route('projections.index'))
            ->assertInertia(fn (Assert $page) => $page->has('manual_projections.data', 0));
        $this->post(route('projections.manual.store'), $this->payload($unit, $item))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_backdated_completion_before_manual_creation_does_not_fulfill_it(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $manual = $this->manual($unit, $item);
        $workItem = $manual->workOrderItem;
        $workOrder = $workItem->workOrder;
        $mechanic = $workOrder->assignedMechanic;
        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $workItem]), [
            'completed_odo' => 5000, 'completed_date' => '2026-11-01',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('complete', $workItem->fresh()->status);
        $this->assertSame('active', $manual->refresh()->status);
        $this->assertNull($manual->fulfilled_at);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);
    }

    public function test_completion_matches_a_previously_unlinked_manual_but_not_other_items_or_cancelled_entries(): void
    {
        [$unit, $item, $planning] = $this->scenario();
        $unlinked = $this->manual($unit, $item, ['unit_planning_id' => null, 'work_order_item_id' => null]);
        $cancelled = $this->manual($unit, $item, ['status' => 'cancelled', 'cancelled_at' => now()]);
        [$otherUnit, $otherItem] = $this->scenario();
        $other = $this->manual($otherUnit, $otherItem);
        [$mechanic, $workOrder, $workItem] = $this->workItem($unit, $item, $planning);
        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $workItem]), [
            'completed_odo' => 5000, 'completed_date' => '2026-11-02',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('fulfilled', $unlinked->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('active', $other->fresh()->status);
    }

    /** @return array{Unit, PlanningItem, UnitPlanning} */
    private function scenario(string $regionName = 'Own Region'): array
    {
        $region = Region::query()->firstOrCreate(['name' => $regionName]);
        $site = Site::query()->create(['name' => fake()->unique()->word(), 'region_id' => $region->id, 'region' => $region->name]);
        $unit = Unit::withoutEvents(fn (): Unit => Unit::query()->create([
            'site_id' => $site->id, 'customer' => 'Test', 'current_plate' => fake()->unique()->bothify('KT #### ??'),
            'brand' => 'Toyota', 'type' => 'TOYOTA HILUX DC', 'year' => 2024, 'current_odo' => 1300,
            'status' => 'active', 'has_odometer_reading' => false,
        ]));
        $item = PlanningItem::query()->create(['name' => fake()->unique()->word(), 'interval_km' => 5000, 'interval_days' => 365]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id, 'planning_item_id' => $item->id, 'last_done_km' => 1000,
            'last_done_date' => '2026-10-01', 'next_due_km' => 10000, 'next_due_date' => '2027-10-01',
        ]);

        return [$unit, $item, $planning];
    }

    /** @return array{unit_id: int, planning_item_id: int, target_date: string, reason: string, assigned_mechanic_id: int} */
    private function payload(Unit $unit, PlanningItem $item): array
    {
        $mechanic = User::query()->where('role', UserRole::Mekanik->value)->where('site_id', $unit->site_id)->first()
            ?? User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $unit->site_id]);

        return ['unit_id' => $unit->id, 'planning_item_id' => $item->id, 'target_date' => '2026-11-09', 'reason' => 'rusak', 'assigned_mechanic_id' => $mechanic->id];
    }

    /** @param array<string, mixed> $attributes */
    private function manual(Unit $unit, PlanningItem $item, array $attributes = []): ManualProjection
    {
        $task = null;
        $planning = UnitPlanning::query()->where('unit_id', $unit->id)->where('planning_item_id', $item->id)->first();

        if (! array_key_exists('work_order_item_id', $attributes) && $planning !== null) {
            [, , $task] = $this->workItem($unit, $item, $planning);
        }

        return ManualProjection::query()->create([
            ...$this->payload($unit, $item),
            'work_order_item_id' => $task?->id,
            'unit_planning_id' => UnitPlanning::query()->where('unit_id', $unit->id)->where('planning_item_id', $item->id)->value('id'),
            'created_by' => User::factory()->create(['role' => UserRole::SpvHo])->id,
            'status' => 'active', ...$attributes,
        ]);
    }

    /** @return array{User, WorkOrder, WorkOrderItem} */
    private function workItem(Unit $unit, PlanningItem $item, UnitPlanning $planning): array
    {
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $unit->site_id]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id, 'site_id' => $unit->site_id, 'status' => 'in_progress',
            'trigger_type' => 'manual', 'assigned_mechanic_id' => $mechanic->id,
        ]);
        $workItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id, 'unit_planning_id' => $planning->id,
            'planning_item_id' => $item->id, 'status' => 'in_progress', 'action' => 'replace', 'scheduled_date' => '2026-11-02', 'approved_at' => now(),
        ]);

        return [$mechanic, $workOrder, $workItem];
    }
}
