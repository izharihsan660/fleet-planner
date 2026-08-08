<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AssignWorkOrderMechanicRequest;
use App\Http\Requests\CompleteBaselineWorkOrderItemRequest;
use App\Http\Requests\CompleteWorkOrderItemRequest;
use App\Http\Requests\StoreManualFindingRequest;
use App\Http\Requests\SubmitPostponeWorkOrderItemRequest;
use App\Http\Requests\SubmitReplaceWorkOrderItemRequest;
use App\Http\Requests\WorkOrderIndexRequest;
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
use App\Services\WorkOrderItemCompletionService;
use App\Services\WorkOrderProgressService;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    private const PRIORITY_PLANNING_ITEM_NAMES = [
        'PM Check / Reguler Services',
        'Service A',
        'Service B',
    ];

    public function __construct(
        private PlanningIntervalResolver $intervalResolver,
        private WorkOrderProgressService $workOrderProgressService,
    ) {}

    public function myTasks(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $user = $request->user();

        abort_unless($user->hasRole(UserRole::Mekanik), 403);

        $items = WorkOrderItem::query()
            ->applicable()
            ->with(['planningItem:id,name', 'workOrder.unit:id,current_plate,current_odo', 'workOrder.site:id,name'])
            ->whereIn('work_order_items.status', ['in_progress', 'overdue'])
            ->withBaseline()
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

    public function index(WorkOrderIndexRequest $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $filters = $request->validated();
        $planningItemIds = collect($filters['planning_item_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->values()->all();
        $includeIncompleteBaseline = ! array_key_exists('include_incomplete_baseline', $filters)
            || $request->boolean('include_incomplete_baseline');
        $sortBy = $filters['sort_by'] ?? 'priority';
        $priorityPlanningItemIds = PlanningItem::query()
            ->whereIn('name', self::PRIORITY_PLANNING_ITEM_NAMES)
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();
        $user = $request->user();
        $thresholds = $this->maintenanceThresholds();

        $workOrderQuery = WorkOrder::query()
            ->select('work_orders.*')
            ->addSelect($this->boardSortColumns())
            ->with([
                'unit.site',
                'site',
                'items' => fn ($query) => $query->applicable()->with(['planningItem', 'unitPlanning']),
                'assignedMechanic:id,name',
            ])
            ->withCount(['items' => fn ($query) => $query->applicable()->where('status', '!=', 'blocked')])
            ->withExists(['items as has_blocked_items' => fn ($query) => $query->applicable()->where('status', 'blocked')])
            ->withExists(['items as has_high_usage_items' => fn ($query) => $query
                ->applicable()
                ->where('triggered_by_high_usage', true)
                ->withBaseline()])
            ->withExists(['items as has_missing_baseline_items' => fn ($query) => $query
                ->applicable()
                ->missingBaseline()])
            ->withExists(['items as has_priority_items' => fn ($query) => $query
                ->applicable()
                ->whereHas('planningItem', fn ($planningItemQuery) => $planningItemQuery->whereIn('name', self::PRIORITY_PLANNING_ITEM_NAMES))])
            ->whereHas('items', fn ($query) => $query->applicable())
            ->whereDoesntHave('items', fn ($query) => $query->applicable()->where('status', 'pending_create'))
            ->tap(fn ($query) => $this->applyCurrentUnitSiteScope($query, $user))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->whereHas('unit', fn ($unitQuery) => $unitQuery->where('site_id', $siteId)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($planningItemIds !== [], fn ($query) => $query->whereHas('items', fn ($items) => $items->applicable()->whereIn('planning_item_id', $planningItemIds)))
            ->when(! $includeIncompleteBaseline, fn ($query) => $query->whereHas('unit', fn (Builder $unitQuery): Builder => $this->applyCompleteUnitBaselineScope($unitQuery)))
            ->when($filters['assignee_id'] ?? null, fn ($query, string $assigneeId) => $query->where('assigned_mechanic_id', $assigneeId));

        $openWorkOrders = $this->applyBoardSort((clone $workOrderQuery)->where('status', 'open'), $sortBy)
            ->paginate(20, ['*'], 'open_page')
            ->withQueryString();
        $inProgressWorkOrders = $this->applyBoardSort((clone $workOrderQuery)->where('status', 'in_progress'), $sortBy)
            ->paginate(20, ['*'], 'in_progress_page')
            ->withQueryString();
        $completeWorkOrders = $this->applyBoardSort((clone $workOrderQuery)
            ->where('status', 'complete')
            ->whereDoesntHave('items', fn ($query) => $query
                ->applicable()
                ->where('status', '!=', 'blocked')
                ->whereNotIn('status', ['complete', 'postponed'])), $sortBy)
            ->paginate(20, ['*'], 'complete_page')
            ->withQueryString();

        $openWorkOrders->getCollection()->each(fn (WorkOrder $workOrder) => $this->appendBoardMeta($workOrder, $thresholds));
        $inProgressWorkOrders->getCollection()->each(fn (WorkOrder $workOrder) => $this->appendBoardMeta($workOrder, $thresholds));
        $completeWorkOrders->getCollection()->each(fn (WorkOrder $workOrder) => $this->appendBoardMeta($workOrder, $thresholds));

        return Inertia::render('WorkOrders/Index', [
            'boardColumns' => [
                'upcoming' => $this->previewItems($request, 'upcoming', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds),
                'preparation' => $this->previewItems($request, 'preparation', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds),
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
            'filters' => [
                'site_id' => isset($filters['site_id']) ? (string) $filters['site_id'] : '',
                'status' => $filters['status'] ?? '',
                'unit_id' => isset($filters['unit_id']) ? (string) $filters['unit_id'] : '',
                'assignee_id' => isset($filters['assignee_id']) ? (string) $filters['assignee_id'] : '',
                'planning_item_ids' => $planningItemIds,
                'include_incomplete_baseline' => $includeIncompleteBaseline,
                'sort_by' => $sortBy,
            ],
        ]);
    }

    public function show(Request $request, WorkOrder $wo): Response
    {
        Gate::authorize('view', $wo);
        $this->abortIfCannotAccessSite($request, $wo);

        $wo->load([
            'unit' => fn ($query) => $query->with(['site', 'unitPlannings:id,unit_id,last_done_km']),
            'site',
            'items' => fn ($query) => $query->applicable()->with(['planningItem', 'unitPlanning']),
            'approvedBy:id,name',
            'assignedMechanic:id,name',
        ]);

        abort_if($wo->items->isEmpty(), 404);

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => WorkOrderResource::make($wo),
            'planningItems' => PlanningItem::query()
                ->whereDoesntHave('unitPlannings', fn ($query) => $query
                    ->where('unit_id', $wo->unit_id)
                    ->where('is_excluded', true))
                ->orderBy('name')
                ->get(['id', 'name']),
            'mechanics' => User::query()
                ->where('role', UserRole::Mekanik->value)
                ->where('site_id', $wo->site_id)
                ->orderBy('name')
                ->get(['id', 'name', 'site_id']),
            'canManageBaselineItems' => $request->user()->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::SpvHo]),
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

        if ($planning->is_excluded) {
            return back()->withErrors(['planning' => 'Planning item ini ditandai Tidak Berlaku untuk unit tersebut.']);
        }

        if ($planning->isBaselineMissing()) {
            return back()->withErrors(['planning' => 'Baseline item belum diisi. Isi baseline sebelum membuat task dari planning.']);
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
        $excludedItemNames = UnitPlanning::query()
            ->where('unit_id', $unit->id)
            ->whereIn('planning_item_id', $request->validated('planning_item_ids'))
            ->where('is_excluded', true)
            ->with('planningItem:id,name')
            ->get()
            ->pluck('planningItem.name')
            ->filter()
            ->values();

        if ($excludedItemNames->isNotEmpty()) {
            return back()->withErrors([
                'planning_item_ids' => 'Item Tidak Berlaku tidak dapat dibuatkan task: '.$excludedItemNames->implode(', ').'.',
            ]);
        }
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

                abort_if($planning->is_excluded, 422, 'Planning item ini ditandai Tidak Berlaku untuk unit tersebut.');

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
            $wo->load([
                'items' => fn ($query) => $query->applicable()->with(['unitPlanning', 'planningItem']),
                'unit',
            ]);

            $submittedCandidates = $wo->items->whereIn('status', ['replace', 'postpone', 'pending_create']);

            if ($submittedCandidates->isEmpty() && $wo->submitted_by === null) {
                $submittedCandidates = $wo->items->where('status', 'on_hold');
            }

            $submittedItems = $submittedCandidates
                ->reject(fn (WorkOrderItem $item): bool => $item->unitPlanning?->isBaselineMissing() ?? true)
                ->values();

            if ($submittedItems->isEmpty() && $submittedCandidates->isNotEmpty()) {
                abort(422, 'Baseline item belum diisi. Isi baseline sebelum menyetujui task ini.');
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
            $wo->load(['items' => fn ($query) => $query->applicable()]);

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
        $this->abortIfPlanningIsExcluded($item);
        $this->abortIfBaselineIsMissing($item);

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
        $this->abortIfPlanningIsExcluded($item);
        $this->abortIfBaselineIsMissing($item);

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

    public function complete(CompleteWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, WorkOrderItemCompletionService $completionService): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);
        $this->abortIfPlanningIsExcluded($item);
        $this->abortIfBaselineIsMissing($item);

        if (! in_array($item->status, ['in_progress', 'overdue'], true)) {
            return back()->withErrors(['action' => 'Item harus In Progress atau Overdue sebelum bisa diselesaikan.']);
        }

        $completionService->complete(
            $wo,
            $item,
            $request->integer('completed_odo'),
            CarbonImmutable::parse($request->validated('completed_date')),
            $request->string('notes')->toString() ?: null,
            $request->user()->id,
        );

        if ($request->user()->hasRole(UserRole::Mekanik)) {
            return redirect()->route('mechanic.tasks')->with('status', 'Berhasil disimpan');
        }

        return redirect()->route('work-orders.show', $wo)->with('status', 'Item work order berhasil diselesaikan.');
    }

    public function completeWithBaseline(CompleteBaselineWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, WorkOrderItemCompletionService $completionService): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);
        $this->abortIfPlanningIsExcluded($item);

        abort_unless($item->unitPlanning !== null && $item->unitPlanning->isBaselineMissing(), 422, 'Baseline item ini sudah diisi. Gunakan aksi work order normal.');
        abort_if($item->status === 'complete', 422, 'Item work order ini sudah selesai.');

        $completionService->complete(
            $wo,
            $item,
            $request->integer('completed_odo'),
            CarbonImmutable::parse($request->validated('completed_date')),
            $request->string('notes')->toString() ?: null,
            $request->user()->id,
            $request->integer('last_done_km'),
            CarbonImmutable::parse($request->validated('last_done_date')),
        );

        return redirect()->route('work-orders.show', $wo)->with('status', 'Baseline historis tersimpan dan item work order berhasil diselesaikan.');
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
        $workOrder->setRelation('items', $workOrder->items
            ->sort(function (WorkOrderItem $left, WorkOrderItem $right): int {
                $leftRank = in_array($left->planningItem?->name, self::PRIORITY_PLANNING_ITEM_NAMES, true) ? 0 : 1;
                $rightRank = in_array($right->planningItem?->name, self::PRIORITY_PLANNING_ITEM_NAMES, true) ? 0 : 1;

                return ($leftRank <=> $rightRank) ?: ($left->id <=> $right->id);
            })
            ->values());

        $nearest = $workOrder->items
            ->map(fn (WorkOrderItem $item): ?array => $this->dueMeta($workOrder->unit, $item->unitPlanning, $thresholds))
            ->filter()
            ->sortBy('sort_value')
            ->first();

        $workOrder->setAttribute('planning_item_names', $workOrder->items->pluck('planningItem.name')->filter()->values()->all());
        $workOrder->setAttribute('completed_items_count', $this->completedItemsCount($workOrder));
        $workOrder->setAttribute('remaining_items_count', $this->remainingItemsCount($workOrder));
        $workOrder->setAttribute('baseline_incomplete_items_count', $workOrder->items->filter(
            fn (WorkOrderItem $item): bool => $item->status !== 'blocked' && ($item->unitPlanning?->isBaselineMissing() ?? true)
        )->count());
        $workOrder->setAttribute('overdue_items_count', $workOrder->items->filter(
            fn (WorkOrderItem $item): bool => $item->status === 'overdue' && ! ($item->unitPlanning?->isBaselineMissing() ?? true)
        )->count());
        $workOrder->setAttribute('rejected_items_count', $workOrder->items->filter(
            fn (WorkOrderItem $item): bool => $item->status === 'rejected' && ! ($item->unitPlanning?->isBaselineMissing() ?? true)
        )->count());
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
        return $this->workOrderProgressService->completedItemsCount($workOrder->items);
    }

    private function remainingItemsCount(WorkOrder $workOrder): int
    {
        return $this->workOrderProgressService->remainingItemsCount($workOrder->items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previewItems(
        WorkOrderIndexRequest $request,
        string $zone,
        array $planningItemIds,
        bool $includeIncompleteBaseline,
        string $sortBy,
        array $priorityPlanningItemIds,
    ) {
        $filters = $request->validated();
        $user = $request->user();
        $thresholds = $this->maintenanceThresholds();
        $pageName = $zone.'_page';

        $items = UnitPlanning::query()
            ->applicable()
            ->withBaseline()
            ->with(['unit.site', 'planningItem'])
            ->join('units', 'units.id', '=', 'unit_plannings.unit_id')
            ->select('unit_plannings.*')
            ->whereDoesntHave('workOrderItems', fn ($query) => $query->whereIn('status', ['on_hold', 'replace', 'postpone', 'in_progress', 'blocked', 'breakdown', 'overdue']))
            ->when($user->hasRole(UserRole::Mekanik), fn ($query) => $query->where('units.site_id', $user->site_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id !== null, fn ($query) => $query->join('sites as scoped_sites', 'scoped_sites.id', '=', 'units.site_id')->where('scoped_sites.region_id', $user->region_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id === null, fn ($query) => $query->where('units.site_id', $user->site_id))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->where('units.site_id', $siteId))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($planningItemIds !== [], fn ($query) => $query->whereIn('planning_item_id', $planningItemIds))
            ->when(! $includeIncompleteBaseline, fn ($query) => $query->whereHas('unit', fn (Builder $unitQuery): Builder => $this->applyCompleteUnitBaselineScope($unitQuery)))
            ->where(fn ($query) => $this->applyPreviewZoneScope($query, $zone, $thresholds));

        $this->applyPreviewSort($items, $sortBy, $priorityPlanningItemIds);

        $items = $items
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
                'is_priority' => in_array($planning->planningItem->name, self::PRIORITY_PLANNING_ITEM_NAMES, true),
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

    /**
     * @return array<string, Builder>
     */
    private function boardSortColumns(): array
    {
        return [
            'nearest_due_date_sort' => UnitPlanning::query()
                ->select('unit_plannings.next_due_date')
                ->join('work_order_items as due_date_items', 'due_date_items.unit_planning_id', '=', 'unit_plannings.id')
                ->whereColumn('due_date_items.work_order_id', 'work_orders.id')
                ->where('unit_plannings.is_excluded', false)
                ->whereNotNull('unit_plannings.next_due_date')
                ->orderBy('unit_plannings.next_due_date')
                ->limit(1),
            'nearest_due_km_sort' => UnitPlanning::query()
                ->select('unit_plannings.next_due_km')
                ->join('work_order_items as due_km_items', 'due_km_items.unit_planning_id', '=', 'unit_plannings.id')
                ->whereColumn('due_km_items.work_order_id', 'work_orders.id')
                ->where('unit_plannings.is_excluded', false)
                ->whereNotNull('unit_plannings.next_due_km')
                ->orderBy('unit_plannings.next_due_km')
                ->limit(1),
        ];
    }

    private function applyBoardSort(Builder $query, string $sortBy): Builder
    {
        if ($sortBy === 'due_date') {
            return $query
                ->orderByRaw('nearest_due_date_sort IS NULL')
                ->orderBy('nearest_due_date_sort')
                ->orderByRaw('nearest_due_km_sort IS NULL')
                ->orderBy('nearest_due_km_sort')
                ->orderByDesc('work_orders.created_at')
                ->orderByDesc('work_orders.id');
        }

        if ($sortBy === 'due_km') {
            return $query
                ->orderByRaw('nearest_due_km_sort IS NULL')
                ->orderBy('nearest_due_km_sort')
                ->orderByRaw('nearest_due_date_sort IS NULL')
                ->orderBy('nearest_due_date_sort')
                ->orderByDesc('work_orders.created_at')
                ->orderByDesc('work_orders.id');
        }

        return $query
            ->orderByDesc('has_priority_items')
            ->orderByRaw('nearest_due_date_sort IS NULL')
            ->orderBy('nearest_due_date_sort')
            ->orderByRaw('nearest_due_km_sort IS NULL')
            ->orderBy('nearest_due_km_sort')
            ->orderByDesc('work_orders.created_at')
            ->orderByDesc('work_orders.id');
    }

    private function applyPreviewSort(Builder $query, string $sortBy, array $priorityPlanningItemIds): void
    {
        if ($sortBy === 'priority' && $priorityPlanningItemIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($priorityPlanningItemIds), '?'));
            $query->orderByRaw("CASE WHEN unit_plannings.planning_item_id IN ({$placeholders}) THEN 0 ELSE 1 END", $priorityPlanningItemIds);
        }

        if ($sortBy === 'due_km') {
            $query
                ->orderByRaw('CASE WHEN unit_plannings.next_due_km IS NULL THEN 1 ELSE 0 END')
                ->orderBy('unit_plannings.next_due_km')
                ->orderByRaw('CASE WHEN unit_plannings.next_due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('unit_plannings.next_due_date')
                ->orderBy('unit_plannings.id');

            return;
        }

        $query
            ->orderByRaw('CASE WHEN unit_plannings.next_due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('unit_plannings.next_due_date')
            ->orderByRaw('CASE WHEN unit_plannings.next_due_km IS NULL THEN 1 ELSE 0 END')
            ->orderBy('unit_plannings.next_due_km')
            ->orderBy('unit_plannings.id');
    }

    private function applyCompleteUnitBaselineScope(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('has_odometer_reading'), true)
            ->whereHas('unitPlannings', fn (Builder $planningQuery): Builder => $planningQuery
                ->where($planningQuery->qualifyColumn('last_done_km'), '!=', 0));
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
        if ($planning->isBaselineMissing()) {
            return false;
        }

        $today = CarbonImmutable::today();
        $matchesKm = $planning->next_due_km !== null
            && $planning->unit->current_odo >= ($planning->next_due_km - $km);
        $matchesDate = $planning->next_due_date !== null
            && $today->greaterThanOrEqualTo(CarbonImmutable::parse($planning->next_due_date)->subDays($days));

        return $matchesKm || $matchesDate;
    }

    private function dueMeta(?Unit $unit, ?UnitPlanning $planning, array $thresholds): ?array
    {
        if ($unit === null || $planning === null || $planning->isBaselineMissing()) {
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
        $this->workOrderProgressService->sync($workOrder);
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

    private function abortIfPlanningIsExcluded(WorkOrderItem $item): void
    {
        $item->loadMissing('unitPlanning:id,is_excluded,last_done_km,last_done_date');

        abort_if($item->unitPlanning?->is_excluded, 422, 'Planning item ini ditandai Tidak Berlaku untuk unit tersebut.');
    }

    private function abortIfBaselineIsMissing(WorkOrderItem $item): void
    {
        abort_if($item->unitPlanning?->isBaselineMissing() ?? true, 422, 'Baseline item belum diisi. Isi baseline sebelum memproses task ini.');
    }

    private function unitIsStillBreakdown(WorkOrder $workOrder): bool
    {
        $workOrder->loadMissing('unit:id,status');

        return $workOrder->unit?->status === 'breakdown';
    }
}
