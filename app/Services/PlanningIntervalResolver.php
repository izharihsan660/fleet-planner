<?php

namespace App\Services;

use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
use App\Models\Unit;

class PlanningIntervalResolver
{
    /**
     * @return array{interval_km: int, interval_days: int}
     */
    public function resolve(PlanningItem $planningItem, Unit $unit): array
    {
        $override = null;

        if ($unit->vehicle_category) {
            $override = PlanningItemOverride::query()
                ->where('planning_item_id', $planningItem->id)
                ->where('vehicle_category', $unit->vehicle_category)
                ->first(['interval_km', 'interval_days']);
        }

        return [
            'interval_km' => (int) ($override?->interval_km ?? $planningItem->interval_km),
            'interval_days' => (int) ($override?->interval_days ?? $planningItem->interval_days),
        ];
    }
}
