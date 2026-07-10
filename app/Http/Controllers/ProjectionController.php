<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ProjectionIndexRequest;
use App\Http\Resources\ProjectionResultResource;
use App\Http\Resources\RegionResource;
use App\Http\Resources\SiteResource;
use App\Models\Region;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Services\ProjectionService;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectionController extends Controller
{
    public function index(ProjectionIndexRequest $request, ProjectionService $service): Response
    {
        Gate::authorize('view-projections');

        $user = $request->user();
        $months = (int) ($request->validated('months') ?? 1);
        $requestedSiteId = $request->validated('site_id');
        $requestedRegionId = $request->validated('region_id');
        $month = CarbonImmutable::createFromFormat('Y-m', $request->validated('month') ?? now()->format('Y-m'))->startOfMonth();
        $canFilterRegion = $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]);
        $regionId = $canFilterRegion ? ($requestedRegionId ? (int) $requestedRegionId : null) : $user->region_id;
        $canFilterSite = $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea]);
        $siteId = $user->hasRole(UserRole::Mekanik) || ($user->hasRole(UserRole::PlannerArea) && $user->region_id === null) ? $user->site_id : ($canFilterSite ? $requestedSiteId : null);
        $result = $service->calculate($months, $siteId !== null ? (int) $siteId : null, $user->hasRole(UserRole::PlannerArea) ? $user->region_id : null);
        $calendar = $this->buildCalendar($user, $month, $siteId !== null ? (int) $siteId : null, $regionId);

        return Inertia::render('Projections/Index', [
            'projection' => ProjectionResultResource::make($result)->resolve(),
            'sites' => SiteResource::collection(Site::query()
                ->tap(fn (Builder $query) => AccessScope::applySiteListScope($query, $user))
                ->when($regionId, fn (Builder $query, int $selectedRegionId) => $query->where('region_id', $selectedRegionId))
                ->orderBy('name')
                ->get()),
            'regions' => RegionResource::collection(Region::query()->orderBy('name')->get()),
            'filters' => [
                'months' => $months,
                'site_id' => $siteId,
                'month' => $month->format('Y-m'),
                'region_id' => $regionId,
            ],
            'calendar' => $calendar,
            'permissions' => [
                'can_filter_site' => $canFilterSite,
                'can_filter_region' => $canFilterRegion,
                'can_view_unit' => true,
                'can_view_item' => true,
                'can_view_calendar' => true,
                'can_view_part' => $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]),
                'default_tab' => 'unit',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCalendar(User $user, CarbonImmutable $month, ?int $siteId, ?int $regionId): array
    {
        $today = CarbonImmutable::today();
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $items = WorkOrderItem::query()
            ->with(['planningItem:id,name', 'unitPlanning:id,next_due_date,next_due_km', 'workOrder.unit:id,current_plate,current_odo,site_id', 'workOrder.site:id,name,region,region_id'])
            ->whereNotIn('work_order_items.status', ['complete', 'cancelled'])
            ->whereHas('workOrder', fn (Builder $query) => $query->whereNotIn('status', ['complete', 'cancelled']))
            ->whereHas('workOrder.site', function (Builder $query) use ($user, $regionId): void {
                AccessScope::applySiteListScope($query, $user);
                $query->when($regionId, fn (Builder $regionQuery, int $selectedRegionId) => $regionQuery->where('region_id', $selectedRegionId));
            })
            ->when($siteId, fn (Builder $query, int $selectedSiteId) => $query->whereHas('workOrder', fn (Builder $workOrderQuery) => $workOrderQuery->where('site_id', $selectedSiteId)))
            ->get()
            ->map(function (WorkOrderItem $item) use ($today): array {
                $dueDate = $item->action === 'postpone' && $item->approved_at !== null && $item->new_due_date !== null
                    ? $item->new_due_date
                    : ($item->workOrder?->scheduled_date ?? $item->unitPlanning?->next_due_date);
                $lateDays = $dueDate === null ? 0 : max(0, $today->diffInDays(CarbonImmutable::parse($dueDate), false) * -1);

                return [
                    'id' => $item->id,
                    'work_order_id' => $item->work_order_id,
                    'site_id' => $item->workOrder->site_id,
                    'site_name' => $item->workOrder->site?->name ?? '-',
                    'plate_number' => $item->workOrder->unit?->current_plate ?? '-',
                    'item_name' => $item->planningItem?->name ?? 'Item maintenance',
                    'status' => $item->status,
                    'due_date' => $dueDate?->toDateString(),
                    'due_km' => $item->new_due_km ?? $item->unitPlanning?->next_due_km,
                    'late_days' => $lateDays,
                    'status_label' => $lateDays > 0 ? 'Overdue '.$lateDays.' hari' : match ($item->status) {
                        'on_hold' => 'On Hold',
                        'in_progress' => 'In Progress',
                        'pending_create' => 'Waiting Approval',
                        'postponed' => 'Postponed',
                        'blocked' => 'Blocked',
                        'breakdown' => 'Breakdown',
                        'rejected' => 'Rejected',
                        default => str($item->status)->replace('_', ' ')->title()->toString(),
                    },
                    'is_overdue' => $lateDays > 0,
                    'is_high_usage' => (bool) $item->triggered_by_high_usage,
                ];
            })
            ->filter(fn (array $item): bool => $item['due_date'] !== null && CarbonImmutable::parse($item['due_date'])->betweenIncluded($start, $end))
            ->values();

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->translatedFormat('F Y'),
            'items' => $items,
            'summary_by_date' => $items->groupBy('due_date')->map(fn ($dateItems): array => [
                'total' => $dateItems->count(),
                'overdue' => $dateItems->where('is_overdue', true)->count(),
                'high_usage' => $dateItems->where('is_high_usage', true)->count(),
            ])->all(),
        ];
    }
}
