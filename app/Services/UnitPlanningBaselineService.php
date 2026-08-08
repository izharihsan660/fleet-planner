<?php

namespace App\Services;

use App\Models\HighUsageFlag;
use App\Models\UnitPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class UnitPlanningBaselineService
{
    public function __construct(
        private PlanningIntervalResolver $intervalResolver,
        private MaintenanceTriggerService $maintenanceTriggerService,
        private HighUsageService $highUsageService,
    ) {}

    public function set(UnitPlanning $unitPlanning, int $lastDoneKm, CarbonImmutable $lastDoneDate): UnitPlanning
    {
        $unitPlanning = DB::transaction(function () use ($unitPlanning, $lastDoneKm, $lastDoneDate): UnitPlanning {
            $unitPlanning->loadMissing(['unit', 'planningItem']);
            $interval = $this->intervalResolver->resolve($unitPlanning->planningItem, $unitPlanning->unit);

            $unitPlanning->update([
                'last_done_km' => $lastDoneKm,
                'last_done_date' => $lastDoneDate->toDateString(),
                'next_due_km' => $lastDoneKm + $interval['interval_km'],
                'next_due_date' => $lastDoneDate->addDays($interval['interval_days'])->toDateString(),
                'is_estimated' => false,
            ]);

            HighUsageFlag::query()
                ->where('unit_planning_id', $unitPlanning->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'updated_at' => now()]);

            return $unitPlanning->refresh();
        });

        $this->maintenanceTriggerService->checkAndTrigger($unitPlanning->unit->refresh());
        $this->highUsageService->detect($unitPlanning->unit->refresh());

        return $unitPlanning->refresh();
    }
}
