<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AssignWorkOrderMechanicRequest;
use App\Http\Requests\CompleteWorkOrderItemRequest;
use App\Http\Requests\StoreManualFindingRequest;
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
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function __construct(private PlanningIntervalResolver $intervalResolver) {}

    public function myTasks(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $user = $request->user();

        abort_unless($user->hasRole(UserRole::Mekanik), 403);

        $items = WorkOrderItem::query()
            ->with(['planningItem:id,name', 'workOrder.unit:id,current_plate,current_odo', 'workOrder.site:id,name'])
            ->whereIn('work_order_items.status', ['in_progress', 'overdue'])
            ->whereHas('workOrder', fn ($query) => $query
                ->where('assigned_mechanic_id', $user->id)
                ->where('work_orders.status', 'in_progress')
            )
            ->join('work_orders', 'work_orders.id', '=', 'work_order_items.work_order_id')
            ->select('work_order_items.*')
            ->orderByRaw('CASE WHEN work_orders.scheduled_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('work_orders.scheduled_date')
            ->orderBy('work_order_items.id')
            ->get()
            ->map(fn (WorkOrderItem $item): array => [
                'id' => $item->id,
                'work_order_id' => $item->work_order_id,
                'unit_name' => $item->workOrder?->unit?->current_plate ?? '-',
                'item_name' => $item->planningItem?->name ?? 'Pekerjaan maintenance',
                'scheduled_date' => $item->workOrder?->scheduled_date?->toDateString(),
                'current_odo' => $item->workOrder?->unit?->current_odo ?? 0,
                'site_name' => $item->workOrder?->site?->name,
            ]);

        return Inertia::render('Mechanic/Tasks', [
            'tasks' => $items,
        ]);
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $filters = $request->only(['site_id', 'status', 'unit_id', 'item_id', 'assignee_id']);
        $user = $request->user();
        $thresholds = $this->maintenanceThresholds();

        $workOrderQuery = WorkOrder::query()
            ->with(['unit.site', 'site', 'items.planningItem', 'items.unitPlanning', 'assignedMechanic:id,name'])
            ->withCount('items')
            ->withExists(['items as has_blocked_items' => fn ($query) => $query->where('status', 'blocked')])
            ->withExists(['items as has_high_usage_items' => fn ($query) => $query->where('triggered_by_high_usage', true)])
            ->whereDoesntHave('items', fn ($query) => $query->where('status', 'pending_create'))
            ->tap(fn ($query) => $this->applyCurrentUnitSiteScope($query, $user))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->whereHas('unit', fn ($unitQuery) => $unitQuery->where('site_id', $siteId)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($filters['item_id'] ?? null, fn ($query, string $itemId) => $query->whereHas('items', fn ($items) => $items->where('planning_item_id', $itemId)))
            ->when($filters['assignee_id'] ?? null, fn ($query, string $assigneeId) => $query->where('assigned_mechanic_id', $assigneeId));

        $openWorkOrders = (clone $workOrderQuery)->where('status', 'open')->latest()->paginate(20, ['*'], 'open_page')->withQueryString();
        $inProgressWorkOrders = (clone $workOrderQuery)->where('status', 'in_progress')->latest()->paginate(20, ['*'], 'in_progress_page')->withQueryString();
        $completeWorkOrders = (clone $workOrderQuery)
            ->where('status', 'complete')
            ->whereDoesntHave('items', fn ($query) => $query->whereNotIn('status', ['complete', 'postponed']))
            ->latest()
            ->paginate(20, ['*'], 'complete_page')
            ->withQueryString();

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
            'canReviewWorkOrders' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::SpvHo]),
            'canApproveWorkOrders' => $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]),
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
            'planningItems' => PlanningItem::query()->orderBy('name')->get(['id', 'name']),
            'mechanics' => User::query()
                ->where('role', UserRole::Mekanik->value)
                ->where('site_id', $wo->site_id)
                ->orderBy('name')
                ->get(['id', 'name', 'site_id']),
        ]);
    }

    public function createFromPlanning(Request $request, UnitPlanning $planning, FleetNotificationService $notifications): RedirectResponse
    {
        $planning->load('unit');
        $workOrder = new WorkOrder(['site_id' => $planning->unit->site_id]);
        Gate::authorize('create', WorkOrder::class);

        $planning->loadMissing('unit.site:id,region_id');

        if (! AccessScope::canAccessSite($request->user(), $planning->unit->site_id, $planning->unit->site?->region_id)) {
            abort(403);
        }

        if ($this->hasActiveItem($planning)) {
            return back()->withErrors(['planning' => 'Planning item ini sudah memiliki WO aktif.']);
        }

        $assignment = $this->optionalAssignmentPayload($request, $planning->unit->site_id);

        DB::transaction(function () use ($request, $planning, $notifications, $assignment): void {
            $workOrder = WorkOrder::query()->create([
                'unit_id' => $planning->unit_id,
                'site_id' => $planning->unit->site_id,
                'trigger_type' => 'manual',
                'status' => 'open',
                'submitted_by' => $request->user()->id,
                'notes' => 'Dibuat lebih awal dari preview Upcoming/Ancang-ancang.',
                ...$assignment,
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

    public function storeManualFinding(StoreManualFindingRequest $request, Unit $unit, FleetNotificationService $notifications): RedirectResponse
    {
        $unit->loadMissing('site:id,region_id');
        $planningItems = PlanningItem::query()
            ->whereIn('id', $request->validated('planning_item_ids'))
            ->get()
            ->keyBy('id');
        $assignment = $this->optionalAssignmentPayload($request, $unit->site_id);

        DB::transaction(function () use ($request, $unit, $planningItems, $notifications, $assignment): void {
            $workOrder = WorkOrder::query()->create([
                'unit_id' => $unit->id,
                'site_id' => $unit->site_id,
                'trigger_type' => 'manual',
                'status' => 'open',
                'submitted_by' => $request->user()->id,
                'notes' => $request->string('reason')->toString(),
                ...$assignment,
            ]);

            foreach ($request->validated('planning_item_ids') as $planningItemId) {
                $planning = UnitPlanning::query()->firstOrCreate(
                    ['unit_id' => $unit->id, 'planning_item_id' => $planningItemId],
                    [
                        'last_done_km' => $unit->current_odo,
                        'last_done_date' => now()->toDateString(),
                    ],
                );

                $item = WorkOrderItem::query()->create([
                    'work_order_id' => $workOrder->id,
                    'unit_planning_id' => $planning->id,
                    'planning_item_id' => $planningItemId,
                    'status' => 'replace',
                    'action' => 'replace',
                    'reason' => $request->string('reason')->toString(),
                    'previous_due_km' => $planning->next_due_km,
                    'previous_due_date' => $planning->next_due_date?->toDateString(),
                    'submitted_by' => $request->user()->id,
                ]);

                $item->setRelation('planningItem', $planningItems->get($planningItemId));
                $item->setRelation('workOrder', $workOrder);
                $notifications->taskSubmitted($item, 'replace');
            }
        });

        return redirect()->route('work-orders.index')->with('status', 'Lapor Temuan berhasil diajukan untuk approval SPV.');
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
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            foreach ($submittedItems as $item) {
                if ($item->status === 'pending_create') {
                    $item->update([
                        'status' => $wo->assigned_mechanic_id === null ? 'on_hold' : 'in_progress',
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

            $this->syncWorkOrderStatusFromItems($wo->refresh());
        });

        return redirect()->route('work-orders.show', $wo)->with('status', 'Work order berhasil disetujui.');
    }

    public function reject(Request $request, WorkOrder $wo): RedirectResponse
    {
        Gate::authorize('approve', $wo);
        $this->abortIfCannotAccessSite($request, $wo);

        DB::transaction(function () use ($request, $wo): void {
            $wo->load('items');

            $pendingItems = $wo->items->whereIn('status', ['pending_create', 'replace', 'postpone']);

            if ($pendingItems->isEmpty()) {
                abort(422, 'Work order belum memiliki action yang diajukan.');
            }

            $hasPendingCreate = $pendingItems->contains('status', 'pending_create');

            $pendingItems->each(fn (WorkOrderItem $item) => $item->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]));

            $wo->update([
                'status' => $hasPendingCreate ? 'cancelled' : 'in_progress',
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

        if ($this->unitIsStillBreakdown($wo)) {
            return back()->withErrors(['action' => 'Unit sedang Breakdown. Input KM baru dan isi part yang diganti sebelum melanjutkan aksi normal.']);
        }

        if (! in_array($item->status, ['on_hold', 'blocked', 'overdue'], true)) {
            return back()->withErrors(['action' => 'Hanya item On Hold, Overdue, atau Blocked yang bisa diajukan Replace.']);
        }

        $assignment = $this->optionalAssignmentPayload($request, $wo->site_id);

        DB::transaction(function () use ($request, $wo, $item, $assignment): void {
            if ($assignment !== []) {
                $wo->update($assignment);
            }

            $item->update([
                'status' => 'replace',
                'action' => 'replace',
                'reason' => $request->string('reason')->toString() ?: null,
                'previous_due_km' => $item->unitPlanning?->next_due_km,
                'previous_due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
                'submitted_by' => $request->user()->id,
            ]);
        });

        $notifications->taskSubmitted($item->refresh(), 'replace');

        return redirect()->route('work-orders.show', $wo)->with('status', 'Replace berhasil diajukan untuk approval SPV.');
    }

    public function submitPostpone(SubmitPostponeWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, FleetNotificationService $notifications): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);

        if ($this->unitIsStillBreakdown($wo)) {
            return back()->withErrors(['action' => 'Unit sedang Breakdown. Input KM baru dan isi part yang diganti sebelum melanjutkan aksi normal.']);
        }

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

    public function complete(CompleteWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, FleetNotificationService $notifications): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);

        if ($item->work_order_id !== $wo->id) {
            abort(404);
        }

        if (! in_array($item->status, ['in_progress', 'overdue'], true)) {
            return back()->withErrors(['action' => 'Item harus In Progress atau Overdue sebelum bisa diselesaikan.']);
        }

        DB::transaction(function () use ($request, $wo, $item, $notifications): void {
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

            $this->syncWorkOrderStatusFromItems($wo->refresh());

            $notifications->workOrderItemCompleted($item->refresh());
        });

        if ($request->user()->hasRole(UserRole::Mekanik)) {
            return redirect()->route('mechanic.tasks')->with('status', 'Berhasil disimpan');
        }

        return redirect()->route('work-orders.show', $wo)->with('status', 'Item work order berhasil diselesaikan.');
    }

    private function visibleSites(Request $request)
    {
        $user = $request->user();

        return Site::query()
            ->tap(fn ($query) => AccessScope::applySiteListScope($query, $user))
            ->orderBy('name')
            ->get();
    }

    private function visibleUnits(Request $request)
    {
        $user = $request->user();

        return Unit::query()
            ->with('site:id,name,region')
            ->tap(fn ($query) => AccessScope::applySiteScope($query, $user))
            ->orderBy('current_plate')
            ->get();
    }

    private function visibleMechanics(Request $request)
    {
        $user = $request->user();

        return User::query()
            ->where('role', UserRole::Mekanik->value)
            ->when($user->hasRole(UserRole::Mekanik), fn ($query) => $query->where('site_id', $user->site_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id !== null, fn ($query) => $query->whereHas('site', fn ($siteQuery) => $siteQuery->where('region_id', $user->region_id)))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id === null, fn ($query) => $query->where('site_id', $user->site_id))
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
        $workOrder->setAttribute('completed_items_count', $this->completedItemsCount($workOrder));
        $workOrder->setAttribute('remaining_items_count', $this->remainingItemsCount($workOrder));
        $workOrder->setAttribute('nearest_due', $nearest);
        $workOrder->setAttribute('sub_status', $this->subStatus($workOrder));
        $workOrder->setAttribute('has_overdue_items', $workOrder->status !== 'complete' && $nearest !== null && $nearest['level'] === 'red');
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
            return ['key' => 'assigned', 'label' => 'Mekanik: '.$workOrder->assignedMechanic->name];
        }

        if ($workOrder->items->contains(fn (WorkOrderItem $item): bool => $item->action === 'replace' && $item->status === 'in_progress')) {
            return ['key' => 'waiting_part', 'label' => 'Menunggu Part'];
        }

        return ['key' => 'working', 'label' => 'Dikerjakan'];
    }

    private function completedItemsCount(WorkOrder $workOrder): int
    {
        return $workOrder->items->filter(fn (WorkOrderItem $item): bool => in_array($item->status, ['complete', 'postpone', 'postponed'], true))->count();
    }

    private function remainingItemsCount(WorkOrder $workOrder): int
    {
        return max($workOrder->items->count() - $this->completedItemsCount($workOrder), 0);
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
            ->when($user->hasRole(UserRole::Mekanik), fn ($query) => $query->where('units.site_id', $user->site_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id !== null, fn ($query) => $query->join('sites as scoped_sites', 'scoped_sites.id', '=', 'units.site_id')->where('scoped_sites.region_id', $user->region_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id === null, fn ($query) => $query->where('units.site_id', $user->site_id))
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
                'site_id' => $planning->unit->site_id,
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
     * @return array{assigned_mechanic_id?: int, scheduled_date?: string}
     */
    private function optionalAssignmentPayload(Request $request, int $siteId): array
    {
        $assignment = $request->validate([
            'assigned_mechanic_id' => [
                'nullable',
                'required_with:scheduled_date',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Mekanik->value)->where('site_id', $siteId),
            ],
            'scheduled_date' => ['nullable', 'required_with:assigned_mechanic_id', 'date', 'after_or_equal:today'],
        ]);

        if (($assignment['assigned_mechanic_id'] ?? null) === null) {
            return [];
        }

        return [
            'assigned_mechanic_id' => (int) $assignment['assigned_mechanic_id'],
            'scheduled_date' => $assignment['scheduled_date'],
        ];
    }

    private function syncWorkOrderStatusFromItems(WorkOrder $workOrder): void
    {
        $workOrder->loadMissing('items');

        if ($this->workOrderIsFullyResolved($workOrder)) {
            $workOrder->update(['status' => 'complete']);

            return;
        }

        if ($workOrder->assigned_mechanic_id !== null || $workOrder->items->contains('status', 'in_progress')) {
            $workOrder->update(['status' => 'in_progress']);

            return;
        }

        $workOrder->update(['status' => 'open']);
    }

    private function workOrderIsFullyResolved(WorkOrder $workOrder): bool
    {
        $workOrder->loadMissing('items');

        return $workOrder->items->isNotEmpty()
            && $workOrder->items->every(fn (WorkOrderItem $item): bool => in_array($item->status, ['complete', 'postponed'], true));
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
        return AccessScope::canAccessAllSites($user);
    }

    private function applyCurrentUnitSiteScope($query, User $user)
    {
        if ($this->canAccessAllSites($user)) {
            return $query;
        }

        if ($user->hasRole(UserRole::Mekanik)) {
            return $query->whereHas('unit', fn ($unitQuery) => $unitQuery->where('site_id', $user->site_id));
        }

        if ($user->hasRole(UserRole::PlannerArea)) {
            if ($user->region_id === null) {
                return $query->whereHas('unit', fn ($unitQuery) => $unitQuery->where('site_id', $user->site_id));
            }

            return $query->whereHas('unit.site', fn ($siteQuery) => $siteQuery->where('region_id', $user->region_id));
        }

        return $query;
    }

    private function abortIfCannotAccessSite(Request $request, WorkOrder $workOrder): void
    {
        $user = $request->user();

        if ($this->canAccessAllSites($user)) {
            return;
        }

        $workOrder->loadMissing('unit.site:id,region_id');

        if (! AccessScope::canAccessSite($user, $workOrder->unit?->site_id, $workOrder->unit?->site?->region_id)) {
            abort(403);
        }
    }

    private function abortIfItemDoesNotBelongToWorkOrder(WorkOrder $workOrder, WorkOrderItem $item): void
    {
        if ($item->work_order_id !== $workOrder->id) {
            abort(404);
        }
    }

    private function unitIsStillBreakdown(WorkOrder $workOrder): bool
    {
        $workOrder->loadMissing('unit:id,status');

        return $workOrder->unit?->status === 'breakdown';
    }
}
