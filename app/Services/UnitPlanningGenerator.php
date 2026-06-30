<?php

namespace App\Services;

use App\Models\PlanningItem;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class UnitPlanningGenerator
{
    public function generateForUnit(Unit $unit, ?CarbonInterface $date = null): int
    {
        $baseDate = Carbon::parse($date ?? today())->startOfDay();
        $lastDoneKm = (int) $unit->current_odo;
        $created = 0;

        PlanningItem::query()
            ->orderBy('id')
            ->get(['id', 'interval_km', 'interval_days'])
            ->each(function (PlanningItem $planningItem) use ($unit, $baseDate, $lastDoneKm, &$created): void {
                $unitPlanning = UnitPlanning::query()->firstOrCreate(
                    [
                        'unit_id' => $unit->id,
                        'planning_item_id' => $planningItem->id,
                    ],
                    [
                        'last_done_km' => $lastDoneKm,
                        'last_done_date' => $baseDate->toDateString(),
                        'next_due_km' => $lastDoneKm + (int) $planningItem->interval_km,
                        'next_due_date' => $baseDate->copy()->addDays((int) $planningItem->interval_days)->toDateString(),
                    ],
                );

                if ($unitPlanning->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }
}