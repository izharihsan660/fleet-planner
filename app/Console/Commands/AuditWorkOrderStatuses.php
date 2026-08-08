<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AuditWorkOrderStatuses extends Command
{
    protected $signature = 'work-orders:audit-statuses {--fix : Update mismatched work order statuses}';

    protected $description = 'Audit and optionally fix work orders whose status no longer matches their item progress.';

    public function handle(WorkOrderProgressService $workOrderProgressService): int
    {
        $mismatches = WorkOrder::query()
            ->with(['unit:id,current_plate', 'items:id,work_order_id,status'])
            ->whereHas('items')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('status', 'complete')
                    ->whereHas('items', fn (Builder $items) => $items
                        ->whereNotIn('status', $this->resolvedStatuses()))
                )
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', '!=', 'complete')
                    ->whereDoesntHave('items', fn (Builder $items) => $items
                        ->whereNotIn('status', $this->resolvedStatuses()))
                )
            )
            ->get();

        $this->info('Mismatched work orders: '.$mismatches->count());

        foreach ($mismatches as $workOrder) {
            $progressItems = $workOrderProgressService->progressItems($workOrder->items);
            $targetStatus = $workOrderProgressService->statusFor($workOrder, $workOrder->items);
            $this->line(sprintf(
                'WO #%d %s: %s -> %s (%d/%d tuntas)',
                $workOrder->id,
                $workOrder->unit?->current_plate ?? '-',
                $workOrder->status,
                $targetStatus,
                $progressItems->whereIn('status', $this->resolvedStatuses())->count(),
                $progressItems->count(),
            ));

            if ($this->option('fix')) {
                $workOrder->update(['status' => $targetStatus]);
            }
        }

        if (! $this->option('fix')) {
            $this->warn('Dry-run only. Re-run with --fix to update data.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolvedStatuses(): array
    {
        return ['complete', 'postponed'];
    }
}
