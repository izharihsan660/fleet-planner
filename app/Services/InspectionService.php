<?php

namespace App\Services;

use App\Models\InspectionLog;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InspectionService
{
    public function __construct(
        private MaintenanceTriggerService $maintenanceTriggerService,
        private BlockedBreakdownService $blockedBreakdownService,
        private HighUsageService $highUsageService,
        private PlanningIntervalResolver $intervalResolver,
    ) {}

    public function record(Unit $unit, int $odometer, User $mechanic, Carbon $date): InspectionLog
    {
        if ($odometer <= $unit->current_odo) {
            throw new InvalidArgumentException('Odometer baru harus lebih besar dari odometer unit saat ini.');
        }

        return DB::transaction(function () use ($unit, $odometer, $mechanic, $date): InspectionLog {
            if ($unit->status === 'breakdown') {
                $this->blockedBreakdownService->unfreezeBreakdown($unit);
                $unit->refresh();
            }

            $inspectionDate = $date->copy()->startOfDay();

            $log = InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'inspection_date' => $inspectionDate->toDateString(),
                'mechanic_id' => $mechanic->id,
                'odometer' => $odometer,
                'previous_odo' => $unit->current_odo,
            ]);

            $insufficientData = $this->refreshUnitAfterInspectionChange($unit->refresh());

            $log->setAttribute('insufficient_data', $insufficientData);

            return $log->refresh()->setAttribute('insufficient_data', $insufficientData);
        });
    }

    public function cancelToday(InspectionLog $log): void
    {
        DB::transaction(function () use ($log): void {
            $unit = $log->unit()->lockForUpdate()->firstOrFail();
            $fallbackOdometer = $log->previous_odo;

            $log->delete();

            $this->refreshUnitAfterInspectionChange($unit->refresh(), true, $fallbackOdometer);
        });
    }

    private function refreshUnitAfterInspectionChange(Unit $unit, bool $pruneStaleTriggers = false, ?int $fallbackOdometer = null): bool
    {
        $logs = InspectionLog::query()
            ->where('unit_id', $unit->id)
            ->orderBy('inspection_date')
            ->orderBy('id')
            ->get(['inspection_date', 'odometer']);

        $latestLog = $logs->last();
        $minimumInspectionData = (int) (SystemThreshold::query()
            ->where('key', 'min_inspection_data')
            ->value('value') ?? 3);

        $insufficientData = $logs->count() < $minimumInspectionData;
        $averageKmPerDay = null;

        if (! $insufficientData) {
            $firstLog = $logs->first();
            $days = max(1, Carbon::parse($firstLog->inspection_date)->diffInDays(Carbon::parse($latestLog->inspection_date)));
            $averageKmPerDay = (int) round(($latestLog->odometer - $firstLog->odometer) / $days);
        }

        $unit->forceFill([
            'current_odo' => $latestLog?->odometer ?? $fallbackOdometer ?? $unit->current_odo,
            'avg_km_per_day' => $averageKmPerDay,
        ])->save();

        $unit->unitPlannings()
            ->with(['planningItem:id,interval_km,interval_days', 'unit'])
            ->get()
            ->each(function ($unitPlanning): void {
                $interval = $this->intervalResolver->resolve($unitPlanning->planningItem, $unitPlanning->unit);
                $nextDueKm = $unitPlanning->last_done_km + $interval['interval_km'];

                $unitPlanning->update([
                    'next_due_km' => max($unitPlanning->next_due_km, $nextDueKm),
                ]);
            });

        if ($pruneStaleTriggers) {
            $this->pruneStaleNormalTriggers($unit->refresh());
        }

        $this->maintenanceTriggerService->checkAndTrigger($unit->refresh());
        $this->highUsageService->detect($unit->refresh());

        return $insufficientData;
    }

    private function pruneStaleNormalTriggers(Unit $unit): void
    {
        $warningKm = (int) (SystemThreshold::query()->where('key', 'warning_km')->value('value') ?? 500);
        $warningDays = (int) (SystemThreshold::query()->where('key', 'warning_days')->value('value') ?? 7);
        $today = CarbonImmutable::today();

        $staleItems = WorkOrderItem::query()
            ->where('status', 'on_hold')
            ->whereHas('workOrder', fn ($query) => $query
                ->where('unit_id', $unit->id)
                ->where('trigger_type', 'normal')
                ->where('status', 'open'))
            ->with(['unitPlanning.planningItem', 'unitPlanning.unit', 'workOrder'])
            ->get()
            ->filter(function (WorkOrderItem $item) use ($unit, $warningKm, $warningDays, $today): bool {
                $planning = $item->unitPlanning;

                if (! $planning) {
                    return false;
                }

                $kmDueSoon = $planning->next_due_km !== null
                    && $unit->current_odo >= ($planning->next_due_km - $warningKm);
                $dateDueSoon = $planning->next_due_date !== null
                    && $today->greaterThanOrEqualTo(CarbonImmutable::parse($planning->next_due_date)->subDays($warningDays));

                return ! ($kmDueSoon || $dateDueSoon);
            });

        $workOrderIds = $staleItems->pluck('work_order_id')->unique();

        $staleItems->each->delete();

        WorkOrder::query()
            ->whereIn('id', $workOrderIds)
            ->whereDoesntHave('items')
            ->delete();
    }
}
