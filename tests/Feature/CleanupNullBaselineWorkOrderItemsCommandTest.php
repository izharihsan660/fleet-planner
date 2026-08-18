<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CleanupNullBaselineWorkOrderItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_matches_without_changing_data(): void
    {
        $scenario = $this->createScenario();

        $exitCode = Artisan::call('wo:cleanup-null-baseline-items', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MODE: DRY-RUN', $output);
        $this->assertStringContainsString('2 item AKAN DIHAPUS', $output);
        $this->assertStringContainsString('1 item DILEWATI', $output);
        $this->assertStringContainsString('DD 1153 EKX', $output);
        $this->assertStringContainsString('Akan Dihapus', $output);
        $this->assertStringContainsString('Dilewati Manual', $output);
        $this->assertStringContainsString('on_hold', $output);
        $this->assertStringContainsString('postponed', $output);
        $this->assertStringContainsString('Total item ditemukan (KM baseline NULL/0): 3', $output);
        $this->assertStringContainsString('Akan dihapus (status on_hold): 2', $output);
        $this->assertStringContainsString('Dilewati untuk review manual (status lain): 1', $output);

        $this->assertModelExists($scenario['onHoldOnlyParent']);
        $this->assertModelExists($scenario['mixedParent']);
        $this->assertModelExists($scenario['onHoldOnlyItem']);
        $this->assertModelExists($scenario['mixedOnHoldItem']);
        $this->assertModelExists($scenario['mixedPostponedItem']);
    }

    public function test_execute_deletes_only_on_hold_items_and_preserves_skipped_items_and_parent(): void
    {
        $scenario = $this->createScenario();

        $exitCode = Artisan::call('wo:cleanup-null-baseline-items', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MODE: EXECUTE', $output);
        $this->assertStringContainsString('Item dihapus: 2', $output);
        $this->assertStringContainsString('WO parent kosong dihapus: 1', $output);
        $this->assertStringContainsString('Dilewati untuk review manual (status lain): 1', $output);

        $this->assertModelMissing($scenario['onHoldOnlyItem']);
        $this->assertModelMissing($scenario['mixedOnHoldItem']);
        $this->assertModelMissing($scenario['onHoldOnlyParent']);
        $this->assertModelExists($scenario['mixedParent']);
        $this->assertModelExists($scenario['mixedPostponedItem']);
        $this->assertSame('postponed', $scenario['mixedPostponedItem']->refresh()->status);
        $this->assertSame('complete', $scenario['mixedParent']->refresh()->status);
    }

    /**
     * @return array<string, WorkOrder|WorkOrderItem>
     */
    private function createScenario(): array
    {
        $site = Site::query()->create(['name' => 'Site Cleanup', 'region' => 'Sulawesi']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT Cleanup',
            'current_plate' => 'DD 1153 EKX',
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 109309,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]));
        $deletablePlanningItem = PlanningItem::query()->create(['name' => 'Akan Dihapus', 'interval_km' => 5000, 'interval_days' => 90]);
        $skippedPlanningItem = PlanningItem::query()->create(['name' => 'Dilewati Manual', 'interval_km' => 5000, 'interval_days' => 90]);
        $deletablePlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $deletablePlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => today()->subDays(83)->toDateString(),
        ]);
        $skippedPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $skippedPlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subMonths(2)->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => today()->subDays(83)->toDateString(),
        ]);

        $onHoldOnlyParent = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $onHoldOnlyItem = WorkOrderItem::query()->create([
            'work_order_id' => $onHoldOnlyParent->id,
            'unit_planning_id' => $deletablePlanning->id,
            'planning_item_id' => $deletablePlanningItem->id,
            'status' => 'on_hold',
        ]);

        $mixedParent = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'in_progress']);
        $mixedOnHoldItem = WorkOrderItem::query()->create([
            'work_order_id' => $mixedParent->id,
            'unit_planning_id' => $deletablePlanning->id,
            'planning_item_id' => $deletablePlanningItem->id,
            'status' => 'on_hold',
        ]);
        $mixedPostponedItem = WorkOrderItem::query()->create([
            'work_order_id' => $mixedParent->id,
            'unit_planning_id' => $skippedPlanning->id,
            'planning_item_id' => $skippedPlanningItem->id,
            'status' => 'postponed',
        ]);

        return compact('onHoldOnlyParent', 'mixedParent', 'onHoldOnlyItem', 'mixedOnHoldItem', 'mixedPostponedItem');
    }
}
