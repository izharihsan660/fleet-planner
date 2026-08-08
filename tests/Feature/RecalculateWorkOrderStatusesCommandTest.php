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
use Tests\TestCase;

class RecalculateWorkOrderStatusesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_status_changes_without_mutating_data(): void
    {
        $scenario = $this->createScenario();

        $this->artisan('fleet:recalculate-wo-status --dry-run')
            ->expectsTable(
                ['WO ID', 'Plat', 'Status Saat Ini', 'Status Baru', 'Alasan / Komposisi Item'],
                [
                    [
                        $scenario['stuckWorkOrder']->id,
                        'DD 1001 AA',
                        'in_progress',
                        'open',
                        'Tidak ada item in_progress; WO kembali ke kolom On Hold. Komposisi: on_hold: 1',
                    ],
                    [
                        $scenario['activeWorkOrder']->id,
                        'DD 1002 AA',
                        'open',
                        'in_progress',
                        'Ada 1 item berstatus in_progress. Komposisi: in_progress: 1',
                    ],
                    [
                        $scenario['resolvedWorkOrder']->id,
                        'DD 1003 AA',
                        'open',
                        'complete',
                        'Semua item sudah final. Komposisi: complete: 1, postponed: 1',
                    ],
                ],
            )
            ->expectsOutput('3 work order akan diubah. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $this->assertSame('in_progress', $scenario['stuckWorkOrder']->refresh()->status);
        $this->assertSame('open', $scenario['activeWorkOrder']->refresh()->status);
        $this->assertSame('open', $scenario['resolvedWorkOrder']->refresh()->status);
        $this->assertSame('cancelled', $scenario['rejectedPreviewWorkOrder']->refresh()->status);
    }

    public function test_execute_updates_statuses_and_is_idempotent(): void
    {
        $scenario = $this->createScenario();

        $this->artisan('fleet:recalculate-wo-status --execute')
            ->expectsOutput('3 work order berhasil dihitung ulang.')
            ->assertSuccessful();

        $this->assertSame('open', $scenario['stuckWorkOrder']->refresh()->status);
        $this->assertSame('in_progress', $scenario['activeWorkOrder']->refresh()->status);
        $this->assertSame('complete', $scenario['resolvedWorkOrder']->refresh()->status);
        $this->assertSame('cancelled', $scenario['rejectedPreviewWorkOrder']->refresh()->status);

        $this->artisan('fleet:recalculate-wo-status --execute')
            ->expectsOutput('Tidak ada status work order yang perlu diubah.')
            ->assertSuccessful();
    }

    /**
     * @return array{stuckWorkOrder: WorkOrder, activeWorkOrder: WorkOrder, resolvedWorkOrder: WorkOrder, rejectedPreviewWorkOrder: WorkOrder}
     */
    private function createScenario(): array
    {
        $site = Site::query()->create(['name' => 'Site Recalculation', 'region' => 'Sulawesi']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $stuckWorkOrder = $this->createWorkOrder($site, 'DD 1001 AA', 'in_progress', [
            ['status' => 'on_hold', 'action' => null],
        ], $mechanic->id);
        $activeWorkOrder = $this->createWorkOrder($site, 'DD 1002 AA', 'open', [
            ['status' => 'in_progress', 'action' => 'replace'],
        ]);
        $resolvedWorkOrder = $this->createWorkOrder($site, 'DD 1003 AA', 'open', [
            ['status' => 'complete', 'action' => 'replace'],
            ['status' => 'postponed', 'action' => 'postpone'],
        ]);
        $rejectedPreviewWorkOrder = $this->createWorkOrder($site, 'DD 1004 AA', 'cancelled', [
            ['status' => 'rejected', 'action' => 'create_task'],
        ]);

        return compact('stuckWorkOrder', 'activeWorkOrder', 'resolvedWorkOrder', 'rejectedPreviewWorkOrder');
    }

    /**
     * @param  array<int, array{status: string, action: string|null}>  $items
     */
    private function createWorkOrder(Site $site, string $plate, string $status, array $items, ?int $mechanicId = null): WorkOrder
    {
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 50000,
            'status' => 'active',
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => $status,
            'assigned_mechanic_id' => $mechanicId,
        ]);

        foreach ($items as $index => $itemData) {
            $planningItem = PlanningItem::query()->create([
                'name' => $plate.' Item '.$index,
                'interval_km' => 10000,
                'interval_days' => 90,
            ]);
            $planning = UnitPlanning::query()->create([
                'unit_id' => $unit->id,
                'planning_item_id' => $planningItem->id,
                'last_done_km' => 40000,
                'last_done_date' => today()->subDays(90)->toDateString(),
                'next_due_km' => 50000,
                'next_due_date' => today()->toDateString(),
            ]);

            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planningItem->id,
                'status' => $itemData['status'],
                'action' => $itemData['action'],
            ]);
        }

        return $workOrder;
    }
}
