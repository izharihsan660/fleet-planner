<?php

namespace App\Services;

use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProjectionService
{
    /**
     * @return array{period_months: int, period_end: string, by_unit: array<int, array<string, mixed>>, by_item: array<int, array<string, mixed>>, by_part: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function calculate(int $months, ?int $siteId = null): array
    {
        $periodStart = CarbonImmutable::today();
        $periodEnd = $periodStart->addMonthsNoOverflow($months);
        $remainingDays = max(0, $periodStart->diffInDays($periodEnd));
        $minimumInspectionData = (int) (SystemThreshold::query()
            ->where('key', 'min_inspection_data')
            ->value('value') ?? 0);

        $units = Unit::query()
            ->with([
                'site:id,name,region',
                'inspectionLogs:id,unit_id,inspection_date,odometer',
                'unitPlannings.planningItem:id,name,interval_km,interval_days',
            ])
            ->when($siteId !== null, fn ($query) => $query->where('site_id', $siteId))
            ->orderBy('current_plate')
            ->get();

        $byUnit = [];
        $flatItems = collect();
        $warnings = [];

        foreach ($units as $unit) {
            $inspectionCount = $unit->inspectionLogs->count();
            $insufficientData = $inspectionCount < $minimumInspectionData;
            $averageKmPerDay = $this->averageKmPerDay($unit->inspectionLogs);
            $estimatedPeriodOdometer = (int) round($unit->current_odo + ($averageKmPerDay * $remainingDays));
            $unitItems = collect();

            foreach ($unit->unitPlannings as $unitPlanning) {
                if (! $this->isDueInPeriod($unitPlanning, $estimatedPeriodOdometer, $periodEnd)) {
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
    private function averageKmPerDay(Collection $inspectionLogs): float
    {
        $logs = $inspectionLogs->sortBy('inspection_date')->values();

        if ($logs->count() < 2) {
            return 0.0;
        }

        $first = $logs->first();
        $last = $logs->last();
        $days = max(1, CarbonImmutable::parse($first->inspection_date)->diffInDays(CarbonImmutable::parse($last->inspection_date)));

        return round(max(0, $last->odometer - $first->odometer) / $days, 2);
    }

    private function isDueInPeriod(UnitPlanning $unitPlanning, int $estimatedPeriodOdometer, CarbonImmutable $periodEnd): bool
    {
        $dueByKm = $unitPlanning->next_due_km !== null && $unitPlanning->next_due_km <= $estimatedPeriodOdometer;
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
}
