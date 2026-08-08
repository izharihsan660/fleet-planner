<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecalculateWorkOrderStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleet:recalculate-wo-status {--dry-run} {--execute}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate work order statuses from their actual item status composition.';

    /**
     * Execute the console command.
     */
    public function handle(WorkOrderProgressService $workOrderProgressService): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $mismatches = $this->mismatchedWorkOrders($workOrderProgressService);

        $this->line('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if ($mismatches->isEmpty()) {
            $this->info('Tidak ada status work order yang perlu diubah.');

            return self::SUCCESS;
        }

        $this->table(
            ['WO ID', 'Plat', 'Status Saat Ini', 'Status Baru', 'Alasan / Komposisi Item'],
            $mismatches->map(fn (WorkOrder $workOrder): array => [
                $workOrder->id,
                $workOrder->unit?->current_plate ?? '-',
                $workOrder->status,
                $workOrder->getAttribute('target_status'),
                $this->changeReason($workOrder),
            ])->all(),
        );

        if (! $execute) {
            $this->info($mismatches->count().' work order akan diubah. Tidak ada data yang diubah.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($mismatches, $workOrderProgressService): void {
                WorkOrder::query()
                    ->whereKey($mismatches->modelKeys())
                    ->lockForUpdate()
                    ->get()
                    ->each(function (WorkOrder $workOrder) use ($workOrderProgressService): void {
                        $workOrderProgressService->sync($workOrder);
                    });
            });
        } catch (Throwable $exception) {
            $this->error('Recalculation gagal dan seluruh perubahan telah di-rollback: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($mismatches->count().' work order berhasil dihitung ulang.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    private function mismatchedWorkOrders(WorkOrderProgressService $workOrderProgressService): Collection
    {
        return WorkOrder::query()
            ->with([
                'unit:id,current_plate',
                'items' => fn ($query) => $query->applicable()->select(['id', 'work_order_id', 'status', 'action']),
            ])
            ->orderBy('id')
            ->get()
            ->map(function (WorkOrder $workOrder) use ($workOrderProgressService): WorkOrder {
                $workOrder->setAttribute('target_status', $workOrderProgressService->statusFor($workOrder, $workOrder->items));

                return $workOrder;
            })
            ->filter(fn (WorkOrder $workOrder): bool => $workOrder->status !== $workOrder->getAttribute('target_status'))
            ->values();
    }

    private function changeReason(WorkOrder $workOrder): string
    {
        $composition = $workOrder->items
            ->countBy('status')
            ->sortKeys()
            ->map(fn (int $count, string $status): string => $status.': '.$count)
            ->implode(', ');
        $targetStatus = $workOrder->getAttribute('target_status');
        $inProgressCount = $workOrder->items->where('status', 'in_progress')->count();

        $reason = match ($targetStatus) {
            'complete' => 'Semua item sudah final.',
            'in_progress' => 'Ada '.$inProgressCount.' item berstatus in_progress.',
            'cancelled' => 'Tidak ada item aktif yang dapat dilanjutkan.',
            default => 'Tidak ada item in_progress; WO kembali ke kolom On Hold.',
        };

        return $reason.' Komposisi: '.($composition !== '' ? $composition : '-');
    }
}
