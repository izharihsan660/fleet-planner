<?php

namespace App\Services;

use App\Models\PlanningItem;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class UnitPlanningGenerator
{
    public function __construct(private PlanningIntervalResolver $intervalResolver) {}

    public function generateForUnit(Unit $unit, ?CarbonInterface $date = null): int
    {
        $baseDate = Carbon::parse($date ?? today())->startOfDay();
        $lastDoneKm = (int) $unit->current_odo;
        $hasOdometerReading = $unit->has_odometer_reading;
        $created = 0;

        PlanningItem::query()
            ->orderBy('id')
            ->get(['id', 'interval_km', 'interval_days'])
            ->each(function (PlanningItem $planningItem) use ($unit, $baseDate, $lastDoneKm, $hasOdometerReading, &$created): void {
                if (UnitPlanning::query()
                    ->where('unit_id', $unit->id)
                    ->where('planning_item_id', $planningItem->id)
                    ->exists()) {
                    return;
                }

                $interval = $this->intervalResolver->resolve($planningItem, $unit);

                $unitPlanning = UnitPlanning::query()->firstOrCreate(
                    [
                        'unit_id' => $unit->id,
                        'planning_item_id' => $planningItem->id,
                    ],
                    [
                        'last_done_km' => $lastDoneKm,
                        'last_done_date' => $baseDate->toDateString(),
                        'next_due_km' => $hasOdometerReading
                            ? $lastDoneKm + $interval['interval_km']
                            : null,
                        'next_due_date' => $baseDate->copy()->addDays($interval['interval_days'])->toDateString(),
                    ],
                );

                if ($unitPlanning->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }
}
