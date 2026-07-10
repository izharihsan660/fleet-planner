<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillInitialMaintenanceTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_due_items_without_creating_work_orders(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 2000, 'interval_days' => 90]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 1200,
            'next_due_date' => null,
            'is_estimated' => true,
        ]);

        $this->artisan('maintenance:backfill-initial-tasks')
            ->expectsOutputToContain('Dry-run only')
            ->expectsOutputToContain('WorkOrders')
            ->assertSuccessful();

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, WorkOrderItem::query()->count());
    }

    public function test_execute_creates_on_hold_and_overdue_items_with_null_due_dates_supported(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $onHoldPlanningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 2000, 'interval_days' => 90]);
        $overduePlanningItem = PlanningItem::query()->create(['name' => 'Filter Oli', 'interval_km' => 2000, 'interval_days' => 90]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $onHoldPlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 1200,
            'next_due_date' => null,
            'is_estimated' => true,
        ]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $overduePlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(100)->toDateString(),
            'next_due_km' => 900,
            'next_due_date' => now()->subDay()->toDateString(),
            'is_estimated' => false,
        ]);

        $this->artisan('maintenance:backfill-initial-tasks --execute')
            ->expectsOutputToContain('Executing initial maintenance backfill')
            ->assertSuccessful();

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->where('status', 'on_hold')->count());
        $this->assertSame(1, WorkOrderItem::query()->where('status', 'overdue')->count());
    }

    public function test_execute_creates_item_when_only_existing_item_is_rejected(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 2000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 1200,
            'next_due_date' => null,
            'is_estimated' => true,
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'in_progress']);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'rejected',
        ]);

        $this->artisan('maintenance:backfill-initial-tasks --execute')
            ->expectsOutputToContain('Executing initial maintenance backfill')
            ->assertSuccessful();

        $this->assertSame(2, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->count());
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->where('status', 'on_hold')->count());
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
