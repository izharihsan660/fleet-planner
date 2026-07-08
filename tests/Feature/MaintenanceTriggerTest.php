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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaintenanceTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_km_input_creates_normal_work_order_item_when_threshold_is_reached(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 2000, 'interval_days' => 90]);
        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => 2000,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1500,
        ])->assertRedirect(route('inspections.index'));

        $workOrder = WorkOrder::query()->firstOrFail();

        $this->assertSame($unit->id, $workOrder->unit_id);
        $this->assertSame($site->id, $workOrder->site_id);
        $this->assertSame('normal', $workOrder->trigger_type);
        $this->assertSame('open', $workOrder->status);
        $this->assertSame(1, WorkOrderItem::query()->where('work_order_id', $workOrder->id)->count());
        $this->assertSame('on_hold', WorkOrderItem::query()->value('status'));

        $this->actingAs($mechanic)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/Index')
                ->where('boardColumns.open.data.0.id', $workOrder->id)
                ->where('boardColumns.open.data.0.status', 'open')
                ->where('boardColumns.open.data.0.unit.current_plate', $unit->current_plate)
            );
    }

    public function test_trigger_reuses_open_work_order_and_does_not_duplicate_active_item(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $firstPlanning = $this->createPlanning($unit, 'Ganti Oli', 2000);
        $secondPlanning = $this->createPlanning($unit, 'Filter Solar', 1900);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 1500,
        ])->assertRedirect(route('inspections.index'));

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1600,
        ])->assertRedirect(route('inspections.index'));

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(2, WorkOrderItem::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $firstPlanning->id)->count());
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $secondPlanning->id)->count());
    }

    public function test_spv_ho_can_approve_work_order_and_complete_item_updates_planning(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 3000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(120)->toDateString(),
            'next_due_km' => 3000,
            'next_due_date' => now()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'on_hold',
        ]);
        $spvOps = User::factory()->create(['role' => UserRole::SpvHo, 'site_id' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($spvOps)->post(route('work-orders.approve', $workOrder))->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $item->refresh()->status);

        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $item]), [
            'completed_odo' => 3200,
            'completed_date' => now()->toDateString(),
        ])->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('complete', $item->refresh()->status);
        $this->assertSame('complete', $workOrder->refresh()->status);
        $this->assertSame(3200, $unitPlanning->refresh()->last_done_km);
        $this->assertSame(8200, $unitPlanning->next_due_km);
        $this->assertSame(now()->addDays(90)->toDateString(), $unitPlanning->next_due_date->toDateString());
    }

    private function seedThresholds(): void
    {
        SystemThreshold::query()->create(['key' => 'warning_km', 'value' => '500', 'description' => 'Warning KM']);
        SystemThreshold::query()->create(['key' => 'warning_days', 'value' => '7', 'description' => 'Warning days']);
        SystemThreshold::query()->create(['key' => 'min_inspection_data', 'value' => '1', 'description' => 'Minimum data']);
    }

    private function createPlanning(Unit $unit, string $name, int $nextDueKm): UnitPlanning
    {
        $planningItem = PlanningItem::query()->create(['name' => $name, 'interval_km' => $nextDueKm, 'interval_days' => 90]);

        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => $nextDueKm,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId, int $currentOdo): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'Customer A',
            'current_plate' => 'KT '.fake()->unique()->numberBetween(1000, 9999).' AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ];
    }
}
