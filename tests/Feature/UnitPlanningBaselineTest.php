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
