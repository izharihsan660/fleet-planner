<?php

namespace App\Services;

use App\Models\HighUsageFlag;
use App\Models\UnitPlanning;
use Illuminate\Support\Facades\DB;

class UnitPlanningExclusionService
{
    public function __construct(private PlanningIntervalResolver $intervalResolver) {}

    public function update(UnitPlanning $unitPlanning, bool $isExcluded, ?string $reason): UnitPlanning
    {
        return DB::transaction(function () use ($unitPlanning, $isExcluded, $reason): UnitPlanning {
            $unitPlanning->loadMissing(['unit', 'planningItem']);

            if ($isExcluded) {
                $unitPlanning->update([
                    'is_excluded' => true,
                    'excluded_reason' => $this->normalizeReason($reason),
                    'is_estimated' => false,
                    'next_due_km' => null,
                    'next_due_date' => null,
                ]);

                HighUsageFlag::query()
                    ->where('unit_planning_id', $unitPlanning->id)
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now(), 'updated_at' => now()]);

                return $unitPlanning->refresh();
            }

            $interval = $this->intervalResolver->resolve($unitPlanning->planningItem, $unitPlanning->unit);

            $unitPlanning->update([
                'is_excluded' => false,
                'excluded_reason' => null,
                'next_due_km' => $unitPlanning->isBaselineMissing()
                    ? null
                    : $this->intervalResolver->nextDueKm(
                        (int) $unitPlanning->last_done_km,
                        (int) $unitPlanning->unit->current_odo,
                        (bool) $unitPlanning->unit->has_odometer_reading,
                        $interval['interval_km'],
                    ),
                'next_due_date' => $unitPlanning->last_done_date?->copy()->addDays($interval['interval_days'])->toDateString(),
            ]);

            return $unitPlanning->refresh();
        });
    }

    private function normalizeReason(?string $reason): ?string
    {
        $normalized = str((string) $reason)->trim()->squish()->toString();

        return $normalized !== '' ? $normalized : null;
    }
}
