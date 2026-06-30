<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ReportFilterRequest;
use App\Http\Resources\ReportSummaryResource;
use App\Http\Resources\SiteResource;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

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
            'woSummary' => Gate::allows('view-reports.wo-summary') ? $this->woSummaryData($request, $filters) : [],
            'byItem' => Gate::allows('view-reports.by-item') ? $this->byItemData($request) : [],
            'byUnit' => Gate::allows('view-reports.by-unit') ? $this->byUnitData($request) : [],
            'overdueByArea' => Gate::allows('view-reports.overdue') ? $this->overdueByAreaData($request) : [],
            'sites' => SiteResource::collection($this->visibleSites($request)),
            'filters' => $filters,
            'permissions' => [
                'can_filter_site' => $request->user()->isOneOf([UserRole::Superadmin, UserRole::PlannerHo, UserRole::SpvOps]),
                'can_view_wo_summary' => Gate::allows('view-reports.wo-summary'),
                'can_view_by_item' => Gate::allows('view-reports.by-item'),
                'can_view_by_unit' => Gate::allows('view-reports.by-unit'),
                'can_view_overdue' => Gate::allows('view-reports.overdue'),
                'default_tab' => $request->user()->hasRole(UserRole::Logistik) ? 'item' : 'wo',
            ],
        ]);
    }

    public function woSummary(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.wo-summary');

        return ReportSummaryResource::collection($this->woSummaryData($request, $this->filters($request)))->resolve();
    }

    public function byItem(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.by-item');

        return ReportSummaryResource::collection($this->byItemData($request))->resolve();
    }

    public function byUnit(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.by-unit');

        return ReportSummaryResource::collection($this->byUnitData($request))->resolve();
    }

    public function overdueByArea(ReportFilterRequest $request): array
    {
        Gate::authorize('view-reports.overdue');

        return ReportSummaryResource::collection($this->overdueByAreaData($request))->resolve();
    }

    /**
     * @return array{month: int, year: int, site_id: int|null}
     */
    private function filters(ReportFilterRequest $request): array
    {
        $validated = $request->validated();
        $user = $request->user();
        $canFilterSite = $user->isOneOf([UserRole::Superadmin, UserRole::PlannerHo, UserRole::SpvOps]);

        return [
            'month' => (int) ($validated['month'] ?? now()->month),
            'year' => (int) ($validated['year'] ?? now()->year),
            'site_id' => $user->hasRole(UserRole::AdminSite) ? $user->site_id : ($canFilterSite ? ($validated['site_id'] ?? null) : null),
        ];
    }

    private function visibleWorkOrders(ReportFilterRequest $request): Builder
    {
        $user = $request->user();

        return WorkOrder::query()
            ->when($user->hasRole(UserRole::AdminSite), fn (Builder $query) => $query->where('site_id', $user->site_id))
            ->when($user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->whereHas('items', fn (Builder $itemQuery) => $itemQuery->where('submitted_by', $user->id)));
    }

    private function visibleWorkOrderItems(ReportFilterRequest $request): Builder
    {
        $user = $request->user();

        return WorkOrderItem::query()
            ->whereHas('workOrder', function (Builder $query) use ($user): void {
                $query->when($user->hasRole(UserRole::AdminSite), fn (Builder $siteQuery) => $siteQuery->where('site_id', $user->site_id));
            })
            ->when($user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->where('submitted_by', $user->id));
    }

    /**
     * @param  array{month: int, year: int, site_id: int|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function woSummaryData(ReportFilterRequest $request, array $filters): array
    {
        return $this->visibleWorkOrders($request)
            ->with('site:id,name')
            ->withCount([
                'items as total_item',
                'items as complete' => fn (Builder $query) => $query->where('status', 'complete'),
                'items as overdue' => fn (Builder $query) => $query->where('status', 'overdue'),
                'items as in_progress' => fn (Builder $query) => $query->where('status', 'in_progress'),
            ])
            ->whereMonth('created_at', $filters['month'])
            ->whereYear('created_at', $filters['year'])
            ->when($filters['site_id'], fn (Builder $query, int $siteId) => $query->where('site_id', $siteId))
            ->get()
            ->groupBy('site_id')
            ->map(fn ($workOrders): array => [
                'site' => $workOrders->first()->site?->name ?? '-',
                'total_wo' => $workOrders->count(),
                'total_item' => $workOrders->sum('total_item'),
                'complete' => $workOrders->sum('complete'),
                'overdue' => $workOrders->sum('overdue'),
                'in_progress' => $workOrders->sum('in_progress'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byItemData(ReportFilterRequest $request): array
    {
        return PlanningItem::query()
            ->orderBy('name')
            ->get()
            ->map(function (PlanningItem $item) use ($request): array {
                $items = $this->visibleWorkOrderItems($request)->where('planning_item_id', $item->id);
                $completed = (clone $items)->where('status', 'complete');
                $durations = (clone $completed)->whereNotNull('completed_date')->get(['created_at', 'completed_date'])
                    ->map(fn (WorkOrderItem $workOrderItem): int => $workOrderItem->created_at->diffInDays($workOrderItem->completed_date));

                return [
                    'item' => $item->name,
                    'total_wo' => (clone $items)->count(),
                    'total_complete' => (clone $completed)->count(),
                    'total_overdue' => (clone $items)->where('status', 'overdue')->count(),
                    'avg_hari_penyelesaian' => round((float) $durations->avg(), 1),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function byUnitData(ReportFilterRequest $request): array
    {
        return $this->visibleWorkOrders($request)
            ->with('unit:id,current_plate,site_id', 'site:id,name')
            ->withCount([
                'items as total_complete' => fn (Builder $query) => $query->where('status', 'complete'),
                'items as total_overdue' => fn (Builder $query) => $query->where('status', 'overdue'),
            ])
            ->get()
            ->groupBy('unit_id')
            ->map(fn ($workOrders): array => [
                'unit_id' => $workOrders->first()->unit_id,
                'plat_nomor' => $workOrders->first()->unit?->current_plate ?? '-',
                'site' => $workOrders->first()->site?->name ?? '-',
                'total_wo' => $workOrders->count(),
                'total_complete' => $workOrders->sum('total_complete'),
                'total_overdue' => $workOrders->sum('total_overdue'),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overdueByAreaData(ReportFilterRequest $request): array
    {
        return $this->visibleWorkOrderItems($request)
            ->with('planningItem:id,name', 'workOrder.site:id,name')
            ->where('status', 'overdue')
            ->get()
            ->groupBy(fn (WorkOrderItem $item): int => $item->workOrder->site_id)
            ->map(fn ($items): array => [
                'site' => $items->first()->workOrder->site?->name ?? '-',
                'total_overdue' => $items->count(),
                'items' => $items->pluck('planningItem.name')->filter()->unique()->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function visibleSites(ReportFilterRequest $request)
    {
        $user = $request->user();

        return Site::query()
            ->when($user->hasRole(UserRole::AdminSite), fn (Builder $query) => $query->whereKey($user->site_id))
            ->orderBy('name')
            ->get();
    }
}
