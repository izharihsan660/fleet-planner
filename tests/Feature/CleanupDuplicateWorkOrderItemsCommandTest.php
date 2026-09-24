<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use JsonException;
use Tests\TestCase;

class CleanupDuplicateWorkOrderItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_dry_run_lists_candidates_without_changing_data(): void
    {
        $scenario = $this->createDuplicateGroup(['rejected', 'on_hold']);
        $rejectedItem = $scenario['items'][0];
        $activeItem = $scenario['items'][1];

        $exitCode = Artisan::call('wo:cleanup-duplicate-items');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('MODE: DRY-RUN', $output);
        $this->assertStringContainsString('KT 8451 DP', $output);
        $this->assertStringContainsString('Ganti Oli Mesin', $output);
        $this->assertStringContainsString((string) $rejectedItem->id, $output);
        $this->assertStringContainsString((string) $activeItem->id, $output);
        $this->assertStringContainsString('Total akan dihapus: 1', $output);
        $this->assertStringContainsString('Grup diproses: 1', $output);
        $this->assertStringContainsString('Grup di-skip: 0', $output);
        $this->assertModelExists($rejectedItem);
        $this->assertModelExists($activeItem);
    }

    /**
     * @throws JsonException
     */
    public function test_execute_deletes_one_rejected_item_and_keeps_active_item(): void
    {
        $scenario = $this->createDuplicateGroup(['rejected', 'on_hold']);
        $rejectedItem = $scenario['items'][0];
        $activeItem = $scenario['items'][1];
        Log::spy();

        $exitCode = Artisan::call('wo:cleanup-duplicate-items', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertModelMissing($rejectedItem);
        $this->assertModelExists($activeItem);
        $this->assertStringContainsString('Total dihapus: 1', $output);
        $this->assertStringContainsString('Grup diproses: 1', $output);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($rejectedItem): bool {
                $row = json_decode($context['row_json'], true, 512, JSON_THROW_ON_ERROR);

                return $message === 'Menghapus duplicate work order item lama.'
                    && $context['work_order_id'] === $rejectedItem->work_order_id
                    && $context['planning_item_id'] === $rejectedItem->planning_item_id
                    && $context['work_order_item_id'] === $rejectedItem->id
                    && $row['id'] === $rejectedItem->id
                    && $row['status'] === 'rejected'
                    && array_key_exists('created_at', $row);
            });
    }

    public function test_execute_deletes_all_rejected_and_cancelled_items_when_active_item_exists(): void
    {
        $scenario = $this->createDuplicateGroup(['rejected', 'cancelled', 'overdue']);
        $rejectedItem = $scenario['items'][0];
        $cancelledItem = $scenario['items'][1];
        $activeItem = $scenario['items'][2];

        $exitCode = Artisan::call('wo:cleanup-duplicate-items', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertModelMissing($rejectedItem);
        $this->assertModelMissing($cancelledItem);
        $this->assertModelExists($activeItem);
        $this->assertStringContainsString('Total dihapus: 2', $output);
        $this->assertStringContainsString('Grup diproses: 1', $output);
    }

    public function test_execute_skips_group_when_all_items_are_rejected_or_cancelled(): void
    {
        $scenario = $this->createDuplicateGroup(['rejected', 'cancelled']);
        $rejectedItem = $scenario['items'][0];
        $cancelledItem = $scenario['items'][1];

        $exitCode = Artisan::call('wo:cleanup-duplicate-items', ['--execute' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertModelExists($rejectedItem);
        $this->assertModelExists($cancelledItem);
        $this->assertStringContainsString('grup dilewati: tidak ada baris aktif', $output);
        $this->assertStringContainsString('Total dihapus: 0', $output);
        $this->assertStringContainsString('Grup diproses: 0', $output);
        $this->assertStringContainsString('Grup di-skip: 1', $output);
    }

    /**
     * @param  list<string>  $statuses
     * @return array{workOrder: WorkOrder, items: Collection<int, WorkOrderItem>}
     */
    private function createDuplicateGroup(array $statuses): array
    {
        $site = Site::query()->create([
            'name' => 'Site Cleanup Duplicate',
            'region' => 'Kalimantan',
        ]);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT Cleanup Duplicate',
            'current_plate' => 'KT 8451 DP',
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 84500,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]));
        $planningItem = PlanningItem::query()->create([
            'name' => 'Ganti Oli Mesin',
            'interval_km' => 5000,
            'interval_days' => 90,
        ]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 80000,
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => 85000,
            'next_due_date' => today()->addMonths(2)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $items = collect($statuses)
            ->map(fn (string $status): WorkOrderItem => WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $unitPlanning->id,
                'planning_item_id' => $planningItem->id,
                'status' => $status,
            ]));

        return compact('workOrder', 'items');
    }
}
