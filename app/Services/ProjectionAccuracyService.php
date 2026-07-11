<?php

namespace App\Services;

use App\Models\InspectionLog;
use App\Models\SystemThreshold;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Laporan akurasi proyeksi, dipisah dua rapor supaya faktor eksternal
 * (part telat, approval lama) tidak mencemari penilaian rumus:
 *
 * 1. Rapor rumus  — tebakan tanggal due (direkonstruksi dari data KM saat task
 *    ter-trigger) vs tanggal odometer BENERAN menyentuh due KM.
 * 2. Rapor eksekusi — jatuh tempo vs tanggal selesai, diurai per tahap
 *    (menunggu approval, eksekusi) memakai cap waktu yang sudah ada.
 */
class ProjectionAccuracyService
{
    /**
     * @return array{
     *     formula: array{evaluated: int, not_measurable: int, avg_deviation_days: float|null, within_week_pct: int|null, rows: array<int, array<string, mixed>>},
     *     execution: array{evaluated: int, rows: array<int, array<string, mixed>>}
     * }
     */
    public function report(int $month, int $year, ?int $siteId, User $user): array
    {
        $items = $this->completedItems($month, $year, $siteId, $user);
        $logsByUnit = $this->logsByUnit($items);
        $windowDays = max(1, (int) (SystemThreshold::query()->where('key', 'rolling_window_days')->value('value') ?? 30));

        return [
            'formula' => $this->formulaReport($items, $logsByUnit, $windowDays),
            'execution' => $this->executionReport($items),
        ];
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    private function completedItems(int $month, int $year, ?int $siteId, User $user): Collection
    {
        return WorkOrderItem::query()
            ->with(['planningItem:id,name', 'workOrder:id,unit_id,site_id', 'workOrder.site:id,name'])
            ->where('status', 'complete')
            ->where('action', '!=', 'breakdown')
            ->whereNotNull('completed_date')
            ->whereMonth('completed_date', $month)
            ->whereYear('completed_date', $year)
            ->whereHas('workOrder', function (Builder $query) use ($siteId, $user): void {
                AccessScope::applySiteScope($query, $user, 'work_orders.site_id');

                if ($siteId !== null) {
                    $query->where('work_orders.site_id', $siteId);
                }
            })
            ->get();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return Collection<int, Collection<int, InspectionLog>>
     */
    private function logsByUnit(Collection $items): Collection
    {
        $unitIds = $items->map(fn (WorkOrderItem $item): ?int => $item->workOrder?->unit_id)->filter()->unique()->values();

        return InspectionLog::query()
            ->whereIn('unit_id', $unitIds)
            ->orderBy('inspection_date')
            ->orderBy('id')
            ->get(['unit_id', 'inspection_date', 'odometer'])
            ->groupBy('unit_id');
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @param  Collection<int, Collection<int, InspectionLog>>  $logsByUnit
     * @return array{evaluated: int, not_measurable: int, avg_deviation_days: float|null, within_week_pct: int|null, rows: array<int, array<string, mixed>>}
     */
    private function formulaReport(Collection $items, Collection $logsByUnit, int $windowDays): array
    {
        $measurements = $items
            ->map(function (WorkOrderItem $item) use ($logsByUnit, $windowDays): ?array {
                $deviation = $this->formulaDeviationDays($item, $logsByUnit, $windowDays);

                if ($deviation === null) {
                    return null;
                }

                return [
                    'item_name' => $item->planningItem?->name ?? '-',
                    'deviation' => $deviation,
                ];
            })
            ->filter()
            ->values();

        $rows = $measurements
            ->groupBy('item_name')
            ->map(fn (Collection $group, string $name): array => [
                'item_name' => $name,
                'evaluated' => $group->count(),
                'avg_deviation_days' => round($group->avg('deviation'), 1),
                'within_week_pct' => (int) round($group->filter(fn (array $row): bool => abs($row['deviation']) <= 7)->count() / $group->count() * 100),
            ])
            ->sortBy('item_name')
            ->values()
            ->all();

        return [
            'evaluated' => $measurements->count(),
            'not_measurable' => $items->count() - $measurements->count(),
            'avg_deviation_days' => $measurements->isNotEmpty() ? round($measurements->avg('deviation'), 1) : null,
            'within_week_pct' => $measurements->isNotEmpty()
                ? (int) round($measurements->filter(fn (array $row): bool => abs($row['deviation']) <= 7)->count() / $measurements->count() * 100)
                : null,
            'rows' => $rows,
        ];
    }

    /**
     * Rekonstruksi tebakan: pakai data KM yang tersedia SAAT task ter-trigger
     * (created_at), proyeksikan kapan due KM tercapai, lalu bandingkan dengan
     * tanggal log odometer pertama yang menyentuh due KM tersebut.
     */
    private function formulaDeviationDays(WorkOrderItem $item, Collection $logsByUnit, int $windowDays): ?int
    {
        $dueKm = $item->previous_due_km;
        $unitId = $item->workOrder?->unit_id;

        if ($dueKm === null || $unitId === null) {
            return null;
        }

        /** @var Collection<int, InspectionLog> $logs */
        $logs = $logsByUnit->get($unitId, collect());
        $referenceDate = CarbonImmutable::parse($item->created_at)->startOfDay();

        $logsUntilReference = $logs->filter(
            fn (InspectionLog $log): bool => CarbonImmutable::parse($log->inspection_date)->lessThanOrEqualTo($referenceDate)
        )->values();

        if ($logsUntilReference->isEmpty()) {
            return null;
        }

        $windowStart = $referenceDate->subDays($windowDays);
        $windowLogs = $logsUntilReference->filter(
            fn (InspectionLog $log): bool => CarbonImmutable::parse($log->inspection_date)->greaterThanOrEqualTo($windowStart)
        )->values();

        if ($windowLogs->count() < 2) {
            $windowLogs = $logsUntilReference;
        }

        if ($windowLogs->count() < 2) {
            return null;
        }

        $first = $windowLogs->first();
        $last = $windowLogs->last();
        $elapsedDays = max(1, (int) CarbonImmutable::parse($first->inspection_date)->diffInDays(CarbonImmutable::parse($last->inspection_date)));
        $averageKmPerDay = ($last->odometer - $first->odometer) / $elapsedDays;

        if ($averageKmPerDay <= 0) {
            return null;
        }

        $referenceOdometer = $last->odometer;
        $predictedDate = $referenceDate->addDays((int) ceil(max(0, $dueKm - $referenceOdometer) / $averageKmPerDay));

        $crossingLog = $logs->first(fn (InspectionLog $log): bool => $log->odometer >= $dueKm);

        if ($crossingLog === null) {
            return null;
        }

        $actualDate = CarbonImmutable::parse($crossingLog->inspection_date)->startOfDay();

        return (int) round(($actualDate->getTimestamp() - $predictedDate->getTimestamp()) / 86400);
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return array{evaluated: int, rows: array<int, array<string, mixed>>}
     */
    private function executionReport(Collection $items): array
    {
        $measurements = $items
            ->map(function (WorkOrderItem $item): ?array {
                if ($item->previous_due_date === null) {
                    return null;
                }

                $dueDate = CarbonImmutable::parse($item->previous_due_date)->startOfDay();
                $completedDate = CarbonImmutable::parse($item->completed_date)->startOfDay();
                $createdDate = CarbonImmutable::parse($item->created_at)->startOfDay();
                $approvedDate = $item->approved_at !== null ? CarbonImmutable::parse($item->approved_at)->startOfDay() : null;

                return [
                    'site_name' => $item->workOrder?->site?->name ?? '-',
                    'late_days' => (int) round(($completedDate->getTimestamp() - $dueDate->getTimestamp()) / 86400),
                    'approval_days' => $approvedDate !== null ? max(0, (int) round(($approvedDate->getTimestamp() - $createdDate->getTimestamp()) / 86400)) : null,
                    'execution_days' => $approvedDate !== null ? max(0, (int) round(($completedDate->getTimestamp() - $approvedDate->getTimestamp()) / 86400)) : null,
                ];
            })
            ->filter()
            ->values();

        $rows = $measurements
            ->groupBy('site_name')
            ->map(fn (Collection $group, string $name): array => [
                'site_name' => $name,
                'evaluated' => $group->count(),
                'avg_late_days' => round($group->avg('late_days'), 1),
                'avg_approval_days' => $group->pluck('approval_days')->filter(fn ($value) => $value !== null)->avg() !== null
                    ? round($group->pluck('approval_days')->filter(fn ($value) => $value !== null)->avg(), 1)
                    : null,
                'avg_execution_days' => $group->pluck('execution_days')->filter(fn ($value) => $value !== null)->avg() !== null
                    ? round($group->pluck('execution_days')->filter(fn ($value) => $value !== null)->avg(), 1)
                    : null,
            ])
            ->sortBy('site_name')
            ->values()
            ->all();

        return [
            'evaluated' => $measurements->count(),
            'rows' => $rows,
        ];
    }
}
