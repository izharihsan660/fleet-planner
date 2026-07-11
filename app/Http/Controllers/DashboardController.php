<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\WorkOrderItem;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->hasRole(UserRole::Mekanik)) {
            return redirect()->route('mechanic.tasks');
        }

        return Inertia::render('Dashboard', [
            'overdueBanner' => [
                'threshold' => 20,
                'count' => WorkOrderItem::query()->where('status', 'overdue')->count(),
            ],
            'plannerDashboard' => $this->dashboardSummary($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardSummary(Request $request): ?array
    {
        $user = $request->user();

        if ($user?->hasRole(UserRole::PlannerArea)) {
            return $this->buildDashboardSummary($request, $user->region_id);
        }

        if (! $user?->isOneOf([UserRole::Superadmin, UserRole::SpvHo])) {
            return null;
        }

        $selectedRegionId = $request->integer('region_id') ?: null;

        if ($selectedRegionId !== null && ! Region::query()->whereKey($selectedRegionId)->exists()) {
            $selectedRegionId = null;
        }

        return $this->buildDashboardSummary($request, $selectedRegionId, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardSummary(Request $request, ?int $regionId = null, bool $canFilterRegion = false): array
    {
        $user = $request->user();
        $now = CarbonImmutable::now();
        $sites = Site::query()
            ->tap(fn (Builder $query) => AccessScope::applySiteListScope($query, $user))
            ->when($regionId !== null, fn (Builder $query) => $query->where('region_id', $regionId))
            ->withCount('units')
            ->orderBy('name')
            ->get(['id', 'name']);

        $statusCounts = [
            'on_hold' => $this->visibleItems($request, $regionId)->where('status', 'on_hold')->count(),
            'waiting_approval' => $this->visibleItems($request, $regionId)->whereIn('status', ['replace', 'postpone', 'pending_create'])->count(),
            'in_progress' => $this->visibleItems($request, $regionId)->where('status', 'in_progress')->count(),
            'complete_this_month' => $this->visibleItems($request, $regionId)
                ->where('status', 'complete')
                ->whereMonth('completed_date', $now->month)
                ->whereYear('completed_date', $now->year)
                ->count(),
            'overdue' => $this->visibleItems($request, $regionId)->where('status', 'overdue')->count(),
        ];

        $inputTodayBySite = Unit::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->whereHas('inspectionLogs', fn (Builder $query) => $query->whereDate('inspection_date', $now->toDateString()))
            ->selectRaw('site_id, COUNT(*) as aggregate')
            ->groupBy('site_id')
            ->pluck('aggregate', 'site_id');

        $siteRows = $sites->map(function (Site $site) use ($inputTodayBySite): array {
            return [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'unit_count' => $site->units_count,
                'km_input_count' => (int) ($inputTodayBySite[$site->id] ?? 0),
                'overdue_count' => WorkOrderItem::query()
                    ->where('status', 'overdue')
                    ->whereHas('workOrder', fn (Builder $query) => $query->where('site_id', $site->id))
                    ->count(),
            ];
        })->values();

        $totalUnits = Unit::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->count();
        $unitsInputToday = (int) $inputTodayBySite->sum();

        return [
            'total_units' => $totalUnits,
            'km_input_today' => [
                'input_count' => $unitsInputToday,
                'total_units' => $totalUnits,
                'missing_count' => max(0, $totalUnits - $unitsInputToday),
                'percentage' => $totalUnits > 0 ? (int) round(($unitsInputToday / $totalUnits) * 100) : 0,
            ],
            'can_filter_region' => $canFilterRegion,
            'selected_region_id' => $regionId,
            'region_options' => $canFilterRegion ? Region::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Region $region): array => [
                    'id' => $region->id,
                    'name' => $region->name,
                ])
                ->values()
                ->all() : [],
            'status_counts' => $statusCounts,
            'status_chart' => [
                ['key' => 'on_hold', 'label' => 'On Hold', 'value' => $statusCounts['on_hold'], 'color' => 'var(--chart-2)'],
                ['key' => 'waiting_approval', 'label' => 'Menunggu Approval', 'value' => $statusCounts['waiting_approval'], 'color' => 'var(--chart-4)'],
                ['key' => 'in_progress', 'label' => 'In Progress', 'value' => $statusCounts['in_progress'], 'color' => 'var(--primary)'],
                ['key' => 'complete_this_month', 'label' => 'Complete Bulan Ini', 'value' => $statusCounts['complete_this_month'], 'color' => 'var(--chart-3)'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => $statusCounts['overdue'], 'color' => 'var(--destructive)'],
            ],
            'site_rows' => $siteRows,
            'overdue_by_site_chart' => $siteRows
                ->sortByDesc('overdue_count')
                ->map(fn (array $row): array => [
                    'site_name' => $row['site_name'],
                    'overdue_count' => $row['overdue_count'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function visibleItems(Request $request, ?int $regionId = null): Builder
    {
        $user = $request->user();

        return WorkOrderItem::query()
            ->whereHas('workOrder', function (Builder $query) use ($user, $regionId): void {
                AccessScope::applySiteScope($query, $user, 'work_orders.site_id');

                if ($regionId !== null) {
                    $query->whereHas('site', fn (Builder $siteQuery) => $siteQuery->where('region_id', $regionId));
                }
            });
    }
}
