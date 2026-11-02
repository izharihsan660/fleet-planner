<?php

namespace App\Http\Controllers;

use App\Enums\ManualProjectionReason;
use App\Enums\UserRole;
use App\Exports\ProjectionExport;
use App\Http\Requests\ProjectionExportRequest;
use App\Http\Requests\ProjectionIndexRequest;
use App\Http\Resources\ManualProjectionResource;
use App\Http\Resources\ProjectionResultResource;
use App\Http\Resources\RegionResource;
use App\Http\Resources\SiteResource;
use App\Models\ManualProjection;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Services\ProjectionService;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectionController extends Controller
{
    public function export(ProjectionExportRequest $request, ProjectionService $service): BinaryFileResponse
    {
        $months = (int) ($request->validated('months') ?? 1);
        $regionId = $request->validated('region_id');
        $siteId = $request->validated('site_id');
        $periodStart = CarbonImmutable::today();
        $result = $service->calculate(
            $months,
            $siteId !== null ? (int) $siteId : null,
            $regionId !== null ? (int) $regionId : null,
            $request->user(),
        );
        $site = $siteId !== null ? Site::query()->with('area:id,name')->find($siteId) : null;
        $regionName = $regionId !== null ? Region::query()->whereKey($regionId)->value('name') : $site?->area?->name;
        $siteName = $site?->name ?? 'Semua Site';
        $filterLabel = ($regionName ?? 'Semua Region').' / '.$siteName;
        $filenameLocation = preg_replace('/[^A-Za-z0-9 _-]/', '_', Str::ascii($site?->name ?? $regionName ?? 'Semua'));

        return Excel::download(
            new ProjectionExport($result, $service->unitsForExport(array_column($result['by_unit'], 'unit_id')), $periodStart, $filterLabel),
            sprintf('Proyeksi_%dbln_%s_%s.xlsx', $months, $filenameLocation, $periodStart->format('Ymd')),
            \Maatwebsite\Excel\Excel::XLSX,
        );
    }

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
        $siteId = $siteId !== null ? (int) $siteId : null;
        $result = $service->calculate($months, $siteId, $regionId, $user);
        $calendar = $this->buildCalendar($user, $month, $siteId, $regionId);

        return Inertia::render('Projections/Index', [
            'projection' => ProjectionResultResource::make($result)->resolve(),
            'sites' => SiteResource::collection(Site::query()
                ->tap(fn (Builder $query) => AccessScope::applySiteListScope($query, $user))
                ->orderBy('name')
                ->get()),
            'regions' => RegionResource::collection(Region::query()
                ->when(! $canFilterRegion, fn (Builder $query) => $query->whereKey($user->region_id))
                ->orderBy('name')
                ->get()),
            'filters' => [
                'months' => $months,
                'site_id' => $siteId,
                'month' => $month->format('Y-m'),
                'region_id' => $siteId === null ? $regionId : null,
            ],
            'today' => CarbonImmutable::today()->toDateString(),
            'calendar' => $calendar,
            'period_options' => $service->periodOptions(),
            'manual_projections' => ManualProjectionResource::collection(ManualProjection::query()->active()
                ->whereHas('unit', function (Builder $query) use ($user, $regionId, $siteId): void {
                    AccessScope::applySiteScope($query, $user);
                    $query->when($regionId !== null, fn (Builder $query) => $query->whereHas('site', fn (Builder $query) => $query->where('region_id', $regionId)))
                        ->when($siteId !== null, fn (Builder $query) => $query->where('site_id', $siteId));
                })
                ->with(['unit.site:id,name,region_id', 'planningItem:id,name', 'createdBy:id,name'])
                ->orderBy('target_date')->orderBy('id')
                ->paginate(25, ['*'], 'manual_page')->withQueryString()),
            'manual_units' => Unit::query()
                ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $user))
                ->orderBy('current_plate')->get(['id', 'current_plate']),
            'manual_items' => PlanningItem::query()->orderBy('id')->get(['id', 'name']),
            'manual_reasons' => array_map(fn (ManualProjectionReason $reason): array => [
                'value' => $reason->value, 'label' => $reason->label(),
            ], ManualProjectionReason::cases()),
            'permissions' => [
                'can_create_manual' => $user->can('create', ManualProjection::class),
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
            ->applicable()
            ->whereHas('unitPlanning', fn (Builder $planningQuery): Builder => $planningQuery->dueEligible())
            ->with(['planningItem:id,name', 'unitPlanning:id,last_done_km,last_done_date,next_due_date,next_due_km', 'workOrder.unit:id,current_plate,current_odo,site_id', 'workOrder.site:id,name,region,region_id'])
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
                    : ($item->scheduled_date ?? $item->unitPlanning?->next_due_date);
                $lateDays = $dueDate === null ? 0 : max(0, $today->diffInDays(CarbonImmutable::parse($dueDate), false) * -1);

                return [
                    'id' => $item->id,
                    'work_order_id' => $item->work_order_id,
                    'site_id' => $item->workOrder->site_id,
                    'site_name' => $item->workOrder->site?->name ?? '-',
                    'plate_number' => $item->workOrder->unit?->current_plate ?? '-',
                    'item_name' => $item->planningItem?->name ?? 'Item perawatan',
                    'status' => $item->status,
                    'due_date' => $dueDate?->toDateString(),
                    'due_km' => $item->new_due_km ?? $item->unitPlanning?->next_due_km,
                    'late_days' => $lateDays,
                    'status_label' => $lateDays > 0 ? 'Terlambat '.$lateDays.' hari' : match ($item->status) {
                        'on_hold' => 'Menunggu',
                        'in_progress' => 'Sedang Dikerjakan',
                        'pending_create' => 'Menunggu Approval',
                        'postponed' => 'Ditunda',
                        'blocked' => 'Terhambat',
                        'breakdown' => 'Rusak/Mogok',
                        'rejected' => 'Ditolak',
                        'open' => 'Terbuka',
                        'overdue' => 'Terlambat',
                        'waiting_part' => 'Menunggu Part',
                        'replace' => 'Penggantian',
                        'postpone' => 'Penundaan',
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
