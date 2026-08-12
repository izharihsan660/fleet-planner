<?php

namespace App\Support;

use App\Models\WorkOrder;

class WorkOrderMerger
{
    /**
     * Work order yang masih terbuka untuk sebuah unit, yaitu yang itemnya belum
     * seluruhnya complete/cancelled. Item trigger baru digabungkan ke WO ini
     * supaya kunjungan mekanik tetap efisien tanpa memengaruhi tampilan Kanban
     * yang sudah per item.
     */
    public static function openWorkOrderFor(int $unitId): ?WorkOrder
    {
        return WorkOrder::query()
            ->where('unit_id', $unitId)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->latest('id')
            ->first();
    }
}
