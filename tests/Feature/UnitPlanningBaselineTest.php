<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitPlanningBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_all_unit_access_roles_can_set_a_missing_baseline(): void
    {
        $site = Site::query()->create(['name' => 'Site Baseline', 'region' => 'Sulawesi']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));

        foreach ([UserRole::Mekanik, UserRole::PlannerArea, UserRole::SpvHo, UserRole::Superadmin] as $index => $role) {
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => in_array($role, [UserRole::Mekanik, UserRole::PlannerArea], true) ? $site->id : null,
            ]);
            $planningItem = PlanningItem::query()->create([
                'name' => 'Baseline '.$role->value,
                'interval_km' => 100000,
                'interval_days' => 365,
            ]);
            $unitPlanning = UnitPlanning::query()->create([
                'unit_id' => $unit->id,
                'planning_item_id' => $planningItem->id,
                'last_done_km' => 0,
                'last_done_date' => null,
                'next_due_km' => 100000,
                'next_due_date' => today()->subDay()->toDateString(),
            ]);

            $this->actingAs($user)
                ->get(route('units.history', $unit))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('history.can_set_baseline', true)
                    ->where("history.planning_items.{$index}.last_done_date", null));

            $lastDoneKm = 1000 + $index;

            $this->actingAs($user)
                ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                    'last_done_km' => $lastDoneKm,
                    'last_done_date' => today()->toDateString(),
                ])
                ->assertRedirect()
                ->assertSessionHas('status');

            $unitPlanning->refresh();

            $this->assertSame($lastDoneKm, $unitPlanning->last_done_km);
            $this->assertSame(today()->toDateString(), $unitPlanning->last_done_date?->toDateString());
            $this->assertSame($lastDoneKm + 100000, $unitPlanning->next_due_km);
            $this->assertSame(today()->addDays(365)->toDateString(), $unitPlanning->next_due_date?->toDateString());
            $this->assertFalse($unitPlanning->is_estimated);
        }
    }

    public function test_setting_baseline_immediately_reenables_normal_triggering(): void
    {
        $site = Site::query()->create(['name' => 'Site Trigger', 'region' => 'Sulawesi']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 500, 'interval_days' => 30]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 500,
            'next_due_date' => today()->subDay()->toDateString(),
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_km' => 1000,
                'last_done_date' => today()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'unit_id' => $unit->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $unitPlanning->id,
            'status' => 'on_hold',
        ]);
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->count());
    }

    public function test_editing_baseline_updates_existing_active_item_in_place(): void
    {
        $site = Site::query()->create(['name' => 'Site Baseline Update', 'region' => 'Kalimantan']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));
        $planningItem = PlanningItem::query()->create(['name' => 'Service A', 'interval_km' => 500, 'interval_days' => 30]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 400,
            'last_done_date' => today()->subDays(30)->toDateString(),
            'next_due_km' => 900,
            'next_due_date' => today()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'overdue',
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_km' => 600,
                'last_done_date' => today()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->count());
        $this->assertSame($workOrder->id, $item->refresh()->work_order_id);
        $this->assertSame('overdue', $item->status);
        $this->assertSame(900, $item->previous_due_km);
        $this->assertSame(today()->toDateString(), $item->previous_due_date?->toDateString());
        $this->assertSame(1100, $item->new_due_km);
        $this->assertSame(today()->addDays(30)->toDateString(), $item->new_due_date?->toDateString());
        $this->assertSame(600, $item->baseline_last_done_km);
        $this->assertSame(today()->toDateString(), $item->baseline_last_done_date?->toDateString());
        $this->assertSame(1100, $unitPlanning->refresh()->next_due_km);
    }

    public function test_cancelled_item_replacement_uses_new_work_order_and_closes_cancelled_only_parent(): void
    {
        $site = Site::query()->create(['name' => 'Site Fresh Replacement', 'region' => 'Kalimantan']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));
        $planningItem = PlanningItem::query()->create(['name' => 'PM Check', 'interval_km' => 500, 'interval_days' => 30]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 400,
            'last_done_date' => today()->subDays(30)->toDateString(),
            'next_due_km' => 900,
            'next_due_date' => today()->toDateString(),
        ]);
        $oldWorkOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $oldWorkOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'cancelled',
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_km' => 600,
                'last_done_date' => today()->toDateString(),
            ])
            ->assertRedirect();

        $replacementItem = WorkOrderItem::query()
            ->where('unit_planning_id', $unitPlanning->id)
            ->where('status', 'on_hold')
            ->sole();

        $this->assertNotSame($oldWorkOrder->id, $replacementItem->work_order_id);
        $this->assertSame('cancelled', $oldWorkOrder->refresh()->status);
        $this->assertSame('open', $replacementItem->workOrder->status);
        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_cancelled_item_replacement_does_not_reuse_mixed_open_work_order(): void
    {
        $site = Site::query()->create(['name' => 'Site Mixed Replacement', 'region' => 'Kalimantan']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));
        $targetItem = PlanningItem::query()->create(['name' => 'Service B', 'interval_km' => 500, 'interval_days' => 30]);
        $otherItem = PlanningItem::query()->create(['name' => 'Brake Pad', 'interval_km' => 5000, 'interval_days' => 180]);
        $targetPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $targetItem->id,
            'last_done_km' => 400,
            'last_done_date' => today()->subDays(30)->toDateString(),
            'next_due_km' => 900,
            'next_due_date' => today()->toDateString(),
        ]);
        $otherPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $otherItem->id,
            'last_done_km' => 1000,
            'last_done_date' => today()->toDateString(),
            'next_due_km' => 6000,
            'next_due_date' => today()->addDays(180)->toDateString(),
        ]);
        $oldWorkOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $oldWorkOrder->id,
            'unit_planning_id' => $targetPlanning->id,
            'planning_item_id' => $targetItem->id,
            'status' => 'cancelled',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $oldWorkOrder->id,
            'unit_planning_id' => $otherPlanning->id,
            'planning_item_id' => $otherItem->id,
            'status' => 'on_hold',
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $targetPlanning]), [
                'last_done_km' => 600,
                'last_done_date' => today()->toDateString(),
            ])
            ->assertRedirect();

        $replacementItem = WorkOrderItem::query()
            ->where('unit_planning_id', $targetPlanning->id)
            ->where('status', 'on_hold')
            ->sole();

        $this->assertNotSame($oldWorkOrder->id, $replacementItem->work_order_id);
        $this->assertSame('open', $oldWorkOrder->refresh()->status);
        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_work_order_detail_hides_cancelled_row_when_active_replacement_exists(): void
    {
        $site = Site::query()->create(['name' => 'Site Detail Mitigation', 'region' => 'Kalimantan']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));
        $duplicateItem = PlanningItem::query()->create(['name' => 'Accu', 'interval_km' => 5000, 'interval_days' => 90]);
        $historicalItem = PlanningItem::query()->create(['name' => 'Wiper Blade', 'interval_km' => 10000, 'interval_days' => 180]);
        $duplicatePlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $duplicateItem->id,
            'last_done_km' => 1000,
            'last_done_date' => today()->toDateString(),
            'next_due_km' => 6000,
            'next_due_date' => today()->addDays(90)->toDateString(),
        ]);
        $historicalPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $historicalItem->id,
            'last_done_km' => 1000,
            'last_done_date' => today()->toDateString(),
            'next_due_km' => 11000,
            'next_due_date' => today()->addDays(180)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $cancelledDuplicate = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $duplicatePlanning->id,
            'planning_item_id' => $duplicateItem->id,
            'status' => 'cancelled',
        ]);
        $activeReplacement = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $duplicatePlanning->id,
            'planning_item_id' => $duplicateItem->id,
            'status' => 'on_hold',
        ]);
        $standaloneCancelled = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $historicalPlanning->id,
            'planning_item_id' => $historicalItem->id,
            'status' => 'cancelled',
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $response = $this->actingAs($planner)
            ->get(route('work-orders.show', $workOrder))
            ->assertOk();
        $visibleItemIds = collect($response->inertiaProps('workOrder.data.items'))->pluck('id');

        $this->assertFalse($visibleItemIds->contains($cancelledDuplicate->id));
        $this->assertTrue($visibleItemIds->contains($activeReplacement->id));
        $this->assertTrue($visibleItemIds->contains($standaloneCancelled->id));
        $this->assertCount(2, $visibleItemIds);
    }

    public function test_mode_a_calculates_due_from_last_done_date_and_allows_km_to_be_empty(): void
    {
        [$unit, $unitPlanning, $planner] = $this->makeBaselineScenario();
        $lastDoneDate = today()->subDays(10);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_date' => $lastDoneDate->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $unitPlanning->refresh();

        $this->assertSame(0, $unitPlanning->last_done_km);
        $this->assertSame($lastDoneDate->toDateString(), $unitPlanning->last_done_date?->toDateString());
        $this->assertNull($unitPlanning->next_due_km);
        $this->assertSame($lastDoneDate->addDays(100)->toDateString(), $unitPlanning->next_due_date?->toDateString());
        $this->assertFalse($unitPlanning->is_estimated);
        $this->assertFalse($unitPlanning->due_manually_set);
    }

    public function test_mode_b_reverse_calculates_an_estimated_baseline_from_due_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        [$unit, $unitPlanning, $planner] = $this->makeBaselineScenario(currentOdometer: 50000);
        $dueDate = today()->addDays(40);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'next_due_date' => $dueDate->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $unitPlanning->refresh();

        $this->assertSame(44000, $unitPlanning->last_done_km);
        $this->assertSame(today()->subDays(60)->toDateString(), $unitPlanning->last_done_date?->toDateString());
        $this->assertNull($unitPlanning->next_due_km);
        $this->assertSame($dueDate->toDateString(), $unitPlanning->next_due_date?->toDateString());
        $this->assertTrue($unitPlanning->is_estimated);
        $this->assertFalse($unitPlanning->due_manually_set);

        $this->actingAs($planner)
            ->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.planning_items.0.is_estimated', true)
                ->where('history.planning_items.0.due_manually_set', false));
    }

    public function test_mode_b_uses_one_as_the_km_sentinel_when_current_odometer_is_unavailable(): void
    {
        [$unit, $unitPlanning, $planner] = $this->makeBaselineScenario(
            currentOdometer: 0,
            hasOdometerReading: false,
        );
        $dueDate = today()->addDays(20);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'next_due_date' => $dueDate->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $unitPlanning->refresh();

        $this->assertSame(1, $unitPlanning->last_done_km);
        $this->assertNull($unitPlanning->next_due_km);
        $this->assertTrue($unitPlanning->is_estimated);
    }

    public function test_mode_c_keeps_both_input_dates_and_marks_due_as_manually_set(): void
    {
        [$unit, $unitPlanning, $planner] = $this->makeBaselineScenario();
        $lastDoneDate = today()->subDays(10);
        $dueDate = today()->addDays(45);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_km' => 25000,
                'last_done_date' => $lastDoneDate->toDateString(),
                'next_due_date' => $dueDate->toDateString(),
                'unit_status_note' => 'Standby',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $unitPlanning->refresh();

        $this->assertSame(25000, $unitPlanning->last_done_km);
        $this->assertSame($lastDoneDate->toDateString(), $unitPlanning->last_done_date?->toDateString());
        $this->assertSame(35000, $unitPlanning->next_due_km);
        $this->assertSame($dueDate->toDateString(), $unitPlanning->next_due_date?->toDateString());
        $this->assertFalse($unitPlanning->is_estimated);
        $this->assertTrue($unitPlanning->due_manually_set);

        $this->actingAs($planner)
            ->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.planning_items.0.is_estimated', false)
                ->where('history.planning_items.0.due_manually_set', true));
    }

    public function test_setup_awal_frontend_contains_expected_fields_hints_and_badges(): void
    {
        $formSource = file_get_contents(resource_path('js/Components/UnitPlanningBaselineForm.tsx'));
        $historySource = file_get_contents(resource_path('js/Pages/Units/History.tsx'));

        $this->assertStringContainsString('Kapan Terakhir Diganti (Tanggal)', $formSource);
        $this->assertStringContainsString('KM Saat Diganti (opsional)', $formSource);
        $this->assertStringContainsString('Due Date', $formSource);
        $this->assertStringContainsString('Due dihitung otomatis dari interval', $formSource);
        $this->assertStringContainsString('Tanggal terakhir diganti akan diestimasi sistem', $formSource);
        $this->assertStringContainsString('Due Date manual akan dipakai (tidak dihitung dari interval)', $formSource);
        $this->assertStringContainsString('>Estimasi</StatusBadge>', $historySource);
        $this->assertStringContainsString('>Due Manual</StatusBadge>', $historySource);
    }

    public function test_baseline_rejects_when_last_done_date_and_due_date_are_both_empty(): void
    {
        [$unit, $unitPlanning, $planner] = $this->makeBaselineScenario();

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_km' => 25000,
            ])
            ->assertSessionHasErrors([
                'last_done_date' => 'Isi Tanggal Terakhir atau Due Date.',
                'next_due_date' => 'Isi Tanggal Terakhir atau Due Date.',
            ]);

        $this->assertNull($unitPlanning->refresh()->last_done_date);
    }

    public function test_baseline_rejects_a_due_date_in_the_past(): void
    {
        [$unit, $unitPlanning, $planner] = $this->makeBaselineScenario();

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'next_due_date' => today()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors([
                'next_due_date' => 'Due Date tidak boleh di masa lalu.',
            ]);

        $this->assertNull($unitPlanning->refresh()->last_done_date);
    }

    /**
     * @return array{Unit, UnitPlanning, User}
     */
    private function makeBaselineScenario(
        int $currentOdometer = 50000,
        bool $hasOdometerReading = true,
    ): array {
        $site = Site::query()->create(['name' => 'Site Mode Baseline', 'region' => 'Sulawesi']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create([
            ...$this->unitPayload($site->id),
            'current_odo' => $currentOdometer,
            'has_odometer_reading' => $hasOdometerReading,
        ]));
        $planningItem = PlanningItem::query()->create([
            'name' => 'Item Mode Baseline',
            'interval_km' => 10000,
            'interval_days' => 100,
        ]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
        ]);
        $planner = User::factory()->create([
            'role' => UserRole::PlannerArea,
            'site_id' => $site->id,
        ]);

        return [$unit, $unitPlanning, $planner];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'PT Baseline',
            'current_plate' => 'DD 1153 EKX',
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 1000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ];
    }
}
