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

class FixMisclassifiedOverdueItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_misclassified_items_without_changing_data(): void
    {
        $site = Site::query()->create(['name' => 'Site Command', 'region' => 'Kalimantan']);
        $item = $this->createWorkOrderItem($site, 'KT 1043 AA', 'Filter Oli', 0, null);

        $this->artisan('fleet:fix-misclassified-overdue --dry-run')
            ->expectsTable(
                ['Plat Nomor', 'Item', 'Status Saat Ini'],
                [['KT 1043 AA', 'Filter Oli', 'overdue']],
            )
            ->expectsOutput('1 item akan diubah dari overdue menjadi on_hold. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $this->assertSame('overdue', $item->refresh()->status);
    }

    public function test_execute_updates_zero_km_baselines_regardless_of_date_and_is_idempotent(): void
    {
        $site = Site::query()->create(['name' => 'Site Execute', 'region' => 'Sulawesi']);
        $missingBaselineItem = $this->createWorkOrderItem($site, 'DD 2001 BB', 'Brake Pad', 0, null);
        $dateBaselineItem = $this->createWorkOrderItem($site, 'DD 2002 BB', 'Filter Udara', 0, today()->toDateString());
        $kmBaselineItem = $this->createWorkOrderItem($site, 'DD 2003 BB', 'Oli Gardan', 5000, null);

        $this->artisan('fleet:fix-misclassified-overdue --execute')
            ->expectsOutput('2 item berhasil diubah dari overdue menjadi on_hold.')
            ->assertSuccessful();

        $this->assertSame('on_hold', $missingBaselineItem->refresh()->status);
        $this->assertSame('on_hold', $dateBaselineItem->refresh()->status);
        $this->assertSame('overdue', $kmBaselineItem->refresh()->status);

        $this->artisan('fleet:fix-misclassified-overdue --execute')
            ->expectsOutput('Tidak ada work order item overdue dengan baseline item yang belum diisi.')
            ->assertSuccessful();
    }

    private function createWorkOrderItem(Site $site, string $plate, string $itemName, int $lastDoneKm, ?string $lastDoneDate): WorkOrderItem
    {
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 104321,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create([
            'name' => $itemName,
            'interval_km' => 10000,
            'interval_days' => 90,
        ]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => $lastDoneKm,
            'last_done_date' => $lastDoneDate,
            'next_due_km' => 10000,
            'next_due_date' => today()->subDay()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'overdue',
        ]);
    }
}
