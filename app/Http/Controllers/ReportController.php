<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Exports\ReportExport;
use App\Http\Requests\ReportFilterRequest;
use App\Http\Resources\ReportSummaryResource;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(ReportFilterRequest $request): Response
    {
        Gate::authorize('view-reports');

        $filters = $this->filters($request);

        return Inertia::render('Reports/Index', [
            'summary' => ReportSummaryResource::make([
                'total_wo' => $this->visibleWorkOrders($request)->count(),
                'total_items' => $this->visibleWorkOrderItems($request)->count(),
                'total_complete' => $this->visibleWorkOrderItems($request)->where('status', 'complete')->count(),
                'total_overdue' => $this->visibleWorkOrderItems($request)->where('status', 'overdue')->count(),
            ])->resolve(),
            'woSummary' => Gate::allows('view-reports.wo-summary') ? $this->paginatedReport($this->woSummaryData($request, $filters)) : $this->emptyReportPage(),
            'byItem' => Gate::allows('view-reports.by-item') ? $this->paginatedReport($this->byItemData($request, $filters)) : $this->emptyReportPage(),
            'byUnit' => Gate::allows('view-reports.by-unit') ? $this->paginatedReport($this->byUnitData($request, $filters)) : $this->emptyReportPage(),
            'overdueByArea' => Gate::allows('view-reports.overdue') ? $this->paginatedReport($this->overdueByAreaData($request, $filters)) : $this->emptyReportPage(),
            'sites' => SiteResource::collection($this->visibleSites($request)),
            'filters' => $filters,
            'permissions' => [
                'can_filter_site' => $request->user()->isOneOf([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea]),
                'can_view_wo_summary' => Gate::allows('view-reports.wo-summary'),
                'can_view_by_item' => Gate::allows('view-reports.by-item'),
                'can_view_by_unit' => Gate::allows('view-reports.by-unit'),
                'can_view_overdue' => Gate::allows('view-reports.overdue'),
                'default_tab' => $filters['tab'],
            ],
        ]);
    }

    public function woSummary(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.wo-summary');

        return $this->paginatedReport($this->woSummaryData($request, $this->filters($request)));
    }

    public function byItem(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.by-item');

        return $this->paginatedReport($this->byItemData($request, $this->filters($request)));
    }

    public function byUnit(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.by-unit');

        return $this->paginatedReport($this->byUnitData($request, $this->filters($request)));
    }

    public function overdueByArea(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.overdue');

        return $this->paginatedReport($this->overdueByAreaData($request, $this->filters($request)));
    }

    public function export(ReportFilterRequest $request, string $tab): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $tab = in_array($tab, ['wo', 'item', 'unit', 'overdue'], true) ? $tab : 'wo';

        Gate::authorize(match ($tab) {
            'wo' => 'view-reports.wo-summary',
            'item' => 'view-reports.by-item',
            'unit' => 'view-reports.by-unit',
            'overdue' => 'view-reports.overdue',
        });

        [$label, $headings, $rows] = match ($tab) {
            'wo' => ['rekap-wo', ['Lokasi', 'Total WO', 'Total Item', 'Selesai', 'Terlambat', 'Sedang Dikerjakan'], $this->woSummaryRows($request, $filters)],
            'item' => ['per-item', ['Item', 'Total WO', 'Selesai', 'Terlambat', 'Avg Hari Penyelesaian'], $this->byItemRows($request, $filters)],
            'unit' => ['per-unit', ['Plat Nomor', 'Lokasi', 'Total WO', 'Selesai', 'Terlambat'], $this->byUnitRows($request, $filters)],
            'overdue' => ['terlambat', ['Lokasi', 'Total Terlambat', 'Item Terlambat'], $this->overdueByAreaRows($request, $filters)],
        };

        $filename = sprintf('laporan-%s-%04d-%02d.xlsx', $label, $filters['year'], $filters['month']);

        return Excel::download(new ReportExport($headings, $rows), $filename);
    }

    /**
     * @return array{month: int, year: int, site_id: int|null, tab: 'wo'|'item'|'unit'|'overdue'}
     */
    private function filters(ReportFilterRequest $request): array
    {
        $validated = $request->validated();
        $user = $request->user();
        $canFilterSite = $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea]);

        return [
            'month' => (int) ($validated['month'] ?? now()->month),
            'year' => (int) ($validated['year'] ?? now()->year),
            'site_id' => $canFilterSite ? ($validated['site_id'] ?? null) : null,
            'tab' => $validated['tab'] ?? 'wo',
        ];
    }

    private function visibleWorkOrders(ReportFilterRequest $request): Builder
    {
        $user = $request->user();

        return WorkOrder::query()
            ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $user, 'work_orders.site_id'))
            ->when($user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->whereHas('items', fn (Builder $itemQuery) => $itemQuery->where('work_order_items.submitted_by', $user->id)));
    }

    private function visibleWorkOrderItems(ReportFilterRequest $request): Builder
    {
        $user = $request->user();

        return WorkOrderItem::query()
            ->whereHas('workOrder', function (Builder $query) use ($user): void {
                AccessScope::applySiteScope($query, $user, 'work_orders.site_id');
            })
            ->when($user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->where('work_order_items.submitted_by', $user->id));
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function woSummaryData(ReportFilterRequest $request, array $filters): LengthAwarePaginator
    {
        return $this->woSummaryQuery($request, $filters)
            ->paginate(25, ['*'], 'wo_page')
            ->withQueryString();
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     */
    private function woSummaryQuery(ReportFilterRequest $request, array $filters): Builder
    {
        return $this->visibleWorkOrders($request)
            ->leftJoin('work_order_items', 'work_order_items.work_order_id', '=', 'work_orders.id')
            ->leftJoin('sites', 'sites.id', '=', 'work_orders.site_id')
            ->selectRaw('sites.name as site')
            ->selectRaw('COUNT(DISTINCT work_orders.id) as total_wo')
            ->selectRaw('COUNT(work_order_items.id) as total_item')
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'complete' THEN 1 ELSE 0 END) as complete")
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'overdue' THEN 1 ELSE 0 END) as overdue")
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
            ->whereMonth('work_orders.created_at', $filters['month'])
            ->whereYear('work_orders.created_at', $filters['year'])
            ->when($filters['site_id'], fn (Builder $query, int $siteId) => $query->where('work_orders.site_id', $siteId))
            ->groupBy('work_orders.site_id', 'sites.name')
            ->orderBy('sites.name');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byItemData(ReportFilterRequest $request, array $filters): LengthAwarePaginator
    {
        return $this->byItemQuery($request, $filters)
            ->paginate(25, ['*'], 'item_page')
            ->withQueryString();
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     */
    private function byItemQuery(ReportFilterRequest $request, array $filters): Builder
    {
        return $this->visibleWorkOrderItems($request)
            ->join('work_orders', 'work_orders.id', '=', 'work_order_items.work_order_id')
            ->join('planning_items', 'planning_items.id', '=', 'work_order_items.planning_item_id')
            ->selectRaw('planning_items.name as item')
            ->selectRaw('COUNT(work_order_items.id) as total_wo')
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'complete' THEN 1 ELSE 0 END) as total_complete")
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'overdue' THEN 1 ELSE 0 END) as total_overdue")
            ->selectRaw("ROUND(AVG(CASE WHEN work_order_items.status = 'complete' AND work_order_items.completed_date IS NOT NULL THEN julianday(work_order_items.completed_date) - julianday(work_order_items.created_at) END), 1) as avg_hari_penyelesaian")
            ->whereMonth('work_orders.created_at', $filters['month'])
            ->whereYear('work_orders.created_at', $filters['year'])
            ->when($filters['site_id'], fn (Builder $query, int $siteId) => $query->where('work_orders.site_id', $siteId))
            ->groupBy('work_order_items.planning_item_id', 'planning_items.name')
            ->orderBy('planning_items.name');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byUnitData(ReportFilterRequest $request, array $filters): LengthAwarePaginator
    {
        return $this->byUnitQuery($request, $filters)
            ->paginate(25, ['*'], 'unit_page')
            ->withQueryString();
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     */
    private function byUnitQuery(ReportFilterRequest $request, array $filters): Builder
    {
        return $this->visibleWorkOrders($request)
            ->leftJoin('work_order_items', 'work_order_items.work_order_id', '=', 'work_orders.id')
            ->leftJoin('units', 'units.id', '=', 'work_orders.unit_id')
            ->leftJoin('sites', 'sites.id', '=', 'work_orders.site_id')
            ->selectRaw('work_orders.unit_id as unit_id')
            ->selectRaw('units.current_plate as plat_nomor')
            ->selectRaw('sites.name as site')
            ->selectRaw('COUNT(DISTINCT work_orders.id) as total_wo')
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'complete' THEN 1 ELSE 0 END) as total_complete")
            ->selectRaw("SUM(CASE WHEN work_order_items.status = 'overdue' THEN 1 ELSE 0 END) as total_overdue")
            ->whereMonth('work_orders.created_at', $filters['month'])
            ->whereYear('work_orders.created_at', $filters['year'])
            ->when($filters['site_id'], fn (Builder $query, int $siteId) => $query->where('work_orders.site_id', $siteId))
            ->groupBy('work_orders.unit_id', 'units.current_plate', 'sites.name')
            ->orderBy('units.current_plate');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueByAreaData(ReportFilterRequest $request, array $filters): LengthAwarePaginator
    {
        return $this->overdueByAreaQuery($request, $filters)
            ->paginate(25, ['*'], 'overdue_page')
            ->withQueryString();
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     */
    private function overdueByAreaQuery(ReportFilterRequest $request, array $filters): Builder
    {
        return $this->visibleWorkOrderItems($request)
            ->join('work_orders', 'work_orders.id', '=', 'work_order_items.work_order_id')
            ->join('sites', 'sites.id', '=', 'work_orders.site_id')
            ->join('planning_items', 'planning_items.id', '=', 'work_order_items.planning_item_id')
            ->selectRaw('sites.name as site')
            ->selectRaw('COUNT(work_order_items.id) as total_overdue')
            ->selectRaw('GROUP_CONCAT(DISTINCT planning_items.name) as items')
            ->where('work_order_items.status', 'overdue')
            ->whereMonth('work_orders.created_at', $filters['month'])
            ->whereYear('work_orders.created_at', $filters['year'])
            ->when($filters['site_id'], fn (Builder $query, int $siteId) => $query->where('work_orders.site_id', $siteId))
            ->groupBy('work_orders.site_id', 'sites.name')
            ->orderBy('sites.name');
    }

    private function paginatedReport(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => ReportSummaryResource::collection(collect($paginator->items())->map(function ($item): array {
                $row = $item instanceof Model ? $item->getAttributes() : (array) $item;

                if (isset($row['items']) && is_string($row['items'])) {
                    $row['items'] = array_values(array_filter(explode(',', $row['items'])));
                }

                return $row;
            }))->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => $paginator->linkCollection()->toArray(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     * @return Collection<int, array<int, mixed>>
     */
    private function woSummaryRows(ReportFilterRequest $request, array $filters): Collection
    {
        return $this->woSummaryQuery($request, $filters)->get()->map(fn ($row): array => [
            $row->site,
            (int) $row->total_wo,
            (int) $row->total_item,
            (int) $row->complete,
            (int) $row->overdue,
            (int) $row->in_progress,
        ]);
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     * @return Collection<int, array<int, mixed>>
     */
    private function byItemRows(ReportFilterRequest $request, array $filters): Collection
    {
        return $this->byItemQuery($request, $filters)->get()->map(fn ($row): array => [
            $row->item,
            (int) $row->total_wo,
            (int) $row->total_complete,
            (int) $row->total_overdue,
            $row->avg_hari_penyelesaian,
        ]);
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     * @return Collection<int, array<int, mixed>>
     */
    private function byUnitRows(ReportFilterRequest $request, array $filters): Collection
    {
        return $this->byUnitQuery($request, $filters)->get()->map(fn ($row): array => [
            $row->plat_nomor,
            $row->site,
            (int) $row->total_wo,
            (int) $row->total_complete,
            (int) $row->total_overdue,
        ]);
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     * @return Collection<int, array<int, mixed>>
     */
    private function overdueByAreaRows(ReportFilterRequest $request, array $filters): Collection
    {
        return $this->overdueByAreaQuery($request, $filters)->get()->map(fn ($row): array => [
            $row->site,
            (int) $row->total_overdue,
            collect(explode(',', (string) $row->items))->filter()->implode(', '),
        ]);
    }

    private function emptyReportPage(): array
    {
        return [
            'data' => [],
            'meta' => ['current_page' => 1, 'from' => null, 'last_page' => 1, 'links' => [], 'per_page' => 25, 'to' => null, 'total' => 0],
        ];
    }

    private function visibleSites(ReportFilterRequest $request)
    {
        $user = $request->user();

        return Site::query()
            ->tap(fn (Builder $query) => AccessScope::applySiteListScope($query, $user))
            ->orderBy('name')
            ->get();
    }
}
