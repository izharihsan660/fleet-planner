<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Collection;

class WorkOrderProgressService
{
    /**
     * @var array<int, string>
     */
    private const TERMINAL_WORK_ORDER_STATUSES = ['cancelled', 'complete'];

    /**
     * @var array<string, string>
     */
    private const ACTIVE_ITEM_LABELS = [
        'overdue' => 'overdue',
        'rejected' => 'rejected',
        'baseline_incomplete' => 'baseline belum diisi',
        'in_progress' => 'in progress',
        'on_hold' => 'on hold',
        'replace' => 'replace menunggu approval',
        'postpone' => 'postpone menunggu approval',
        'pending_create' => 'pembuatan task menunggu approval',
        'breakdown' => 'breakdown',
        'blocked' => 'blocked',
    ];

    /**
     * @return array<int, string>
     */
    public function finalStatuses(): array
    {
        return ['complete', 'postponed'];
    }

    /**
     * @return array<int, string>
     */
    public function terminalWorkOrderStatuses(): array
    {
        return self::TERMINAL_WORK_ORDER_STATUSES;
    }

    public function isTerminalWorkOrder(WorkOrder $workOrder): bool
    {
        return in_array($workOrder->status, self::TERMINAL_WORK_ORDER_STATUSES, true);
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return Collection<int, WorkOrderItem>
     */
    public function progressItems(Collection $items): Collection
    {
        return $items->values();
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

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return Collection<int, WorkOrderItem>
     */
    public function activeItems(Collection $items): Collection
    {
        return $this->progressItems($items)
            ->reject(fn (WorkOrderItem $item): bool => in_array($item->status, $this->finalStatuses(), true))
            ->values();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function activeItemBreakdown(Collection $items): array
    {
        $groupedItems = $this->activeItems($items)->groupBy(
            fn (WorkOrderItem $item): string => ($item->unitPlanning?->isBaselineMissing() ?? true)
                ? 'baseline_incomplete'
                : $item->status
        );
        $breakdown = [];

        foreach (self::ACTIVE_ITEM_LABELS as $key => $label) {
            if (! $groupedItems->has($key)) {
                continue;
            }

            $breakdown[] = [
                'key' => $key,
                'label' => $label,
                'count' => $groupedItems->get($key)->count(),
            ];
        }

        foreach ($groupedItems as $key => $statusItems) {
            if (array_key_exists((string) $key, self::ACTIVE_ITEM_LABELS)) {
                continue;
            }

            $breakdown[] = [
                'key' => (string) $key,
                'label' => str_replace('_', ' ', (string) $key),
                'count' => $statusItems->count(),
            ];
        }

        return $breakdown;
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     */
    public function isDirectCompletionReady(Collection $items): bool
    {
        $activeItems = $this->activeItems($items);

        return $activeItems->isNotEmpty()
            && $activeItems->every(fn (WorkOrderItem $item): bool => $item->status === 'in_progress'
                && ! ($item->unitPlanning?->isBaselineMissing() ?? true));
    }

    public function sync(WorkOrder $workOrder): void
    {
        if ($this->isTerminalWorkOrder($workOrder)) {
            return;
        }

        $items = $workOrder->items()->applicable()->get();
        $workOrder->setRelation('items', $items);
        $targetStatus = $this->statusFor($workOrder, $items);

        if ($workOrder->status !== $targetStatus) {
            $workOrder->update(['status' => $targetStatus]);
        }
    }

    /**
     * @param  Collection<int, WorkOrderItem>|null  $items
     */
    public function statusFor(WorkOrder $workOrder, ?Collection $items = null): string
    {
        if ($this->isTerminalWorkOrder($workOrder)) {
            return $workOrder->status;
        }

        $items ??= $workOrder->items()->applicable()->get();

        if ($items->isEmpty()) {
            return 'cancelled';
        }

        if ($this->isFullyResolved($items)) {
            return 'complete';
        }

        if ($items->contains('status', 'in_progress')) {
            return 'in_progress';
        }

        $activeItems = $this->activeItems($items);

        if ($activeItems->isNotEmpty() && $activeItems->every(
            fn (WorkOrderItem $item): bool => $item->status === 'rejected' && $item->action === 'create_task'
        )) {
            return 'cancelled';
        }

        return 'open';
    }
}
