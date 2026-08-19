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
            $override = $planningItem->relationLoaded('overrides')
                ? $planningItem->overrides->firstWhere('vehicle_category', $unit->vehicle_category)
                : PlanningItemOverride::query()
                    ->where('planning_item_id', $planningItem->id)
                    ->where('vehicle_category', $unit->vehicle_category)
                    ->first(['interval_km', 'interval_days']);
        }

        return [
            'interval_km' => (int) ($override?->interval_km ?? $planningItem->interval_km),
            'interval_days' => (int) ($override?->interval_days ?? $planningItem->interval_days),
        ];
    }

    /**
     * Baseline KM due berikutnya.
     *
     * Riwayat penggantian selalu menang: kalau `last_done_km` terisi, itu acuannya,
     * termasuk saat odometer unit belum pernah diinput mekanik. Kalau tidak ada
     * riwayat, pakai odometer unit — tapi hanya kalau odometer itu data asli.
     * NULL berarti benar-benar belum ada acuan KM sama sekali.
     */
    public function nextDueKm(int $lastDoneKm, int $currentOdometer, bool $hasOdometerReading, int $intervalKm): ?int
    {
        $baseline = match (true) {
            $lastDoneKm > 0 => $lastDoneKm,
            $hasOdometerReading => $currentOdometer,
            default => null,
        };

        return $baseline === null ? null : $baseline + $intervalKm;
    }
}
