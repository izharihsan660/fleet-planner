<?php

namespace App\Console\Commands;

use App\Models\WorkOrderItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class FixMisclassifiedOverdueItems extends Command
{
    protected $signature = 'fleet:fix-misclassified-overdue {--dry-run} {--execute}';

    protected $description = 'Reclassify overdue work order items whose item planning baseline has not been filled.';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $items = $this->misclassifiedItems();

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if ($items->isEmpty()) {
            $this->info('Tidak ada work order item overdue dengan baseline item yang belum diisi.');

            return self::SUCCESS;
        }

        $this->table(
            ['Plat Nomor', 'Item', 'Status Saat Ini'],
            $items->map(fn (WorkOrderItem $item): array => [
                $item->workOrder?->unit?->current_plate ?? '-',
                $item->planningItem?->name ?? '-',
                $item->status,
            ])->all(),
        );

        if (! $execute) {
            $this->info($items->count().' item akan diubah dari overdue menjadi on_hold. Tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        try {
            $updatedCount = DB::transaction(fn (): int => WorkOrderItem::query()
                ->whereKey($items->modelKeys())
                ->where('status', 'overdue')
                ->missingBaseline()
                ->update(['status' => 'on_hold']));
        } catch (Throwable $exception) {
            $this->error('Perbaikan gagal dan seluruh perubahan telah di-rollback: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($updatedCount.' item berhasil diubah dari overdue menjadi on_hold.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    private function misclassifiedItems(): Collection
    {
        return WorkOrderItem::query()
            ->with(['workOrder.unit:id,current_plate', 'planningItem:id,name'])
            ->where('status', 'overdue')
            ->missingBaseline()
            ->orderBy('work_order_id')
            ->orderBy('id')
            ->get();
    }
}
