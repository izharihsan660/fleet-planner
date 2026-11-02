<?php

namespace App\Services;

use App\Models\UnitPlanning;
use App\Models\WorkOrderItem;
use Closure;
use Illuminate\Support\Facades\DB;

class WorkOrderItemGuard
{
    public function findBlockingItem(UnitPlanning $planning, bool $ignoreRejected = false): ?WorkOrderItem
    {
        return DB::transaction(function () use ($planning, $ignoreRejected): ?WorkOrderItem {
            $this->lockPlanning($planning);

            return $this->blockingItem($planning, $ignoreRejected);
        });
    }

    /**
     * @param  Closure(): WorkOrderItem  $create
     */
    public function createIfUnblocked(UnitPlanning $planning, Closure $create, bool $ignoreRejected = false): ?WorkOrderItem
    {
        return DB::transaction(function () use ($planning, $create, $ignoreRejected): ?WorkOrderItem {
            $this->lockPlanning($planning);

            if ($this->blockingItem($planning, $ignoreRejected) !== null) {
                return null;
            }

            return $create();
        });
    }

    private function lockPlanning(UnitPlanning $planning): void
    {
        UnitPlanning::query()->whereKey($planning->id)->lockForUpdate()->firstOrFail();
    }

    private function blockingItem(UnitPlanning $planning, bool $ignoreRejected = false): ?WorkOrderItem
    {
        return WorkOrderItem::query()
            ->where('unit_planning_id', $planning->id)
            ->blocking()
            ->when($ignoreRejected, fn ($query) => $query->where('status', '!=', 'rejected'))
            ->latest('id')
            ->first();
    }
}
