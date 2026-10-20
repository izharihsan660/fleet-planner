<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            'last_done_km' => 100,
            'last_done_date' => today()->toDateString(),
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
            'last_done_km' => 100,
            'last_done_date' => today()->toDateString(),
            'next_due_km' => 1200,
            'next_due_date' => null,
            'is_estimated' => true,
        ]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $overduePlanningItem->id,
            'last_done_km' => 100,
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

    public function test_execute_skips_planning_when_existing_item_is_rejected(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 2000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 100,
            'last_done_date' => today()->toDateString(),
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
            'action' => 'replace',
        ]);

        $this->artisan('maintenance:backfill-initial-tasks --execute')
            ->expectsOutputToContain('Executing initial maintenance backfill')
            ->assertSuccessful();

        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->count());
        $this->assertSame(0, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->where('status', 'on_hold')->count());
    }

    public function test_execute_creates_date_overdue_task_with_zero_baseline_km(): void
    {
        $site = Site::query()->create(['name' => 'Site Null Baseline', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Baseline Kosong', 'interval_km' => 2000, 'interval_days' => 90]);
        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => 1200,
            'next_due_date' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('maintenance:backfill-initial-tasks --execute')->assertSuccessful();

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->where('status', 'overdue')->count());
    }

    public function test_dry_run_is_safe_and_quiet_execute_reconciles_items_without_duplicate_sweep_notifications(): void
    {
        $region = Region::query()->create(['name' => 'Region A']);
        $site = Site::query()->create(['name' => 'Site A', 'region' => 'Region A', 'region_id' => $region->id]);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $otherRegion = Region::query()->create(['name' => 'Other Region']);
        $outsidePlanner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $otherRegion->id]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'status' => 'open', 'trigger_type' => 'normal']);

        $newOverdue = $this->datePlanning($unit, 'New Overdue', today()->subDay()->toDateString());
        $newWarning = $this->datePlanning($unit, 'New Warning', today()->addDays(3)->toDateString());
        $oldOnHold = $this->datePlanning($unit, 'Old On Hold', today()->subDay()->toDateString());
        $oldOverdue = $this->datePlanning($unit, 'Old Overdue', today()->addDays(30)->toDateString());
        $onHoldItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id,
            'unit_planning_id' => $oldOnHold->id, 'planning_item_id' => $oldOnHold->planning_item_id, 'status' => 'on_hold']);
        $overdueItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id,
            'unit_planning_id' => $oldOverdue->id, 'planning_item_id' => $oldOverdue->planning_item_id, 'status' => 'overdue']);

        $before = [WorkOrderItem::query()->count(), Notification::query()->count()];
        $this->assertSame(0, Artisan::call('maintenance:backfill-initial-tasks', ['--dry-run' => true, '--quiet-notifications' => true]));
        $this->assertSame($before, [WorkOrderItem::query()->count(), Notification::query()->count()]);
        $this->assertSame('on_hold', $onHoldItem->refresh()->status);
        $this->assertSame('overdue', $overdueItem->refresh()->status);
        $dryRunReport = $this->reportFromOutput(Artisan::output());
        $this->assertStringContainsString('on_hold->overdue', file_get_contents($dryRunReport));

        $this->assertSame(0, Artisan::call('maintenance:backfill-initial-tasks', ['--execute' => true, '--quiet-notifications' => true]));
        $executeReport = $this->reportFromOutput(Artisan::output());
        $this->assertStringContainsString('task baru masuk warning', file_get_contents($executeReport));
        $this->assertSame('overdue', WorkOrderItem::query()->where('unit_planning_id', $newOverdue->id)->value('status'));
        $this->assertSame('on_hold', WorkOrderItem::query()->where('unit_planning_id', $newWarning->id)->value('status'));
        $this->assertSame('overdue', $onHoldItem->refresh()->status);
        $this->assertSame('on_hold', $overdueItem->refresh()->status);
        $this->assertSame(1, Notification::query()->where('user_id', $planner->id)->count());
        $this->assertSame(1, Notification::query()->where('user_id', $spv->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $outsidePlanner->id)->count());
        $this->assertSame('Backfill: 2 task baru dibuat, 1 task jadi overdue.', Notification::query()->firstOrFail()->message);
        $this->assertSame(0, Notification::query()->whereIn('type', ['task_auto_generated', 'maintenance_overdue'])->count());

        $this->artisan('maintenance:check-overdue')->assertSuccessful();
        $this->assertSame(2, Notification::query()->count());

        unlink($dryRunReport);
        unlink($executeReport);
    }

    public function test_backfill_restores_a_scheduled_stale_overdue_item_to_in_progress(): void
    {
        $site = Site::query()->create(['name' => 'Site A', 'region' => 'Region A']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planning = $this->datePlanning($unit, 'Scheduled', today()->addDays(30)->toDateString());
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id,
            'status' => 'in_progress', 'trigger_type' => 'normal', 'assigned_mechanic_id' => $mechanic->id]);
        $item = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id, 'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue', 'scheduled_date' => today()->toDateString()]);

        $this->assertSame(0, Artisan::call('maintenance:backfill-initial-tasks', ['--execute' => true, '--quiet-notifications' => true]));
        $report = $this->reportFromOutput(Artisan::output());
        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertStringContainsString('overdue->in_progress', file_get_contents($report));
        unlink($report);
    }

    private function datePlanning(Unit $unit, string $name, string $dueDate): UnitPlanning
    {
        $item = PlanningItem::query()->create(['name' => $name, 'interval_km' => 1000, 'interval_days' => 90]);

        return UnitPlanning::query()->create(['unit_id' => $unit->id, 'planning_item_id' => $item->id,
            'last_done_km' => 0, 'last_done_date' => null, 'next_due_km' => null,
            'next_due_date' => $dueDate, 'due_manually_set' => true]);
    }

    private function reportFromOutput(string $output): string
    {
        $this->assertSame(1, preg_match('/CSV: (.+\.csv)/', $output, $matches), $output);

        return $matches[1];
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
