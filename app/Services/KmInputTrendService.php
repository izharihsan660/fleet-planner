<?php

namespace App\Services;

use App\Models\InspectionLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KmInputTrendService
{
    public const DEFAULT_DASHBOARD_DAYS = 14;

    public const SUMMARY_DAYS = 7;

    public const SUMMARY_INTERVAL_KEY = 'km_input_summary_interval_days';

    public const DEFAULT_SUMMARY_INTERVAL_DAYS = 7;

    public const NOTIFICATION_TYPE = 'km_input_periodic_summary';

    /**
     * @param  array<int, int>|null  $siteIds
     * @return array<string, mixed>
     */
    public function summarize(?array $siteIds, int $days, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $days = max(1, $days);
        $chartStart = $today->subDays($days - 1);
        $comparisonStart = $today->subDays((self::SUMMARY_DAYS * 2) - 1);
        $queryStart = $chartStart->lessThan($comparisonStart) ? $chartStart : $comparisonStart;
        $dailyCounts = $this->dailyCounts($siteIds, $queryStart, $today);
        $series = collect(range(0, $days - 1))
            ->map(function (int $offset) use ($chartStart, $dailyCounts): array {
                $date = $chartStart->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'short_label' => $this->shortDateLabel($date),
                    'full_label' => $this->fullDateLabel($date),
                    'count' => (int) ($dailyCounts[$date->toDateString()] ?? 0),
                ];
            })
            ->values();

        $todayCount = (int) ($dailyCounts[$today->toDateString()] ?? 0);
        $yesterdayCount = (int) ($dailyCounts[$today->subDay()->toDateString()] ?? 0);
        $currentSevenDaysTotal = $this->totalForRange(
            $dailyCounts,
            $today->subDays(self::SUMMARY_DAYS - 1),
            $today
        );
        $previousSevenDaysTotal = $this->totalForRange(
            $dailyCounts,
            $today->subDays((self::SUMMARY_DAYS * 2) - 1),
            $today->subDays(self::SUMMARY_DAYS)
        );

        return [
            'selected_days' => $days,
            'period_start' => $chartStart->toDateString(),
            'period_end' => $today->toDateString(),
            'period_label' => $this->rangeLabel($chartStart, $today),
            'total_count' => (int) $series->sum('count'),
            'series' => $series->all(),
            'today_vs_yesterday' => $this->comparison($todayCount, $yesterdayCount),
            'this_week_vs_last_week' => $this->comparison($currentSevenDaysTotal, $previousSevenDaysTotal),
        ];
    }

    /**
     * @param  array<int, int>|null  $siteIds
     * @return Collection<string, int>
     */
    private function dailyCounts(?array $siteIds, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if ($siteIds !== null && $siteIds === []) {
            return collect();
        }

        return InspectionLog::query()
            ->when($siteIds !== null, fn (Builder $query) => $query->whereHas(
                'unit',
                fn (Builder $unitQuery) => $unitQuery->whereIn('site_id', $siteIds)
            ))
            ->whereBetween('inspection_date', [$start->startOfDay(), $end->endOfDay()])
            ->selectRaw('inspection_date, COUNT(*) as aggregate')
            ->groupBy('inspection_date')
            ->orderBy('inspection_date')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                CarbonImmutable::parse((string) $row->inspection_date)->toDateString() => (int) $row->aggregate,
            ]);
    }

    /**
     * @param  Collection<string, int>  $dailyCounts
     */
    private function totalForRange(Collection $dailyCounts, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $total = 0;

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $total += (int) ($dailyCounts[$date->toDateString()] ?? 0);
        }

        return $total;
    }

    /**
     * @return array{current: int, previous: int, delta: int, percentage_change: float|null}
     */
    private function comparison(int $current, int $previous): array
    {
        $delta = $current - $previous;

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'percentage_change' => $previous > 0 ? round(($delta / $previous) * 100, 1) : null,
        ];
    }

    private function shortDateLabel(CarbonImmutable $date): string
    {
        return sprintf('%d %s', $date->day, $this->shortMonthNames()[$date->month]);
    }

    private function fullDateLabel(CarbonImmutable $date): string
    {
        return sprintf(
            '%s, %d %s %d',
            $this->dayNames()[$date->dayOfWeekIso],
            $date->day,
            $this->monthNames()[$date->month],
            $date->year
        );
    }

    private function rangeLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return sprintf('%s sampai %s', $this->fullDateLabel($start), $this->fullDateLabel($end));
    }

    /**
     * @return array<int, string>
     */
    private function dayNames(): array
    {
        return [
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function shortMonthNames(): array
    {
        return [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agu',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function monthNames(): array
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];
    }
}
