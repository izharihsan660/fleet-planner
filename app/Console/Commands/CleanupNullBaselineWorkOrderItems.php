<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CleanupNullBaselineWorkOrderItems extends Command
{
    protected $signature = 'wo:cleanup-null-baseline-items
                            {--dry-run : Preview affected work order items without changing data (default)}
                            {--execute : Delete affected items and empty parent work orders}';

    protected $description = 'Delete only on-hold work order items generated from unit plannings with a missing item baseline.';

    public function handle(WorkOrderProgressService $workOrderProgressService): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $allItems = $this->baselineNullItems();
        $itemsToDelete = $allItems->where('status', 'on_hold')->values();
        $skippedItems = $allItems->where('status', '!=', 'on_hold')->values();
        $workOrderIds = $itemsToDelete->pluck('work_order_id')->unique()->values();

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if ($itemsToDelete->isNotEmpty()) {
            $this->info($itemsToDelete->count().' item AKAN DIHAPUS (baseline NULL dan status on_hold):');
            $this->displayItemsTable($itemsToDelete);
        } else {
            $this->info('Tidak ada item baseline NULL berstatus on_hold yang akan dihapus.');
        }

        if ($skippedItems->isNotEmpty()) {
            $this->warn('⚠️  '.$skippedItems->count().' item DILEWATI (baseline NULL tapi status bukan on_hold — perlu review manual):');
            $this->displayItemsTable($skippedItems);
        }

        if (! $execute) {
            $this->info('DRY-RUN saja. Tidak ada data yang diubah. Jalankan dengan --execute setelah hasil dikonfirmasi.');
            $this->displaySummary($allItems, $itemsToDelete, $skippedItems);

            return self::SUCCESS;
        }

        $deletedItemCount = 0;
        $deletedParents = 0;

        if ($itemsToDelete->isNotEmpty()) {
            [$deletedItemCount, $deletedParents] = DB::transaction(function () use ($itemsToDelete, $workOrderIds, $workOrderProgressService): array {
                $deletedItemCount = WorkOrderItem::query()
                    ->whereKey($itemsToDelete->pluck('id'))
                    ->where('status', 'on_hold')
                    ->missingBaseline()
                    ->delete();

                $deletedParents = WorkOrder::query()
                    ->whereKey($workOrderIds)
                    ->whereDoesntHave('items')
                    ->delete();

                WorkOrder::query()
                    ->whereKey($workOrderIds)
                    ->get()
                    ->each(fn (WorkOrder $workOrder) => $workOrderProgressService->sync($workOrder));

                return [$deletedItemCount, $deletedParents];
            });
        }

        $this->info('EXECUTE selesai. Item dihapus: '.$deletedItemCount.'. WO parent kosong dihapus: '.$deletedParents.'.');
        $this->displaySummary($allItems, $itemsToDelete, $skippedItems);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    private function baselineNullItems(): Collection
    {
        return WorkOrderItem::query()
            ->with(['workOrder.unit:id,current_plate', 'planningItem:id,name'])
            ->missingBaseline()
            ->orderBy('work_order_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     */
    private function displayItemsTable(Collection $items): void
    {
        $this->table(
            ['Plat Nomor', 'Item', 'WO ID', 'Status'],
            $items->map(fn (WorkOrderItem $item): array => [
                $item->workOrder?->unit?->current_plate ?? '-',
                $item->planningItem?->name ?? '-',
                $item->work_order_id,
                $item->status,
            ])->all(),
        );
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $allItems
     * @param  Collection<int, WorkOrderItem>  $itemsToDelete
     * @param  Collection<int, WorkOrderItem>  $skippedItems
     */
    private function displaySummary(Collection $allItems, Collection $itemsToDelete, Collection $skippedItems): void
    {
        $this->newLine();
        $this->info('Total item ditemukan (baseline NULL): '.$allItems->count());
        $this->info('Akan dihapus (status on_hold): '.$itemsToDelete->count());
        $this->info('Dilewati untuk review manual (status lain): '.$skippedItems->count());
    }
}
