<?php

namespace App\Services;

use App\Models\UnitPlanning;
use App\Models\WorkOrderItem;
use Closure;
use Illuminate\Support\Facades\DB;

class WorkOrderItemGuard
{
    public function findBlockingItem(UnitPlanning $planning): ?WorkOrderItem
    {
        return DB::transaction(function () use ($planning): ?WorkOrderItem {
            $this->lockPlanning($planning);

            return $this->blockingItem($planning);
        });
    }

    /**
     * @param  Closure(): WorkOrderItem  $create
     */
    public function createIfUnblocked(UnitPlanning $planning, Closure $create): ?WorkOrderItem
    {
        return DB::transaction(function () use ($planning, $create): ?WorkOrderItem {
            $this->lockPlanning($planning);

            if ($this->blockingItem($planning) !== null) {
                return null;
            }

            return $create();
        });
    }

    private function lockPlanning(UnitPlanning $planning): void
    {
        UnitPlanning::query()->whereKey($planning->id)->lockForUpdate()->firstOrFail();
    }

    private function blockingItem(UnitPlanning $planning): ?WorkOrderItem
    {
        return WorkOrderItem::query()
            ->where('unit_planning_id', $planning->id)
            ->blocking()
            ->latest('id')
            ->first();
    }
}
