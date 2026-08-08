<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Collection;

class WorkOrderProgressService
{
    /**
     * @return array<int, string>
     */
    public function finalStatuses(): array
    {
        return ['complete', 'postponed'];
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return Collection<int, WorkOrderItem>
     */
    public function progressItems(Collection $items): Collection
    {
        return $items
            ->reject(fn (WorkOrderItem $item): bool => $item->status === 'blocked')
            ->values();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     */
    public function isFullyResolved(Collection $items): bool
    {
        return $items->isNotEmpty()
            && $this->progressItems($items)->every(
                fn (WorkOrderItem $item): bool => in_array($item->status, $this->finalStatuses(), true)
            );
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     */
    public function totalItemsCount(Collection $items): int
    {
        return $this->progressItems($items)->count();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     */
    public function completedItemsCount(Collection $items): int
    {
        return $this->progressItems($items)
            ->whereIn('status', $this->finalStatuses())
            ->count();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     */
    public function remainingItemsCount(Collection $items): int
    {
        return max($this->totalItemsCount($items) - $this->completedItemsCount($items), 0);
    }

    public function sync(WorkOrder $workOrder): void
    {
        $items = $workOrder->items()->applicable()->get();
        $workOrder->setRelation('items', $items);
        $progressItems = $this->progressItems($items);

        if ($this->isFullyResolved($items)) {
            $workOrder->update(['status' => 'complete']);

            return;
        }

        if ($workOrder->assigned_mechanic_id !== null || $progressItems->contains('status', 'in_progress')) {
            $workOrder->update(['status' => 'in_progress']);

            return;
        }

        if ($progressItems->contains(fn (WorkOrderItem $item): bool => in_array($item->status, ['on_hold', 'overdue', 'pending_create', 'replace', 'postpone', 'breakdown'], true))) {
            $workOrder->update(['status' => 'open']);

            return;
        }

        $workOrder->update(['status' => 'cancelled']);
    }
}
