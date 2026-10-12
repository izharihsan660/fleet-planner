<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulihkan item approved yang masih punya mekanik dan jadwal tetapi tertahan
 * di on_hold, atau approved Replace yang tertahan di overdue. Item tanpa
 * penugasan dikecualikan karena approval dan relokasi bisa sah menghasilkan
 * on_hold sampai planner menjadwalkannya kembali.
 */
class RestoreScheduledOnHoldItems extends Command
{
    protected $signature = 'fleet:restore-scheduled-on-hold {--dry-run} {--execute}';

    protected $description = 'Restore approved scheduled items stranded in on_hold or overdue Replace items.';

    public function handle(WorkOrderProgressService $workOrderProgressService): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $items = $this->strandedItems();

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if ($items->isEmpty()) {
            $this->info('Tidak ada item on_hold/overdue yang memenuhi kriteria pemulihan.');

            return self::SUCCESS;
        }

        $this->table(
            ['Plat Nomor', 'Item', 'Status Saat Ini', 'Mekanik', 'Tanggal Jadwal'],
            $items->map(fn (WorkOrderItem $item): array => [
                $item->workOrder?->unit?->current_plate ?? '-',
                $item->planningItem?->name ?? '-',
                $item->status,
                $item->workOrder?->assignedMechanic?->name ?? '-',
                $item->scheduled_date?->toDateString() ?? '-',
            ])->all(),
        );

        if (! $execute) {
            $this->info($items->count().' item akan dikembalikan ke in_progress. Tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        try {
            $updatedCount = DB::transaction(function () use ($items, $workOrderProgressService): int {
                $count = $this->restorableItems()
                    ->whereKey($items->modelKeys())
                    ->update(['status' => 'in_progress']);

                WorkOrder::query()
                    ->whereIn('id', $items->pluck('work_order_id')->unique()->values())
                    ->get()
                    ->each(fn (WorkOrder $workOrder) => $workOrderProgressService->sync($workOrder));

                return $count;
            });
        } catch (Throwable $exception) {
            $this->error('Perbaikan gagal dan seluruh perubahan telah di-rollback: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($updatedCount.' item berhasil dikembalikan ke in_progress.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    private function strandedItems(): Collection
    {
        return $this->restorableItems()
            ->with([
                'workOrder.unit:id,current_plate',
                'workOrder.assignedMechanic:id,name',
                'planningItem:id,name',
            ])
            ->orderBy('work_order_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<WorkOrderItem>
     */
    private function restorableItems(): Builder
    {
        return WorkOrderItem::query()
            ->applicable()
            ->whereNotNull('approved_at')
            ->whereNull('completed_date')
            ->whereNotNull('scheduled_date')
            ->whereHas('workOrder', fn ($query) => $query->whereNotNull('assigned_mechanic_id'))
            ->where(fn (Builder $query): Builder => $query
                ->where('status', 'on_hold')
                ->orWhere(fn (Builder $overdueQuery): Builder => $overdueQuery
                    ->where('status', 'overdue')
                    ->where('action', 'replace')));
    }
}
