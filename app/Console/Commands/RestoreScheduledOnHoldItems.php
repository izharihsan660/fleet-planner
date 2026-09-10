<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Perbaikan sekali jalan untuk item yang terlanjur dijatuhkan ke on_hold oleh
 * maintenance:check-overdue versi lama. Item seperti itu masih memegang mekanik
 * penanggung jawab dan tanggal pengerjaannya, tapi statusnya membuatnya hilang
 * dari Tugas Saya mekanik. Aturannya sama dengan yang dipakai assignItem():
 * sudah disetujui + punya mekanik + punya tanggal berarti in_progress.
 */
class RestoreScheduledOnHoldItems extends Command
{
    protected $signature = 'fleet:restore-scheduled-on-hold {--dry-run} {--execute}';

    protected $description = 'Restore approved and scheduled work order items that were pushed back to on_hold.';

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
            $this->info('Tidak ada item on_hold yang masih memegang mekanik dan jadwal.');

            return self::SUCCESS;
        }

        $this->table(
            ['Plat Nomor', 'Item', 'Mekanik', 'Tanggal Jadwal'],
            $items->map(fn (WorkOrderItem $item): array => [
                $item->workOrder?->unit?->current_plate ?? '-',
                $item->planningItem?->name ?? '-',
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
                $count = WorkOrderItem::query()
                    ->whereKey($items->modelKeys())
                    ->where('status', 'on_hold')
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
        return WorkOrderItem::query()
            ->applicable()
            ->with([
                'workOrder.unit:id,current_plate',
                'workOrder.assignedMechanic:id,name',
                'planningItem:id,name',
            ])
            ->where('status', 'on_hold')
            ->whereNotNull('approved_at')
            ->whereNotNull('scheduled_date')
            ->whereHas('workOrder', fn ($query) => $query->whereNotNull('assigned_mechanic_id'))
            ->orderBy('work_order_id')
            ->orderBy('id')
            ->get();
    }
}
