<?php

namespace App\Services;

use App\Models\HighUsageFlag;
use App\Models\UnitPlanning;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class UnitPlanningBaselineService
{
    public function __construct(
        private PlanningIntervalResolver $intervalResolver,
        private MaintenanceTriggerService $maintenanceTriggerService,
        private HighUsageService $highUsageService,
    ) {}

    /**
     * @param  CarbonImmutable|null  $targetDueDate  Diisi kalau pengisi hanya punya
     *                                               perkiraan jatuh tempo; tanggal
     *                                               terakhir diganti dihitung mundur
     *                                               dari interval.
     */
    public function set(
        UnitPlanning $unitPlanning,
        int $lastDoneKm,
        ?CarbonImmutable $lastDoneDate = null,
        ?CarbonImmutable $targetDueDate = null,
        bool $isEstimated = false,
    ): UnitPlanning {
        [$unitPlanning, $activeItemUpdated] = DB::transaction(function () use ($unitPlanning, $lastDoneKm, $lastDoneDate, $targetDueDate, $isEstimated): array {
            $unitPlanning = UnitPlanning::query()
                ->whereKey($unitPlanning->id)
                ->lockForUpdate()
                ->firstOrFail();
            $unitPlanning->loadMissing(['unit', 'planningItem']);
            $interval = $this->intervalResolver->resolve($unitPlanning->planningItem, $unitPlanning->unit);
            $previousDueKm = $unitPlanning->next_due_km;
            $previousDueDate = $unitPlanning->next_due_date?->toDateString();

            // Satu sumber kebenaran tetap last_done_*; perkiraan jatuh tempo cuma
            // cara lain memasukkannya, dihitung mundur sebesar satu interval.
            $lastDoneDate ??= $targetDueDate?->subDays($interval['interval_days']);

            $nextDueKm = $lastDoneKm + $interval['interval_km'];
            $nextDueDate = $targetDueDate !== null
                ? $targetDueDate->toDateString()
                : $lastDoneDate->addDays($interval['interval_days'])->toDateString();

            $unitPlanning->update([
                'last_done_km' => $lastDoneKm,
                'last_done_date' => $lastDoneDate->toDateString(),
                'next_due_km' => $nextDueKm,
                'next_due_date' => $nextDueDate,
                'is_estimated' => $isEstimated,
                'due_manually_set' => false,
            ]);

            $activeItem = WorkOrderItem::query()
                ->where('unit_planning_id', $unitPlanning->id)
                ->activeForBaselineUpdate()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $activeItem?->update([
                'baseline_last_done_km' => $lastDoneKm,
                'baseline_last_done_date' => $lastDoneDate->toDateString(),
                'previous_due_km' => $previousDueKm,
                'previous_due_date' => $previousDueDate,
                'new_due_km' => $nextDueKm,
                'new_due_date' => $nextDueDate,
            ]);

            HighUsageFlag::query()
                ->where('unit_planning_id', $unitPlanning->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'updated_at' => now()]);

            return [$unitPlanning->refresh(), $activeItem !== null];
        });

        $this->maintenanceTriggerService->checkAndTrigger(
            $unitPlanning->unit->refresh(),
            $activeItemUpdated ? [$unitPlanning->id] : [],
        );
        $this->highUsageService->detect($unitPlanning->unit->refresh());

        return $unitPlanning->refresh();
    }
}
