<?php

namespace App\Services;

use App\Models\InspectionLog;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InspectionService
{
    public function __construct(
        private MaintenanceTriggerService $maintenanceTriggerService,
        private BlockedBreakdownService $blockedBreakdownService,
        private HighUsageService $highUsageService,
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

            InspectionLog::query()->upsert(
                [[
                    'unit_id' => $unit->id,
                    'inspection_date' => $inspectionDate->toDateTimeString(),
                    'mechanic_id' => $mechanic->id,
                    'odometer' => $odometer,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['unit_id', 'inspection_date'],
                ['mechanic_id', 'odometer', 'updated_at'],
            );

            $log = InspectionLog::query()
                ->where('unit_id', $unit->id)
                ->where('inspection_date', $inspectionDate->toDateTimeString())
                ->firstOrFail();

            $unit->forceFill(['current_odo' => max($unit->current_odo, $odometer)])->save();

            $minimumInspectionData = (int) (SystemThreshold::query()
                ->where('key', 'min_inspection_data')
                ->value('value') ?? 3);

            $logs = InspectionLog::query()
                ->where('unit_id', $unit->id)
                ->orderBy('inspection_date')
                ->orderBy('id')
                ->get(['inspection_date', 'odometer']);

            $insufficientData = $logs->count() < $minimumInspectionData;
            $averageKmPerDay = null;

            if (! $insufficientData) {
                $firstLog = $logs->first();
                $lastLog = $logs->last();
                $days = max(1, Carbon::parse($firstLog->inspection_date)->diffInDays(Carbon::parse($lastLog->inspection_date)));
                $averageKmPerDay = (int) round(($lastLog->odometer - $firstLog->odometer) / $days);
            }

            $unit->forceFill(['avg_km_per_day' => $averageKmPerDay])->save();
            $unit->unitPlannings()
                ->with('planningItem:id,interval_km')
                ->get()
                ->each(function ($unitPlanning): void {
                    $unitPlanning->update([
                        'next_due_km' => $unitPlanning->last_done_km + $unitPlanning->planningItem->interval_km,
                    ]);
                });

            $log->setAttribute('insufficient_data', $insufficientData);

            $this->maintenanceTriggerService->checkAndTrigger($unit->refresh());
            $this->highUsageService->detect($unit->refresh());

            return $log->refresh()->setAttribute('insufficient_data', $insufficientData);
        });
    }
}
