<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AssignWorkOrderMechanicRequest;
use App\Http\Requests\CompleteWorkOrderItemRequest;
use App\Http\Requests\SubmitPostponeWorkOrderItemRequest;
use App\Http\Requests\SubmitReplaceWorkOrderItemRequest;
use App\Http\Resources\SiteResource;
use App\Http\Resources\UnitResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\PlanningIntervalResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function __construct(private PlanningIntervalResolver $intervalResolver) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $filters = $request->only(['site_id', 'status', 'unit_id', 'item_id', 'assignee_id']);
        $user = $request->user();
        $thresholds = $this->maintenanceThresholds();

        $workOrderQuery = WorkOrder::query()
            ->with(['unit.site', 'site', 'items.planningItem', 'items.unitPlanning', 'assignedMechanic:id,name'])
            ->withCount(['items as items_count' => fn ($query) => $query->where('status', '!=', 'complete')])
            ->withExists(['items as has_blocked_items' => fn ($query) => $query->where('status', 'blocked')])
            ->withExists(['items as has_high_usage_items' => fn ($query) => $query->where('triggered_by_high_usage', true)])
            ->whereDoesntHave('items', fn ($query) => $query->where('status', 'pending_create'))
            ->when(! $this->canAccessAllSites($user), fn ($query) => $query->where('site_id', $user->site_id))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->where('site_id', $siteId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($filters['item_id'] ?? null, fn ($query, string $itemId) => $query->whereHas('items', fn ($items) => $items->where('planning_item_id', $itemId)))
            ->when($filters['assignee_id'] ?? null, fn ($query, string $assigneeId) => $query->where('assigned_mechanic_id', $assigneeId));

        $openWorkOrders = (clone $workOrderQuery)->where('status', 'open')->latest()->paginate(20, ['*'], 'open_page')->withQueryString();
        $inProgressWorkOrders = (clone $workOrderQuery)->where('status', 'in_progress')->latest()->paginate(20, ['*'], 'in_progress_page')->withQueryString();
        $completeWorkOrders = (clone $workOrderQuery)->where('status', 'complete')->latest()->paginate(20, ['*'], 'complete_page')->withQueryString();

        $openWorkOrders->getCollection()->each(fn (WorkOrder $workOrder) => $this->appendBoardMeta($workOrder, $thresholds));
        $inProgressWorkOrders->getCollection()->each(fn (WorkOrder $workOrder) => $this->appendBoardMeta($workOrder, $thresholds));
        $completeWorkOrders->getCollection()->each(fn (WorkOrder $workOrder) => $this->appendBoardMeta($workOrder, $thresholds));

        return Inertia::render('WorkOrders/Index', [
            'boardColumns' => [
                'upcoming' => $this->previewItems($request, 'upcoming'),
                'preparation' => $this->previewItems($request, 'preparation'),
                'open' => WorkOrderResource::collection($openWorkOrders),
                'in_progress' => WorkOrderResource::collection($inProgressWorkOrders),
                'complete' => WorkOrderResource::collection($completeWorkOrders),
            ],
            'sites' => SiteResource::collection($this->visibleSites($request)),
            'units' => UnitResource::collection($this->visibleUnits($request)),
            'mechanics' => $this->visibleMechanics($request),
            'planningItems' => PlanningItem::query()->orderBy('name')->get(['id', 'name']),
            'canCreateUpcomingTask' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]),
            'canAssignMechanic' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, WorkOrder $wo): Response
    {
        Gate::authorize('view', $wo);
        $this->abortIfCannotAccessSite($request, $wo);

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => WorkOrderResource::make($wo->load([
                'unit.site',
                'site',
                'items.planningItem',
                'items.unitPlanning',
                'approvedBy:id,name',
                'assignedMechanic:id,name',
            ])),
        ]);
    }

    public function createFromPlanning(Request $request, UnitPlanning $planning, FleetNotificationService $notifications): RedirectResponse
    {
        $planning->load('unit');
        $workOrder = new WorkOrder(['site_id' => $planning->unit->site_id]);
        Gate::authorize('create', WorkOrder::class);

        if (! $this->canAccessAllSites($request->user()) && $planning->unit->site_id !== $request->user()->site_id) {
            abort(403);
        }

        if ($this->hasActiveItem($planning)) {
            return back()->withErrors(['planning' => 'Planning item ini sudah memiliki WO aktif.']);
        }

        DB::transaction(function () use ($request, $planning, $notifications): void {
            $workOrder = WorkOrder::query()->create([
                'unit_id' => $planning->unit_id,
                'site_id' => $planning->unit->site_id,
                'trigger_type' => 'manual',
                'status' => 'open',
                'submitted_by' => $request->user()->id,
                'notes' => 'Dibuat lebih awal dari preview Upcoming/Ancang-ancang.',
            ]);

            $item = WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => 'pending_create',
                'action' => 'create_task',
                'submitted_by' => $request->user()->id,
            ]);

            $notifications->taskCreationRequested($item);
        });

        return back()->with('status', 'Task berhasil diajukan untuk approval SPV.');
    }

    public function assignMechanic(AssignWorkOrderMechanicRequest $request, WorkOrder $wo): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);

        if ($wo->status !== 'in_progress' || $wo->approved_at === null) {
            return back()->withErrors(['assigned_mechanic_id' => 'WO harus approved dan berada di In Progress.']);
        }

        $wo->update($request->validated());

        return back()->with('status', 'Mekanik berhasil di-assign.');
    }

    public function approve(Request $request, WorkOrder $wo, FleetNotificationService $notifications): RedirectResponse
    {
        Gate::authorize('approve', $wo);
        $this->abortIfCannotAccessSite($request, $wo);

        DB::transaction(function () use ($request, $wo, $notifications): void {
            $wo->load(['items.unitPlanning', 'items.planningItem', 'unit']);

            $submittedItems = $wo->items->whereIn('status', ['replace', 'postpone', 'pending_create']);

            if ($wo->submitted_by === null) {
                $submittedItems = $submittedItems->merge($wo->items->where('status', 'on_hold'));
            }

            if ($submittedItems->isEmpty()) {
                abort(422, 'Work order belum memiliki action yang diajukan.');
            }

            $wo->update([
                'status' => $this->approvedWorkOrderStatus($submittedItems),
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            foreach ($submittedItems as $item) {
                if ($item->status === 'pending_create') {
                    $item->update([
                        'status' => 'on_hold',
                        'approved_by' => $request->user()->id,
                        'approved_at' => now(),
                    ]);

                    $notifications->taskAutoGenerated($item->refresh());

                    continue;
                }

                if ($item->status === 'postpone') {
                    $item->unitPlanning->update([
                        'next_due_km' => $item->new_due_km,
                        'next_due_date' => $item->new_due_date?->toDateString(),
                        'last_done_date' => $item->available_date?->toDateString() ?? $item->unitPlanning->last_done_date?->toDateString(),
                    ]);

                    $item->update([
                        'status' => 'postponed',
                        'approved_by' => $request->user()->id,
                        'approved_at' => now(),
                    ]);

                    continue;
                }

                $item->update([
                    'status' => 'in_progress',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);

                $notifications->replaceApprovedForLogistics($wo, $item);
            }
        });

        return redirect()->route('work-orders.show', $wo)->with('status', 'Work order berhasil disetujui.');
    }

    public function reject(Request $request, WorkOrder $wo): RedirectResponse
    {
        Gate::authorize('approve', $wo);
        $this->abortIfCannotAccessSite($request, $wo);

        DB::transaction(function () use ($request, $wo): void {
            $wo->load('items');

            $pendingItems = $wo->items->where('status', 'pending_create');

            if ($pendingItems->isEmpty()) {
                abort(422, 'Work order belum memiliki pengajuan Buat Task Sekarang.');
            }

            $pendingItems->each(fn (WorkOrderItem $item) => $item->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]));

            $wo->update([
                'status' => 'cancelled',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });

        return redirect()->route('work-orders.index')->with('status', 'Pengajuan task ditolak.');
    }

    public function submitReplace(SubmitReplaceWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, FleetNotificationService $notifications): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);

        if (! in_array($item->status, ['on_hold', 'blocked', 'overdue'], true)) {
            return back()->withErrors(['action' => 'Hanya item On Hold, Overdue, atau Blocked yang bisa diajukan Replace.']);
        }

        $item->update([
            'status' => 'replace',
            'action' => 'replace',
            'reason' => $request->string('reason')->toString() ?: null,
            'previous_due_km' => $item->unitPlanning?->next_due_km,
            'previous_due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
            'submitted_by' => $request->user()->id,
        ]);

        $notifications->taskSubmitted($item->refresh(), 'replace');

        return redirect()->route('work-orders.show', $wo)->with('status', 'Replace berhasil diajukan untuk approval SPV.');
    }

    public function submitPostpone(SubmitPostponeWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, FleetNotificationService $notifications): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);

        if (! in_array($item->status, ['on_hold', 'blocked', 'overdue'], true)) {
            return back()->withErrors(['action' => 'Hanya item On Hold, Overdue, atau Blocked yang bisa diajukan Postpone.']);
        }

        $item->update([
            'status' => 'postpone',
            'action' => 'postpone',
            'reason' => $request->string('reason')->toString(),
            'previous_due_km' => $item->unitPlanning?->next_due_km,
            'previous_due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
            'new_due_km' => $request->integer('new_due_km'),
            'new_due_date' => $request->date('new_due_date')->toDateString(),
            'submitted_by' => $request->user()->id,
        ]);

        $notifications->taskSubmitted($item->refresh(), 'postpone');

        return redirect()->route('work-orders.show', $wo)->with('status', 'Postpone berhasil diajukan untuk approval SPV.');
    }

    public function complete(CompleteWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);

        if ($item->work_order_id !== $wo->id) {
            abort(404);
        }

        if ($item->status !== 'in_progress') {
            return back()->withErrors(['action' => 'Item harus In Progress sebelum bisa diselesaikan.']);
        }

        DB::transaction(function () use ($request, $wo, $item): void {
            $item->load('unitPlanning.planningItem', 'unitPlanning.unit');

            $completedDate = CarbonImmutable::parse($request->date('completed_date'));
            $completedOdo = $request->integer('completed_odo');

            $item->update([
                'status' => 'complete',
                'action' => 'replace',
                'completed_odo' => $completedOdo,
                'completed_date' => $completedDate->toDateString(),
                'notes' => $request->string('notes')->toString() ?: null,
                'submitted_by' => $request->user()->id,
            ]);

            $unitPlanning = $item->unitPlanning;
            $planningItem = $unitPlanning->planningItem;
            $interval = $this->intervalResolver->resolve($planningItem, $unitPlanning->unit);

            $unitPlanning->update([
                'last_done_km' => $completedOdo,
                'last_done_date' => $completedDate->toDateString(),
                'next_due_km' => $completedOdo + $interval['interval_km'],
                'next_due_date' => $completedDate->addDays($interval['interval_days'])->toDateString(),
            ]);

            if (! $wo->items()->where('status', '!=', 'complete')->exists()) {
                $wo->update(['status' => 'complete']);
            }
        });

        return redirect()->route('work-orders.show', $wo)->with('status', 'Item work order berhasil diselesaikan.');
    }

    private function visibleSites(Request $request)
    {
        $user = $request->user();

        return Site::query()
            ->when(! $this->canAccessAllSites($user), fn ($query) => $query->whereKey($user->site_id))
            ->orderBy('name')
            ->get();
    }

    private function visibleUnits(Request $request)
    {
        $user = $request->user();

        return Unit::query()
            ->with('site:id,name,region')
            ->when(! $this->canAccessAllSites($user), fn ($query) => $query->where('site_id', $user->site_id))
            ->orderBy('current_plate')
            ->get();
    }

    private function visibleMechanics(Request $request)
    {
        $user = $request->user();

        return User::query()
            ->where('role', UserRole::Mekanik->value)
            ->when(! $this->canAccessAllSites($user), fn ($query) => $query->where('site_id', $user->site_id))
            ->orderBy('name')
            ->get(['id', 'name', 'site_id']);
    }

    /**
     * @return array{warning_days: int, warning_km: int, ancang_ancang_days: int, ancang_ancang_km: int, upcoming_days: int, upcoming_km: int}
     */
    private function maintenanceThresholds(): array
    {
        $values = SystemThreshold::query()
            ->whereIn('key', ['warning_days', 'warning_km', 'ancang_ancang_days', 'ancang_ancang_km', 'upcoming_days', 'upcoming_km'])
            ->pluck('value', 'key');

        $warningDays = (int) ($values['warning_days'] ?? 7);
        $warningKm = (int) ($values['warning_km'] ?? 500);

        return [
            'warning_days' => $warningDays,
            'warning_km' => $warningKm,
            'ancang_ancang_days' => (int) ($values['ancang_ancang_days'] ?? ($warningDays * 2)),
            'ancang_ancang_km' => (int) ($values['ancang_ancang_km'] ?? ($warningKm * 2)),
            'upcoming_days' => (int) ($values['upcoming_days'] ?? ($warningDays * 4)),
            'upcoming_km' => (int) ($values['upcoming_km'] ?? ($warningKm * 4)),
        ];
    }

    private function appendBoardMeta(WorkOrder $workOrder, array $thresholds): void
    {
        $nearest = $workOrder->items
            ->map(fn (WorkOrderItem $item): ?array => $this->dueMeta($workOrder->unit, $item->unitPlanning, $thresholds))
            ->filter()
            ->sortBy('sort_value')
            ->first();

        $workOrder->setAttribute('planning_item_names', $workOrder->items->pluck('planningItem.name')->filter()->values()->all());
        $workOrder->setAttribute('nearest_due', $nearest);
        $workOrder->setAttribute('sub_status', $this->subStatus($workOrder));
        $workOrder->setAttribute('has_overdue_items', $nearest !== null && $nearest['level'] === 'red');
        $workOrder->setAttribute('has_rejected_items', $workOrder->items->contains('status', 'rejected'));
    }

    private function subStatus(WorkOrder $workOrder): ?array
    {
        if ($workOrder->items->contains(fn (WorkOrderItem $item): bool => in_array($item->status, ['replace', 'postpone', 'pending_create'], true))) {
            return ['key' => 'waiting_approval', 'label' => 'Menunggu Approval'];
        }

        if ($workOrder->status !== 'in_progress') {
            return null;
        }

        if ($workOrder->assignedMechanic !== null && $workOrder->scheduled_date !== null) {
            return ['key' => 'assigned', 'label' => 'Assign Mekanik: '.$workOrder->assignedMechanic->name.' — '.$workOrder->scheduled_date->toDateString()];
        }

        if ($workOrder->items->contains(fn (WorkOrderItem $item): bool => $item->action === 'replace' && $item->status === 'in_progress')) {
            return ['key' => 'waiting_part', 'label' => 'Menunggu Part'];
        }

        return ['key' => 'working', 'label' => 'Dikerjakan'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previewItems(Request $request, string $zone)
    {
        $filters = $request->only(['site_id', 'unit_id', 'item_id']);
        $user = $request->user();
        $thresholds = $this->maintenanceThresholds();
        $pageName = $zone.'_page';

        $items = UnitPlanning::query()
            ->with(['unit.site', 'planningItem'])
            ->join('units', 'units.id', '=', 'unit_plannings.unit_id')
            ->select('unit_plannings.*')
            ->whereDoesntHave('workOrderItems', fn ($query) => $query->whereIn('status', ['on_hold', 'replace', 'postpone', 'in_progress', 'blocked', 'breakdown', 'overdue']))
            ->when(! $this->canAccessAllSites($user), fn ($query) => $query->where('units.site_id', $user->site_id))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->where('units.site_id', $siteId))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($filters['item_id'] ?? null, fn ($query, string $itemId) => $query->where('planning_item_id', $itemId))
            ->where(fn ($query) => $this->applyPreviewZoneScope($query, $zone, $thresholds))
            ->orderByRaw('COALESCE(unit_plannings.next_due_date, date(\'9999-12-31\'))')
            ->orderBy('unit_plannings.next_due_km')
            ->paginate(20, ['unit_plannings.*'], $pageName)
            ->withQueryString()
            ->through(fn (UnitPlanning $planning): array => [
                'id' => $planning->id,
                'unit_id' => $planning->unit_id,
                'planning_item_id' => $planning->planning_item_id,
                'unit_plate' => $planning->unit->current_plate,
                'site_name' => $planning->unit->site?->name,
                'planning_item_name' => $planning->planningItem->name,
                'next_due_km' => $planning->next_due_km,
                'next_due_date' => $planning->next_due_date?->toDateString(),
                'due' => $this->dueMeta($planning->unit, $planning, $thresholds),
                'approval_status' => $this->previewApprovalStatus($planning),
            ]);

        return [
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'from' => $items->firstItem(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'to' => $items->lastItem(),
                'total' => $items->total(),
            ],
        ];
    }

    private function applyPreviewZoneScope($query, string $zone, array $thresholds): void
    {
        $today = CarbonImmutable::today();
        $warningDate = $today->addDays($thresholds['warning_days'])->toDateString();
        $preparationDate = $today->addDays($thresholds['ancang_ancang_days'])->toDateString();
        $upcomingDate = $today->addDays($thresholds['upcoming_days'])->toDateString();

        $warningKm = (int) $thresholds['warning_km'];
        $preparationKm = (int) $thresholds['ancang_ancang_km'];
        $upcomingKm = (int) $thresholds['upcoming_km'];

        $query->whereNot(fn ($warning) => $this->applyDueThresholdScope($warning, $warningDate, $warningKm));

        if ($zone === 'preparation') {
            $query->where(fn ($preparation) => $this->applyDueThresholdScope($preparation, $preparationDate, $preparationKm));

            return;
        }

        $query
            ->whereNot(fn ($preparation) => $this->applyDueThresholdScope($preparation, $preparationDate, $preparationKm))
            ->where(fn ($upcoming) => $this->applyDueThresholdScope($upcoming, $upcomingDate, $upcomingKm));
    }

    private function applyDueThresholdScope($query, string $cutoffDate, int $km): void
    {
        $query
            ->where(fn ($date) => $date
                ->whereNotNull('unit_plannings.next_due_date')
                ->whereDate('unit_plannings.next_due_date', '<=', $cutoffDate))
            ->orWhere(fn ($odo) => $odo
                ->whereNotNull('unit_plannings.next_due_km')
                ->whereRaw('units.current_odo >= unit_plannings.next_due_km - ?', [$km]));
    }

    private function isInPreviewZone(UnitPlanning $planning, string $zone, array $thresholds): bool
    {
        if ($this->meetsThreshold($planning, $thresholds['warning_days'], $thresholds['warning_km'])) {
            return false;
        }

        $isPreparation = $this->meetsThreshold($planning, $thresholds['ancang_ancang_days'], $thresholds['ancang_ancang_km']);

        if ($zone === 'preparation') {
            return $isPreparation;
        }

        return ! $isPreparation && $this->meetsThreshold($planning, $thresholds['upcoming_days'], $thresholds['upcoming_km']);
    }

    private function meetsThreshold(UnitPlanning $planning, int $days, int $km): bool
    {
        $today = CarbonImmutable::today();
        $matchesKm = $planning->next_due_km !== null
            && $planning->unit->current_odo >= ($planning->next_due_km - $km);
        $matchesDate = $planning->next_due_date !== null
            && $today->greaterThanOrEqualTo(CarbonImmutable::parse($planning->next_due_date)->subDays($days));

        return $matchesKm || $matchesDate;
    }

    private function dueMeta(?Unit $unit, ?UnitPlanning $planning, array $thresholds): ?array
    {
        if ($unit === null || $planning === null) {
            return null;
        }

        $daysUntilDue = $planning->next_due_date === null ? null : CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($planning->next_due_date), false);
        $kmUntilDue = $planning->next_due_km === null ? null : $planning->next_due_km - $unit->current_odo;
        $isOverdue = ($daysUntilDue !== null && $daysUntilDue < 0) || ($kmUntilDue !== null && $kmUntilDue < 0);
        $isWarning = ($daysUntilDue !== null && $daysUntilDue <= $thresholds['warning_days']) || ($kmUntilDue !== null && $kmUntilDue <= $thresholds['warning_km']);

        return [
            'next_due_km' => $planning->next_due_km,
            'next_due_date' => $planning->next_due_date?->toDateString(),
            'level' => $isOverdue ? 'red' : ($isWarning ? 'yellow' : 'green'),
            'label' => $isOverdue ? 'Overdue '.abs((int) ($daysUntilDue ?? 0)).' hari' : ($isWarning ? 'Warning' : 'Aman'),
            'sort_value' => min($daysUntilDue ?? 999999, $kmUntilDue ?? 999999),
        ];
    }

    private function hasActiveItem(UnitPlanning $planning): bool
    {
        return $planning->workOrderItems()->whereIn('status', ['on_hold', 'pending_create', 'replace', 'postpone', 'in_progress', 'blocked', 'breakdown', 'overdue'])->exists();
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $submittedItems
     */
    private function approvedWorkOrderStatus(Collection $submittedItems): string
    {
        if ($submittedItems->contains('status', 'replace') || $submittedItems->contains('status', 'on_hold')) {
            return 'in_progress';
        }

        if ($submittedItems->contains('status', 'pending_create')) {
            return 'open';
        }

        return 'complete';
    }

    private function previewApprovalStatus(UnitPlanning $planning): ?string
    {
        if ($planning->workOrderItems()->where('status', 'pending_create')->exists()) {
            return 'pending_create';
        }

        return $planning->workOrderItems()
            ->where('status', 'rejected')
            ->latest('updated_at')
            ->exists() ? 'rejected' : null;
    }

    private function canAccessAllSites(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]);
    }

    private function abortIfCannotAccessSite(Request $request, WorkOrder $workOrder): void
    {
        $user = $request->user();

        if ($this->canAccessAllSites($user)) {
            return;
        }

        if ($workOrder->site_id !== $user->site_id) {
            abort(403);
        }
    }

    private function abortIfItemDoesNotBelongToWorkOrder(WorkOrder $workOrder, WorkOrderItem $item): void
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(404);
        }
    }
}
