<?php

namespace App\Console\Commands;

use App\Models\WorkOrderItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class AuditDuplicateWorkOrderItems extends Command
{
    protected $signature = 'wo:audit-duplicate-items
                            {--dry-run : Audit duplicate items without changing data (default)}
                            {--execute : Run the same audit report in execute mode; no cleanup is performed}';

    protected $description = 'Audit duplicate work order items by work order and planning item without changing data.';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Gunakan salah satu opsi saja: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $duplicateGroups = $this->duplicateGroups();

        $this->line('MODE: '.($execute ? 'EXECUTE (AUDIT ONLY)' : 'DRY-RUN'));
        $this->warn('Command ini hanya melakukan audit. Tidak ada data yang diubah atau dihapus.');

        if ($duplicateGroups->isEmpty()) {
            $this->info('Tidak ada duplikat work order item yang memenuhi kriteria audit.');

            return self::SUCCESS;
        }

        $items = $this->duplicateItems($duplicateGroups);

        $this->table(
            ['WO ID', 'Unit', 'Planning Item', 'Item ID', 'Status', 'Due KM', 'Due Date', 'Created At'],
            $items->map(fn (WorkOrderItem $item): array => [
                $item->work_order_id,
                $item->workOrder?->unit?->current_plate ?? '-',
                $item->planningItem?->name ?? '-',
                $item->id,
                $item->status,
                $item->new_due_km ?? $item->unitPlanning?->next_due_km ?? '-',
                $item->new_due_date?->toDateString() ?? $item->unitPlanning?->next_due_date?->toDateString() ?? '-',
                $item->created_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        $this->newLine();
        $this->info('Total grup duplikat: '.$duplicateGroups->count());
        $this->info('Total baris dalam grup duplikat: '.$items->count());

        return self::SUCCESS;
    }

    /**
     * @return SupportCollection<int, object{work_order_id: int, planning_item_id: int, active_count: int, cancelled_count: int, non_cancelled_count: int}>
     */
    private function duplicateGroups(): SupportCollection
    {
        return WorkOrderItem::query()
            ->select(['work_order_id', 'planning_item_id'])
            ->selectRaw("SUM(CASE WHEN status NOT IN ('cancelled', 'complete') THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count")
            ->selectRaw("SUM(CASE WHEN status != 'cancelled' THEN 1 ELSE 0 END) as non_cancelled_count")
            ->groupBy(['work_order_id', 'planning_item_id'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('work_order_id')
            ->orderBy('planning_item_id')
            ->get()
            ->filter(fn (WorkOrderItem $group): bool => (int) $group->getAttribute('active_count') > 1
                || ((int) $group->getAttribute('cancelled_count') > 0
                    && (int) $group->getAttribute('non_cancelled_count') > 0))
            ->map(fn (WorkOrderItem $group): object => (object) [
                'work_order_id' => (int) $group->work_order_id,
                'planning_item_id' => (int) $group->planning_item_id,
                'active_count' => (int) $group->getAttribute('active_count'),
                'cancelled_count' => (int) $group->getAttribute('cancelled_count'),
                'non_cancelled_count' => (int) $group->getAttribute('non_cancelled_count'),
            ])
            ->values();
    }

    /**
     * @param  SupportCollection<int, object{work_order_id: int, planning_item_id: int}>  $duplicateGroups
     * @return Collection<int, WorkOrderItem>
     */
    private function duplicateItems(SupportCollection $duplicateGroups): Collection
    {
        return WorkOrderItem::query()
            ->with([
                'workOrder.unit:id,current_plate',
                'planningItem:id,name',
                'unitPlanning:id,next_due_km,next_due_date',
            ])
            ->where(function ($query) use ($duplicateGroups): void {
                $duplicateGroups->each(function (object $group) use ($query): void {
                    $query->orWhere(function ($groupQuery) use ($group): void {
                        $groupQuery
                            ->where('work_order_id', $group->work_order_id)
                            ->where('planning_item_id', $group->planning_item_id);
                    });
                });
            })
            ->orderBy('work_order_id')
            ->orderBy('planning_item_id')
            ->orderBy('id')
            ->get();
    }
}
