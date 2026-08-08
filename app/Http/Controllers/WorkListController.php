<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\SubmitWorkListRequest;
use App\Http\Requests\WorkListIndexRequest;
use App\Http\Resources\SiteResource;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkListController extends Controller
{
    private const PRIORITY_PLANNING_ITEM_NAMES = [
        'PM Check / Reguler Services',
        'Service A',
        'Service B',
    ];

    public function index(WorkListIndexRequest $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);
        $user = $request->user();

        abort_unless($user->isOneOf([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea]), 403);

        $filters = $request->validated();
        $planningItemIds = collect($filters['planning_item_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->values()->all();
        $includeIncompleteBaseline = ! array_key_exists('include_incomplete_baseline', $filters)
            || $request->boolean('include_incomplete_baseline');
        $sortBy = $filters['sort_by'] ?? 'priority';
        $today = CarbonImmutable::today();

        $items = WorkOrderItem::query()
            ->applicable()
            ->with([
                'planningItem:id,name',
                'unitPlanning:id,last_done_km,last_done_date,next_due_date,next_due_km',
                'workOrder.unit' => fn ($query) => $query
                    ->select(['id', 'current_plate', 'current_odo', 'has_odometer_reading', 'site_id'])
                    ->with('unitPlannings:id,unit_id,last_done_km'),
                'workOrder.site:id,name,region,region_id',
            ])
            ->whereIn('work_order_items.status', ['on_hold', 'overdue'])
            ->whereHas('workOrder', fn (Builder $query) => $query->whereIn('status', ['open', 'in_progress']))
            ->whereHas('workOrder.site', fn (Builder $query) => AccessScope::applySiteListScope($query, $user))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $siteId) => $query->whereHas('workOrder', fn (Builder $workOrderQuery) => $workOrderQuery->where('site_id', $siteId)))
            ->when($planningItemIds !== [], fn (Builder $query) => $query->whereIn('work_order_items.planning_item_id', $planningItemIds))
            ->when(! $includeIncompleteBaseline, fn (Builder $query) => $query->whereHas('workOrder.unit', fn (Builder $unitQuery): Builder => $this->applyCompleteUnitBaselineScope($unitQuery)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->whereHas('workOrder.unit', fn (Builder $unitQuery) => $unitQuery->where('current_plate', 'like', "%{$search}%"))
                        ->orWhereHas('planningItem', fn (Builder $planningQuery) => $planningQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->get()
            ->map(function (WorkOrderItem $item) use ($today): array {
                $dueDate = $item->unitPlanning?->next_due_date;
                $lateDays = $dueDate === null ? 0 : max(0, $today->diffInDays(CarbonImmutable::parse($dueDate), false) * -1);
                $baselineMissing = $item->unitPlanning?->isBaselineMissing() ?? true;
                $unit = $item->workOrder->unit;

                return [
                    'id' => $item->id,
                    'work_order_id' => $item->work_order_id,
                    'site_id' => $item->workOrder->site_id,
                    'site_name' => $item->workOrder->site?->name ?? '-',
                    'plate_number' => $item->workOrder->unit?->current_plate ?? '-',
                    'current_odo' => $unit?->current_odo ?? 0,
                    'baseline_incomplete' => $unit?->hasIncompletePlanningBaseline() ?? true,
                    'item_name' => $item->planningItem?->name ?? 'Item maintenance',
                    'is_priority' => in_array($item->planningItem?->name, self::PRIORITY_PLANNING_ITEM_NAMES, true),
                    'status' => $item->status,
                    'due_date' => $dueDate?->toDateString(),
                    'due_km' => $item->unitPlanning?->next_due_km,
                    'late_days' => $item->status === 'overdue' ? $lateDays : 0,
                    'baseline_missing' => $baselineMissing,
                    'status_label' => $baselineMissing
                        ? 'Baseline Belum Diisi'
                        : ($item->status === 'overdue' ? 'Telat '.$lateDays.' hari' : 'Aman'),
                ];
            })
            ->pipe(fn (Collection $items): Collection => $this->sortItems($items, $sortBy));

        $siteIds = $items->pluck('site_id')->merge($filters['site_id'] ?? [])->filter()->unique()->values();

        return Inertia::render('WorkList/Index', [
            'items' => $items,
            'sites' => SiteResource::collection(AccessScope::applySiteListScope(Site::query(), $user)->orderBy('name')->get()),
            'planningItems' => PlanningItem::query()->orderBy('name')->get(['id', 'name']),
            'mechanicsBySite' => User::query()
                ->where('role', UserRole::Mekanik->value)
                ->whereIn('site_id', $siteIds)
                ->orderBy('name')
                ->get(['id', 'name', 'site_id'])
                ->groupBy('site_id')
                ->map(fn ($mechanics) => $mechanics->values())
                ->all(),
            'filters' => [
                'site_id' => $filters['site_id'] ?? '',
                'search' => $filters['search'] ?? '',
                'planning_item_ids' => $planningItemIds,
                'include_incomplete_baseline' => $includeIncompleteBaseline,
                'sort_by' => $sortBy,
            ],
        ]);
    }

    private function sortItems(Collection $items, string $sortBy): Collection
    {
        return $items->sort(function (array $left, array $right) use ($sortBy): int {
            if ($sortBy === 'due_date') {
                return $this->compareNullable($left['due_date'], $right['due_date'])
                    ?: $this->compareNullable($left['due_km'], $right['due_km'])
                    ?: ($left['id'] <=> $right['id']);
            }

            if ($sortBy === 'due_km') {
                return $this->compareNullable($left['due_km'], $right['due_km'])
                    ?: $this->compareNullable($left['due_date'], $right['due_date'])
                    ?: ($left['id'] <=> $right['id']);
            }

            return ($right['is_priority'] <=> $left['is_priority'])
                ?: ($right['late_days'] <=> $left['late_days'])
                ?: $this->compareNullable($left['due_date'], $right['due_date'])
                ?: ($left['id'] <=> $right['id']);
        })->values();
    }

    private function compareNullable(string|int|null $left, string|int|null $right): int
    {
        if ($left === null) {
            return $right === null ? 0 : 1;
        }

        if ($right === null) {
            return -1;
        }

        return $left <=> $right;
    }

    private function applyCompleteUnitBaselineScope(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('has_odometer_reading'), true)
            ->whereHas('unitPlannings', fn (Builder $planningQuery): Builder => $planningQuery
                ->where($planningQuery->qualifyColumn('last_done_km'), '!=', 0));
    }

    public function store(SubmitWorkListRequest $request, FleetNotificationService $notifications): RedirectResponse
    {
        $payload = $request->validated();
        $user = $request->user();
        $submittedCount = 0;

        DB::transaction(function () use ($payload, $user, $notifications, &$submittedCount): void {
            foreach ($payload['groups'] as $group) {
                foreach ($group['item_ids'] as $itemId) {
                    $item = WorkOrderItem::query()
                        ->applicable()
                        ->with(['workOrder.site:id,region_id', 'workOrder.unit:id,status', 'unitPlanning', 'planningItem'])
                        ->lockForUpdate()
                        ->findOrFail($itemId);

                    $workOrder = $item->workOrder;

                    abort_unless(AccessScope::canAccessSite($user, $workOrder->site_id, $workOrder->site?->region_id), 403);

                    if ((int) $group['site_id'] !== (int) $workOrder->site_id) {
                        abort(422, 'Item yang dipilih harus sesuai dengan lokasi formnya.');
                    }

                    if (! in_array($item->status, ['on_hold', 'overdue'], true)) {
                        abort(422, 'Ada item yang sudah berubah status. Muat ulang halaman lalu pilih ulang.');
                    }

                    if ($item->unitPlanning?->isBaselineMissing() ?? true) {
                        abort(422, 'Baseline item belum diisi. Isi baseline sebelum memproses task ini.');
                    }

                    if ($workOrder->unit?->status === 'breakdown') {
                        abort(422, 'Unit sedang Breakdown. Input KM baru dan isi part yang diganti sebelum melanjutkan aksi normal.');
                    }

                    match ($group['action']) {
                        'replace' => $this->submitReplace($item, $group, $user, $notifications),
                        'postpone' => $this->submitPostpone($item, $group, $user, $notifications),
                        'blocked' => $this->submitBlocked($item, $group, $user, $notifications),
                    };

                    $submittedCount++;
                }
            }
        });

        return redirect()->route('work-list.index')->with('status', $submittedCount.' item berhasil dikirim dari Daftar Kerja.');
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function submitReplace(WorkOrderItem $item, array $group, User $user, FleetNotificationService $notifications): void
    {
        $item->workOrder->update([
            'assigned_mechanic_id' => $group['assigned_mechanic_id'],
            'scheduled_date' => $group['scheduled_date'],
        ]);

        $item->update([
            'status' => 'replace',
            'action' => 'replace',
            'reason' => 'Diajukan dari Daftar Kerja.',
            'previous_due_km' => $item->unitPlanning?->next_due_km,
            'previous_due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
            'submitted_by' => $user->id,
        ]);

        $notifications->taskSubmitted($item->refresh(), 'replace');
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function submitPostpone(WorkOrderItem $item, array $group, User $user, FleetNotificationService $notifications): void
    {
        $item->update([
            'status' => 'postpone',
            'action' => 'postpone',
            'reason' => 'Ditunda dari Daftar Kerja.',
            'previous_due_km' => $item->unitPlanning?->next_due_km,
            'previous_due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
            'new_due_km' => $item->unitPlanning?->next_due_km ?? 0,
            'new_due_date' => $group['scheduled_date'],
            'submitted_by' => $user->id,
        ]);

        $notifications->taskSubmitted($item->refresh(), 'postpone');
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function submitBlocked(WorkOrderItem $item, array $group, User $user, FleetNotificationService $notifications): void
    {
        $item->update([
            'status' => 'blocked',
            'action' => 'blocked',
            'reason' => 'Diblokir dari Daftar Kerja.',
            'submitted_by' => $user->id,
        ]);

        $notifications->taskSubmitted($item->refresh(), 'blocked');
    }
}
