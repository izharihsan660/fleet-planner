<?php

namespace App\Services;

use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProjectionService
{
    /**
     * @return array{period_months: int, period_end: string, by_unit: array<int, array<string, mixed>>, by_item: array<int, array<string, mixed>>, by_part: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function calculate(int $months, ?int $siteId = null, ?int $regionId = null): array
    {
        $periodStart = CarbonImmutable::today();
        $periodEnd = $periodStart->addMonthsNoOverflow($months);
        $remainingDays = max(0, $periodStart->diffInDays($periodEnd));

        $thresholds = SystemThreshold::query()
            ->whereIn('key', ['min_inspection_data', 'rolling_window_days'])
            ->pluck('value', 'key');

        $minimumInspectionData = (int) ($thresholds->get('min_inspection_data') ?? 0);
        $rollingWindowDays = max(1, (int) ($thresholds->get('rolling_window_days') ?? 30));

        $units = Unit::query()
            ->with([
                'site:id,name,region',
                'inspectionLogs:id,unit_id,inspection_date,odometer,previous_odo',
                'unitPlannings.planningItem:id,name,interval_km,interval_days',
            ])
            ->when($siteId !== null, fn ($query) => $query->where('site_id', $siteId))
            ->when($siteId === null && $regionId !== null, fn ($query) => $query->whereHas('site', fn ($siteQuery) => $siteQuery->where('region_id', $regionId)))
            ->orderBy('current_plate')
            ->get();

        $byUnit = [];
        $flatItems = collect();
        $warnings = [];

        foreach ($units as $unit) {
            $inspectionCount = $unit->inspectionLogs->count();
            $hasProjectionOdometerData = $unit->has_odometer_reading && $inspectionCount >= $minimumInspectionData;
            $insufficientData = ! $hasProjectionOdometerData;
            $averageKmPerDay = $hasProjectionOdometerData ? $this->averageKmPerDayForProjection($unit->id, $unit->inspectionLogs, $rollingWindowDays) : 0.0;
            $estimatedPeriodOdometer = (int) round($unit->current_odo + ($averageKmPerDay * $remainingDays));
            $unitItems = collect();

            foreach ($unit->unitPlannings as $unitPlanning) {
                if (! $this->isDueInPeriod($unitPlanning, $estimatedPeriodOdometer, $periodEnd, $hasProjectionOdometerData)) {
                    continue;
                }

                $estimatedDueDate = $this->estimatedDueDate($unitPlanning, $unit->current_odo, $averageKmPerDay, $periodEnd);
                $estimatedDueKm = $unitPlanning->next_due_km ?? $estimatedPeriodOdometer;

                $projectionItem = [
                    'unit_id' => $unit->id,
                    'unit_planning_id' => $unitPlanning->id,
                    'planning_item_id' => $unitPlanning->planning_item_id,
                    'planning_item_name' => $unitPlanning->planningItem?->name ?? '-',
                    'plate_number' => $unit->current_plate,
                    'site_id' => $unit->site_id,
                    'site_name' => $unit->site?->name ?? '-',
                    'estimated_due_date' => $estimatedDueDate,
                    'estimated_due_km' => $estimatedDueKm,
                    'estimated_quantity' => 1,
                    'insufficient_data' => $insufficientData,
                    'data_status_message' => $insufficientData ? $this->insufficientDataMessage() : null,
                ];

                $unitItems->push($projectionItem);
                $flatItems->push($projectionItem);
            }

            if ($insufficientData) {
                $warnings[] = [
                    'unit_id' => $unit->id,
                    'plate_number' => $unit->current_plate,
                    'site_name' => $unit->site?->name ?? '-',
                    'inspection_count' => $inspectionCount,
                    'minimum_required' => $minimumInspectionData,
                    'has_odometer_reading' => $unit->has_odometer_reading,
                    'message' => $this->insufficientDataMessage(),
                ];
            }

            if ($unitItems->isNotEmpty()) {
                $byUnit[] = [
                    'unit_id' => $unit->id,
                    'plate_number' => $unit->current_plate,
                    'site_id' => $unit->site_id,
                    'site_name' => $unit->site?->name ?? '-',
                    'current_odo' => $unit->current_odo,
                    'avg_km_per_day' => $averageKmPerDay,
                    'estimated_period_odo' => $estimatedPeriodOdometer,
                    'insufficient_data' => $insufficientData,
                    'data_status_message' => $insufficientData ? $this->insufficientDataMessage() : null,
                    'items' => $unitItems->values()->all(),
                ];
            }
        }

        return [
            'period_months' => $months,
            'period_end' => $periodEnd->toDateString(),
            'by_unit' => $byUnit,
            'by_item' => $this->groupByPlanningItem($flatItems),
            'by_part' => $this->groupByPlanningItem($flatItems),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $inspectionLogs
     */
    private function averageKmPerDayForProjection(int $unitId, Collection $inspectionLogs, int $windowDays): float
    {
        $logs = $inspectionLogs->sortBy('inspection_date')->values();

        if ($logs->count() < 2) {
            return 0.0;
        }

        $today = CarbonImmutable::today();
        $windowStart = $today->subDays($windowDays);

        $windowLogs = $logs->filter(
            fn ($log): bool => CarbonImmutable::parse($log->inspection_date)->greaterThanOrEqualTo($windowStart)
        )->values();

        if ($windowLogs->count() < 2) {
            $windowLogs = $logs;
        }

        if ($windowLogs->count() < 2) {
            return 0.0;
        }

        $first = $windowLogs->first();
        $last = $windowLogs->last();
        $totalKm = max(0, $last->odometer - $first->odometer);

        $firstDate = CarbonImmutable::parse($first->inspection_date);
        $lastDate = CarbonImmutable::parse($last->inspection_date);
        $totalDays = max(1, $firstDate->diffInDays($lastDate));

        $blockedDays = $this->countBlockedOrBreakdownDays($unitId, $firstDate, $lastDate);
        $effectiveDays = max(1, $totalDays - $blockedDays);

        return round($totalKm / $effectiveDays, 2);
    }

    private function countBlockedOrBreakdownDays(int $unitId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $items = WorkOrderItem::query()
            ->whereHas('workOrder', fn ($query) => $query->where('unit_id', $unitId))
            ->whereIn('status', ['blocked', 'breakdown', 'complete'])
            ->whereIn('action', ['blocked', 'breakdown'])
            ->whereNotNull('freeze_start')
            ->get(['freeze_start', 'freeze_end']);

        $intervals = [];

        foreach ($items as $item) {
            $freezeStart = CarbonImmutable::parse($item->freeze_start)->startOfDay();
            $freezeEnd = $item->freeze_end
                ? CarbonImmutable::parse($item->freeze_end)->startOfDay()
                : $to;

            $overlapStart = $freezeStart->max($from);
            $overlapEnd = $freezeEnd->min($to);

            if ($overlapStart->lessThan($overlapEnd)) {
                $intervals[] = [$overlapStart, $overlapEnd];
            }
        }

        if ($intervals === []) {
            return 0;
        }

        usort($intervals, fn (array $first, array $second): int => $first[0]->lessThan($second[0]) ? -1 : 1);

        $days = 0;
        [$currentStart, $currentEnd] = array_shift($intervals);

        foreach ($intervals as [$start, $end]) {
            if ($start->lessThanOrEqualTo($currentEnd)) {
                $currentEnd = $end->greaterThan($currentEnd) ? $end : $currentEnd;

                continue;
            }

            $days += (int) $currentStart->diffInDays($currentEnd);
            [$currentStart, $currentEnd] = [$start, $end];
        }

        $days += (int) $currentStart->diffInDays($currentEnd);

        return $days;
    }

    private function isDueInPeriod(UnitPlanning $unitPlanning, int $estimatedPeriodOdometer, CarbonImmutable $periodEnd, bool $canUseOdometerProjection): bool
    {
        $dueByKm = $canUseOdometerProjection && $unitPlanning->next_due_km !== null && $unitPlanning->next_due_km <= $estimatedPeriodOdometer;
        $dueByDate = $unitPlanning->next_due_date !== null && CarbonImmutable::parse($unitPlanning->next_due_date)->lessThanOrEqualTo($periodEnd);

        return $dueByKm || $dueByDate;
    }

    private function estimatedDueDate(UnitPlanning $unitPlanning, int $currentOdometer, float $averageKmPerDay, CarbonImmutable $periodEnd): ?string
    {
        $dateByKm = null;

        if ($unitPlanning->next_due_km !== null && $averageKmPerDay > 0) {
            $remainingKm = max(0, $unitPlanning->next_due_km - $currentOdometer);
            $dateByKm = CarbonImmutable::today()->addDays((int) ceil($remainingKm / $averageKmPerDay));
        }

        $dateBySchedule = $unitPlanning->next_due_date !== null
            ? CarbonImmutable::parse($unitPlanning->next_due_date)
            : null;

        $dates = collect([$dateByKm, $dateBySchedule])
            ->filter(fn (?CarbonImmutable $date): bool => $date !== null)
            ->sort();

        return $dates->first()?->min($periodEnd)->toDateString();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function groupByPlanningItem(Collection $items): array
    {
        return $items
            ->groupBy('planning_item_id')
            ->map(fn (Collection $group): array => [
                'planning_item_id' => $group->first()['planning_item_id'],
                'planning_item_name' => $group->first()['planning_item_name'],
                'total_estimated_quantity' => $group->sum('estimated_quantity'),
                'items' => $group->values()->all(),
            ])
            ->sortBy('planning_item_name')
            ->values()
            ->all();
    }

    private function insufficientDataMessage(): string
    {
        return 'Data KM belum tersedia — menunggu input mekanik';
    }
}
