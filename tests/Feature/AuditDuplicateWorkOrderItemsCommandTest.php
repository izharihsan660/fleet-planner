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

class AuditDuplicateWorkOrderItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_dry_run_reports_duplicate_groups_without_changing_data(): void
    {
        $scenario = $this->createScenario();

        $exitCode = Artisan::call('wo:audit-duplicate-items');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MODE: DRY-RUN', $output);
        $this->assertStringContainsString('Tidak ada data yang diubah atau dihapus.', $output);
        $this->assertStringContainsString((string) $scenario['activeDuplicateWorkOrder']->id, $output);
        $this->assertStringContainsString((string) $scenario['mixedDuplicateWorkOrder']->id, $output);
        $this->assertStringContainsString('KT 8479 YZ', $output);
        $this->assertStringContainsString('PM Check', $output);
        $this->assertStringContainsString('Service A', $output);
        $this->assertStringContainsString('overdue', $output);
        $this->assertStringContainsString('cancelled', $output);
        $this->assertStringContainsString('125000', $output);
        $this->assertStringContainsString('Total grup duplikat: 2', $output);
        $this->assertStringContainsString('Total baris dalam grup duplikat: 4', $output);

        $this->assertSame(6, WorkOrderItem::query()->count());
        $this->assertSame('open', $scenario['mixedDuplicateWorkOrder']->refresh()->status);
    }

    public function test_execute_mode_remains_audit_only(): void
    {
        $this->createScenario();

        $exitCode = Artisan::call('wo:audit-duplicate-items', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MODE: EXECUTE (AUDIT ONLY)', $output);
        $this->assertStringContainsString('Total grup duplikat: 2', $output);
        $this->assertSame(6, WorkOrderItem::query()->count());
    }

    public function test_command_rejects_conflicting_modes(): void
    {
        $exitCode = Artisan::call('wo:audit-duplicate-items', [
            '--dry-run' => true,
            '--execute' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Gunakan salah satu opsi saja', Artisan::output());
    }

    /**
     * @return array{activeDuplicateWorkOrder: WorkOrder, mixedDuplicateWorkOrder: WorkOrder}
     */
    private function createScenario(): array
    {
        $site = Site::query()->create(['name' => 'Site Audit Duplicate', 'region' => 'Kalimantan']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT Audit',
            'current_plate' => 'KT 8479 YZ',
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 120000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]));
        $pmCheck = PlanningItem::query()->create(['name' => 'PM Check', 'interval_km' => 5000, 'interval_days' => 30]);
        $serviceA = PlanningItem::query()->create(['name' => 'Service A', 'interval_km' => 10000, 'interval_days' => 90]);
        $cleanItem = PlanningItem::query()->create(['name' => 'Brake Pad', 'interval_km' => 20000, 'interval_days' => 180]);
        $pmPlanning = $this->planning($unit, $pmCheck, 125000);
        $servicePlanning = $this->planning($unit, $serviceA, 130000);
        $cleanPlanning = $this->planning($unit, $cleanItem, 140000);

        $activeDuplicateWorkOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $this->item($activeDuplicateWorkOrder, $pmPlanning, 'on_hold');
        $this->item($activeDuplicateWorkOrder, $pmPlanning, 'overdue');

        $mixedDuplicateWorkOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $this->item($mixedDuplicateWorkOrder, $servicePlanning, 'cancelled');
        $this->item($mixedDuplicateWorkOrder, $servicePlanning, 'on_hold');

        $cleanWorkOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $this->item($cleanWorkOrder, $cleanPlanning, 'cancelled');
        $this->item($cleanWorkOrder, $cleanPlanning, 'cancelled');

        return compact('activeDuplicateWorkOrder', 'mixedDuplicateWorkOrder');
    }

    private function planning(Unit $unit, PlanningItem $planningItem, int $nextDueKm): UnitPlanning
    {
        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => $nextDueKm - $planningItem->interval_km,
            'last_done_date' => today()->subDays(30)->toDateString(),
            'next_due_km' => $nextDueKm,
            'next_due_date' => today()->addDays(30)->toDateString(),
        ]);
    }

    private function item(WorkOrder $workOrder, UnitPlanning $planning, string $status): WorkOrderItem
    {
        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => $status,
        ]);
    }
}
