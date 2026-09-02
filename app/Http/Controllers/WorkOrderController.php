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
use App\Services\CompletionBackdatePolicy;
use App\Services\FleetNotificationService;
use App\Services\PlanningIntervalResolver;
use App\Services\WorkOrderItemCompletionService;
use App\Services\WorkOrderProgressService;
use App\Support\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    /**
     * Item yang menunggu tindakan dan belum dikerjakan mekanik.
     *
     * @var array<int, string>
     */
    private const ON_HOLD_ITEM_STATUSES = [
        'on_hold',
        'overdue',
        'blocked',
        'breakdown',
        'replace',
        'postpone',
    ];

    /**
     * Item rejected hanya perlu ditindak ulang bila pengajuannya Replace/Postpone.
     *
     * @var array<int, string>
     */
    private const RESUBMITTABLE_REJECTED_ACTIONS = ['replace', 'postpone'];

    /**
     * Tugas mekanik ditentukan per item, bukan dari status WO induknya: WO turun
     * ke 'open' begitu item in_progress terakhir selesai, padahal item overdue
     * lain di WO yang sama masih jadi tanggung jawab mekanik yang sama.
     * Sama dengan daftar status yang boleh di-complete pada self::complete().
     *
     * @var array<int, string>
     */
    private const MECHANIC_TASK_ITEM_STATUSES = ['in_progress', 'overdue'];

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
            ->whereIn('work_order_items.status', self::MECHANIC_TASK_ITEM_STATUSES)
            ->where(function (Builder $query): void {
                $query
                    ->withBaseline()
                    ->orWhere(function (Builder $baselineReplaceQuery): void {
                        $baselineReplaceQuery
                            ->missingBaseline()
                            ->where('work_order_items.action', 'replace')
                            ->whereNotNull('work_order_items.approved_at');
                    });
            })
            ->whereHas('workOrder', fn ($query) => $query->where('assigned_mechanic_id', $user->id))
            ->select('work_order_items.*')
            ->orderByRaw('CASE WHEN work_order_items.scheduled_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('work_order_items.scheduled_date')
            ->orderBy('work_order_items.id')
            ->get()
            ->map(fn (WorkOrderItem $item): array => [
                'id' => $item->id,
                'work_order_id' => $item->work_order_id,
                'unit_name' => $item->workOrder?->unit?->current_plate ?? '-',
                'item_name' => $item->planningItem?->name ?? 'Pekerjaan maintenance',
                'scheduled_date' => $item->scheduled_date?->toDateString(),
                'planned_date' => $item->planned_date?->toDateString(),
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

        return Inertia::render('WorkOrders/Index', [
            'boardColumns' => [
                'upcoming' => $this->previewItems($request, 'upcoming', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds),
                'preparation' => $this->previewItems($request, 'preparation', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds),
                'open' => $this->boardItems($request, 'open', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds, $thresholds),
                'in_progress' => $this->boardItems($request, 'in_progress', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds, $thresholds),
                'complete' => $this->boardItems($request, 'complete', $planningItemIds, $includeIncompleteBaseline, $sortBy, $priorityPlanningItemIds, $thresholds),
            ],
            'sites' => SiteResource::collection($this->visibleSites($request)),
            'units' => UnitResource::collection($this->visibleUnits($request)),
            'mechanics' => $this->visibleMechanics($request),
            'planningItems' => PlanningItem::query()->orderBy('name')->get(['id', 'name']),
            'backdateThresholds' => app(CompletionBackdatePolicy::class)->toArray(),
            'canCreateUpcomingTask' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]),
            'canAssignMechanic' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]),
            'canSubmitItemActions' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]),
            'canConditionItems' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::Mekanik]),
            'filters' => [
                'search' => $this->searchTerm($request),
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
            'items' => fn ($query) => $query->applicable()->forWorkOrderDetail()->with(['planningItem', 'unitPlanning']),
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
            'canBackdateCompletion' => $request->user()->hasRole(UserRole::Superadmin),
            'backdateThresholds' => app(CompletionBackdatePolicy::class)->toArray(),
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
                ...$assignment['work_order'],
            ]);

            $item = WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => 'pending_create',
                'action' => 'create_task',
                'submitted_by' => $request->user()->id,
                ...$assignment['item'],
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
                ...$assignment['work_order'],
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
                    ...$assignment['item'],
                ]);

                $item->setRelation('planningItem', $planningItems->get($planningItemId));
                $item->setRelation('workOrder', $workOrder);
                $notifications->taskSubmitted($item, 'replace');
            }
        });

        return redirect()->route('work-orders.index')->with('status', 'Lapor Temuan berhasil diajukan untuk approval SPV.');
    }

    /**
     * Mekanik disimpan di WO sebagai penanggung jawab unit, tanggal disimpan di
     * item yang dijadwalkan. Menjadwalkan item kedua tidak lagi menimpa jadwal
     * item pertama pada unit yang sama.
     */
    public function assignItem(AssignWorkOrderMechanicRequest $request, WorkOrder $wo, WorkOrderItem $item): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);

        if ($item->approved_at === null) {
            return back()->withErrors(['assigned_mechanic_id' => 'Item harus disetujui SPV sebelum dijadwalkan.']);
        }

        if ($item->status === 'complete') {
            return back()->withErrors(['scheduled_date' => 'Item yang sudah selesai tidak bisa dijadwalkan ulang.']);
        }

        $assignment = $request->validated();

        DB::transaction(function () use ($wo, $item, $assignment): void {
            $wo->update(['assigned_mechanic_id' => (int) $assignment['assigned_mechanic_id']]);

            $item->update([
                'scheduled_date' => $assignment['scheduled_date'],
                ...($item->status === 'on_hold' ? ['status' => 'in_progress'] : []),
            ]);
        });

        $this->syncWorkOrderStatusFromItems($wo->refresh());

        return back()->with('status', 'Jadwal item berhasil disimpan.');
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
                ->reject(fn (WorkOrderItem $item): bool => ($item->unitPlanning?->isBaselineMissing() ?? true)
                    && ! $item->isBaselineReplaceSubmission())
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
                        'status' => $this->approvedItemStatus($wo, $item),
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
                    'status' => $this->approvedItemStatus($wo, $item),
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

            $pendingItems->each(fn (WorkOrderItem $item) => $item->update([
                'status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]));

            $wo->update([
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            $this->syncWorkOrderStatusFromItems($wo->refresh());
        });

        return redirect()->route('work-orders.index')->with('status', 'Pengajuan task ditolak.');
    }

    public function submitReplace(SubmitReplaceWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, FleetNotificationService $notifications): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);
        $this->abortIfPlanningIsExcluded($item);

        if ($this->unitIsStillBreakdown($wo)) {
            return back()->withErrors(['action' => 'Unit sedang Breakdown. Input KM baru dan isi part yang diganti sebelum melanjutkan aksi normal.']);
        }

        if (! in_array($item->status, ['on_hold', 'blocked', 'overdue', 'rejected'], true)) {
            return back()->withErrors(['action' => 'Hanya item On Hold, Overdue, Blocked, atau Rejected yang bisa diajukan Replace.']);
        }

        $assignment = $this->optionalAssignmentPayload($request, $wo->site_id);

        DB::transaction(function () use ($request, $wo, $item, $assignment): void {
            $this->reopenCancelledWorkOrder($wo);

            if ($assignment['work_order'] !== []) {
                $wo->update($assignment['work_order']);
            }

            $item->update([
                'status' => 'replace',
                'action' => 'replace',
                'reason' => $request->string('reason')->toString() ?: null,
                'previous_due_km' => $item->unitPlanning?->next_due_km,
                'previous_due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
                'planned_date' => $request->date('planned_date')?->toDateString(),
                'submitted_by' => $request->user()->id,
                ...$assignment['item'],
            ]);
        });

        $this->syncWorkOrderStatusFromItems($wo->refresh());
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

        if (! in_array($item->status, ['on_hold', 'blocked', 'overdue', 'rejected'], true)) {
            return back()->withErrors(['action' => 'Hanya item On Hold, Overdue, Blocked, atau Rejected yang bisa diajukan Postpone.']);
        }

        DB::transaction(function () use ($request, $wo, $item): void {
            $this->reopenCancelledWorkOrder($wo);

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
        });

        $this->syncWorkOrderStatusFromItems($wo->refresh());
        $notifications->taskSubmitted($item->refresh(), 'postpone');

        return redirect()->route('work-orders.show', $wo)->with('status', 'Postpone berhasil diajukan untuk approval SPV.');
    }

    public function complete(CompleteWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item, WorkOrderItemCompletionService $completionService): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);
        $this->abortIfPlanningIsExcluded($item);

        if (! $item->isApprovedBaselineReplace()) {
            $this->abortIfBaselineIsMissing($item);
        }

        if (! in_array($item->status, self::MECHANIC_TASK_ITEM_STATUSES, true)) {
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

    /**
     * Kanban On Hold / In Progress / Complete memakai 1 card per work_order_item.
     *
     * @param  array<int, int>  $planningItemIds
     * @param  array<int, int>  $priorityPlanningItemIds
     * @param  array{warning_days: int, warning_km: int, ancang_ancang_days: int, ancang_ancang_km: int, upcoming_days: int, upcoming_km: int}  $thresholds
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function boardItems(
        WorkOrderIndexRequest $request,
        string $column,
        array $planningItemIds,
        bool $includeIncompleteBaseline,
        string $sortBy,
        array $priorityPlanningItemIds,
        array $thresholds,
    ): array {
        $filters = $request->validated();
        $user = $request->user();
        $statusFilter = $filters['status'] ?? null;

        $query = WorkOrderItem::query()
            ->select('work_order_items.*')
            ->join('work_orders', 'work_orders.id', '=', 'work_order_items.work_order_id')
            ->join('units', 'units.id', '=', 'work_orders.unit_id')
            ->join('unit_plannings', 'unit_plannings.id', '=', 'work_order_items.unit_planning_id')
            ->where('unit_plannings.is_excluded', false)
            ->with([
                'planningItem:id,name',
                'unitPlanning',
                'workOrder.unit.site',
                'workOrder.assignedMechanic:id,name',
            ])
            ->when($statusFilter !== null && $statusFilter !== $column, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->tap(fn (Builder $query) => $this->applyBoardItemSiteScope($query, $user))
            ->when($filters['site_id'] ?? null, fn (Builder $query, string $siteId): Builder => $query->where('units.site_id', $siteId))
            ->when($filters['unit_id'] ?? null, fn (Builder $query, string $unitId): Builder => $query->where('work_orders.unit_id', $unitId))
            ->when($filters['assignee_id'] ?? null, fn (Builder $query, string $assigneeId): Builder => $query->where('work_orders.assigned_mechanic_id', $assigneeId))
            ->when($planningItemIds !== [], fn (Builder $query): Builder => $query->whereIn('work_order_items.planning_item_id', $planningItemIds))
            ->when(! $includeIncompleteBaseline, fn (Builder $query) => $this->applyJoinedCompleteBaselineScope($query))
            ->when($this->searchTerm($request) !== '', fn (Builder $query) => $this->applySearchScope($query, $this->searchTerm($request)))
            ->tap(fn (Builder $query) => $this->applyBoardItemColumnScope($query, $column));

        $this->applyBoardItemSort($query, $column, $sortBy, $priorityPlanningItemIds);

        $items = $query
            ->paginate(20, ['work_order_items.*'], $column.'_page')
            ->withQueryString();

        $unitIds = collect($items->items())
            ->map(fn (WorkOrderItem $item): ?int => $item->workOrder?->unit_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $actionableCounts = $this->actionableItemCountsByUnit($unitIds);

        $items->through(fn (WorkOrderItem $item): array => $this->boardItemPayload($item, $thresholds, $actionableCounts));

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
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    private function applyBoardItemSiteScope(Builder $query, User $user): Builder
    {
        if ($this->canAccessAllSites($user)) {
            return $query;
        }

        if ($user->hasRole(UserRole::Mekanik)) {
            return $query->where('units.site_id', $user->site_id);
        }

        if ($user->hasRole(UserRole::PlannerArea)) {
            if ($user->region_id === null) {
                return $query->where('units.site_id', $user->site_id);
            }

            return $query->whereIn('units.site_id', Site::query()->select('id')->where('region_id', $user->region_id));
        }

        return $query;
    }

    private function searchTerm(Request $request): string
    {
        return trim((string) $request->input('search', ''));
    }

    /**
     * Pencarian cepat lintas kolom: cocok ke sebagian plat unit atau sebagian
     * nama item maintenance, tanpa membedakan huruf besar/kecil maupun spasi
     * pada plat. Dipakai baik untuk query item board maupun preview planning
     * karena keduanya sudah men-join tabel units dan punya relasi planningItem.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applySearchScope(Builder $query, string $search): Builder
    {
        $needle = '%'.mb_strtolower($search).'%';
        $compactNeedle = '%'.str_replace(' ', '', mb_strtolower($search)).'%';

        return $query->where(fn (Builder $searchQuery): Builder => $searchQuery
            ->whereRaw('lower(units.current_plate) like ?', [$needle])
            ->orWhereRaw("replace(lower(units.current_plate), ' ', '') like ?", [$compactNeedle])
            ->orWhereHas('planningItem', fn (Builder $planningItemQuery): Builder => $planningItemQuery
                ->whereRaw('lower(planning_items.name) like ?', [$needle])));
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    private function applyJoinedCompleteBaselineScope(Builder $query): Builder
    {
        return $query
            ->where('units.has_odometer_reading', true)
            ->whereExists(fn ($baselineQuery) => $baselineQuery
                ->selectRaw('1')
                ->from('unit_plannings as baseline_plannings')
                ->whereColumn('baseline_plannings.unit_id', 'units.id')
                ->whereNotNull('baseline_plannings.last_done_km')
                ->where('baseline_plannings.last_done_km', '!=', 0));
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    private function applyBoardItemColumnScope(Builder $query, string $column): Builder
    {
        $today = CarbonImmutable::today()->toDateString();

        if ($column === 'complete') {
            return $query->where('work_order_items.status', 'complete');
        }

        if ($column === 'in_progress') {
            return $query
                ->where('work_order_items.status', 'in_progress')
                ->whereNotNull('work_orders.assigned_mechanic_id')
                ->whereNotNull('work_order_items.scheduled_date')
                ->whereDate('work_order_items.scheduled_date', '<=', $today);
        }

        return $query->where(fn (Builder $onHoldQuery): Builder => $onHoldQuery
            ->whereIn('work_order_items.status', self::ON_HOLD_ITEM_STATUSES)
            ->orWhere(fn (Builder $rejectedQuery): Builder => $rejectedQuery
                ->where('work_order_items.status', 'rejected')
                ->whereIn('work_order_items.action', self::RESUBMITTABLE_REJECTED_ACTIONS))
            ->orWhere(fn (Builder $notStartedQuery): Builder => $notStartedQuery
                ->where('work_order_items.status', 'in_progress')
                ->where(fn (Builder $scheduleQuery): Builder => $scheduleQuery
                    ->whereNull('work_orders.assigned_mechanic_id')
                    ->orWhereNull('work_order_items.scheduled_date')
                    ->orWhereDate('work_order_items.scheduled_date', '>', $today))));
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @param  array<int, int>  $priorityPlanningItemIds
     */
    private function applyBoardItemSort(Builder $query, string $column, string $sortBy, array $priorityPlanningItemIds): void
    {
        if ($column === 'complete') {
            $query
                ->orderByRaw('CASE WHEN work_order_items.completed_date IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('work_order_items.completed_date')
                ->orderByDesc('work_order_items.id');

            return;
        }

        if ($sortBy === 'priority' && $priorityPlanningItemIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($priorityPlanningItemIds), '?'));
            $query->orderByRaw("CASE WHEN work_order_items.planning_item_id IN ({$placeholders}) THEN 0 ELSE 1 END", $priorityPlanningItemIds);
        }

        if ($sortBy === 'due_km') {
            $query
                ->orderByRaw('CASE WHEN unit_plannings.next_due_km IS NULL THEN 1 ELSE 0 END')
                ->orderBy('unit_plannings.next_due_km')
                ->orderByRaw('CASE WHEN unit_plannings.next_due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('unit_plannings.next_due_date')
                ->orderBy('work_order_items.id');

            return;
        }

        $query
            ->orderByRaw('CASE WHEN unit_plannings.next_due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('unit_plannings.next_due_date')
            ->orderByRaw('CASE WHEN unit_plannings.next_due_km IS NULL THEN 1 ELSE 0 END')
            ->orderBy('unit_plannings.next_due_km')
            ->orderBy('work_order_items.id');
    }

    /**
     * Jumlah item yang masih perlu ditindak per unit, tanpa terpengaruh filter tampilan.
     *
     * @param  array<int, int>  $unitIds
     * @return Collection<int, int>
     */
    private function actionableItemCountsByUnit(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return WorkOrderItem::query()
            ->join('work_orders', 'work_orders.id', '=', 'work_order_items.work_order_id')
            ->join('unit_plannings', 'unit_plannings.id', '=', 'work_order_items.unit_planning_id')
            ->where('unit_plannings.is_excluded', false)
            ->whereIn('work_orders.unit_id', $unitIds)
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('work_order_items.status', [...self::ON_HOLD_ITEM_STATUSES, 'in_progress'])
                ->orWhere(fn (Builder $rejectedQuery): Builder => $rejectedQuery
                    ->where('work_order_items.status', 'rejected')
                    ->whereIn('work_order_items.action', self::RESUBMITTABLE_REJECTED_ACTIONS)))
            ->groupBy('work_orders.unit_id')
            ->selectRaw('work_orders.unit_id as unit_id, count(*) as actionable_count')
            ->pluck('actionable_count', 'unit_id');
    }

    private function isActionableItem(WorkOrderItem $item): bool
    {
        if ($item->status === 'rejected') {
            return in_array($item->action, self::RESUBMITTABLE_REJECTED_ACTIONS, true);
        }

        return in_array($item->status, [...self::ON_HOLD_ITEM_STATUSES, 'in_progress'], true);
    }

    private function boardItemPhase(WorkOrderItem $item): string
    {
        if ($item->status === 'complete') {
            return 'complete';
        }

        if ($item->status === 'in_progress' && $item->workHasStarted()) {
            return 'in_progress';
        }

        return 'on_hold';
    }

    /**
     * Item hanya masuk In Progress kalau unitnya sudah punya penanggung jawab
     * dan item ini sudah punya tanggalnya sendiri.
     */
    private function approvedItemStatus(WorkOrder $workOrder, WorkOrderItem $item): string
    {
        return $workOrder->assigned_mechanic_id !== null && $item->scheduled_date !== null
            ? 'in_progress'
            : 'on_hold';
    }

    /**
     * @param  array{warning_days: int, warning_km: int, ancang_ancang_days: int, ancang_ancang_km: int, upcoming_days: int, upcoming_km: int}  $thresholds
     * @param  Collection<int, int>  $actionableCounts
     * @return array<string, mixed>
     */
    private function boardItemPayload(WorkOrderItem $item, array $thresholds, Collection $actionableCounts): array
    {
        $workOrder = $item->workOrder;
        $unit = $workOrder?->unit;
        $due = $this->dueMeta($unit, $item->unitPlanning, $thresholds);
        $unitActionableCount = (int) $actionableCounts->get($unit?->id, 0);
        $otherActionableCount = max($unitActionableCount - ($this->isActionableItem($item) ? 1 : 0), 0);

        return [
            'id' => $item->id,
            'work_order_id' => $item->work_order_id,
            'unit_id' => $unit?->id,
            'site_id' => $unit?->site_id,
            'unit_plate' => $unit?->current_plate ?? '-',
            'site_name' => $unit?->site?->name,
            'item_name' => $item->planningItem?->name ?? 'Pekerjaan perawatan',
            'due_km' => $item->unitPlanning?->next_due_km,
            'due_date' => $item->unitPlanning?->next_due_date?->toDateString(),
            'due' => $due,
            'phase' => $this->boardItemPhase($item),
            'badges' => $this->boardItemBadges($item, $due),
            'other_active_items_count' => $otherActionableCount,
            'is_priority' => in_array($item->planningItem?->name, self::PRIORITY_PLANNING_ITEM_NAMES, true),
            'status' => $item->status,
            'action' => $item->action,
            'baseline_missing' => $item->unitPlanning?->isBaselineMissing() ?? true,
            'unit_breakdown' => $unit?->status === 'breakdown',
            'unit_current_odo' => (int) ($unit?->current_odo ?? 0),
            'reason' => $item->reason,
            'new_due_km' => $item->new_due_km,
            'new_due_date' => $item->new_due_date?->toDateString(),
            'completed_date' => $item->completed_date?->toDateString(),
            'assigned_mechanic_id' => $workOrder?->assigned_mechanic_id,
            'scheduled_date' => $item->scheduled_date?->toDateString(),
            'can_schedule' => $item->approved_at !== null && $item->status !== 'complete',
        ];
    }

    /**
     * Badge card memakai bahasa sehari-hari, tanpa nama status internal.
     *
     * @param  array<string, mixed>|null  $due
     * @return array<int, array{key: string, tone: string, label: string}>
     */
    private function boardItemBadges(WorkOrderItem $item, ?array $due): array
    {
        $badges = [];

        if ($item->status !== 'complete' && $due !== null && $due['level'] === 'red') {
            $badges[] = [
                'key' => 'late',
                'tone' => 'danger',
                'label' => $due['overdue_days'] !== null
                    ? 'Terlambat '.$due['overdue_days'].' hari'
                    : 'Lewat '.number_format((int) $due['overdue_km'], 0, ',', '.').' KM',
            ];
        }

        if ($item->unitPlanning?->isBaselineMissing() ?? true) {
            $badges[] = ['key' => 'baseline_missing', 'tone' => 'neutral', 'label' => 'Data awal belum diisi'];
        }

        $phaseBadge = $this->boardItemPhaseBadge($item);

        if ($phaseBadge !== null) {
            $badges[] = $phaseBadge;
        }

        return $badges;
    }

    /**
     * @return array{key: string, tone: string, label: string}|null
     */
    private function boardItemPhaseBadge(WorkOrderItem $item): ?array
    {
        if (in_array($item->status, ['replace', 'postpone', 'pending_create'], true)) {
            return ['key' => 'waiting_approval', 'tone' => 'info', 'label' => 'Menunggu persetujuan'];
        }

        if ($item->status === 'rejected') {
            return ['key' => 'rejected', 'tone' => 'rejected', 'label' => 'Pengajuan ditolak, ajukan ulang'];
        }

        if ($item->status === 'blocked') {
            return ['key' => 'waiting_part', 'tone' => 'warning', 'label' => 'Menunggu part'];
        }

        if ($item->status === 'breakdown') {
            return ['key' => 'breakdown', 'tone' => 'danger', 'label' => 'Unit rusak, belum bisa dikerjakan'];
        }

        if ($item->status === 'complete') {
            return [
                'key' => 'done',
                'tone' => 'safe',
                'label' => 'Selesai'.($item->completed_date === null ? '' : ' '.$item->completed_date->toDateString()),
            ];
        }

        if ($item->status === 'on_hold' && $item->approved_at !== null) {
            return ['key' => 'waiting_schedule', 'tone' => 'neutral', 'label' => 'Sudah disetujui, menunggu jadwal'];
        }

        if ($item->status === 'in_progress') {
            $workOrder = $item->workOrder;

            if ($item->workHasStarted()) {
                return ['key' => 'working', 'tone' => 'info', 'label' => 'Sedang dikerjakan'];
            }

            if ($workOrder?->assignedMechanic !== null && $item->scheduled_date !== null) {
                return [
                    'key' => 'scheduled',
                    'tone' => 'neutral',
                    'label' => $workOrder->assignedMechanic->name.' - '.$item->scheduled_date->toDateString(),
                ];
            }

            if ($item->action === 'replace') {
                return ['key' => 'waiting_part', 'tone' => 'warning', 'label' => 'Menunggu part'];
            }

            return ['key' => 'waiting_mechanic', 'tone' => 'neutral', 'label' => 'Menunggu jadwal mekanik'];
        }

        return ['key' => 'not_started', 'tone' => 'neutral', 'label' => 'Belum ada tindakan'];
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
            ->whereDoesntHave('workOrderItems', fn (Builder $query): Builder => $this->applyPlanningPreviewBlockingScope($query))
            ->when($user->hasRole(UserRole::Mekanik), fn ($query) => $query->where('units.site_id', $user->site_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id !== null, fn ($query) => $query->join('sites as scoped_sites', 'scoped_sites.id', '=', 'units.site_id')->where('scoped_sites.region_id', $user->region_id))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id === null, fn ($query) => $query->where('units.site_id', $user->site_id))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->where('units.site_id', $siteId))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($planningItemIds !== [], fn ($query) => $query->whereIn('planning_item_id', $planningItemIds))
            ->when(! $includeIncompleteBaseline, fn ($query) => $query->whereHas('unit', fn (Builder $unitQuery): Builder => $this->applyCompleteUnitBaselineScope($unitQuery)))
            ->when($this->searchTerm($request) !== '', fn (Builder $query) => $this->applySearchScope($query, $this->searchTerm($request)))
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
                ->withBaseline());
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
            'label' => $isOverdue ? 'Terlambat '.abs((int) ($daysUntilDue ?? 0)).' hari' : ($isWarning ? 'Peringatan' : 'Aman'),
            'overdue_days' => $daysUntilDue !== null && $daysUntilDue < 0 ? abs((int) $daysUntilDue) : null,
            'overdue_km' => $kmUntilDue !== null && $kmUntilDue < 0 ? abs((int) $kmUntilDue) : null,
            'sort_value' => min($daysUntilDue ?? 999999, $kmUntilDue ?? 999999),
        ];
    }

    private function hasActiveItem(UnitPlanning $planning): bool
    {
        return $planning->workOrderItems()
            ->where(fn (Builder $query): Builder => $this->applyActiveWorkOrderItemScope($query))
            ->exists();
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    private function applyActiveWorkOrderItemScope(Builder $query): Builder
    {
        return $query->where(fn (Builder $activeQuery): Builder => $activeQuery
            ->whereIn('status', ['on_hold', 'pending_create', 'replace', 'postpone', 'in_progress', 'blocked', 'breakdown', 'overdue'])
            ->orWhere(fn (Builder $rejectedQuery): Builder => $rejectedQuery
                ->where('status', 'rejected')
                ->whereIn('action', ['replace', 'postpone'])));
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    private function applyPlanningPreviewBlockingScope(Builder $query): Builder
    {
        return $query->where(fn (Builder $activeQuery): Builder => $activeQuery
            ->whereIn('status', ['on_hold', 'replace', 'postpone', 'in_progress', 'blocked', 'breakdown', 'overdue'])
            ->orWhere(fn (Builder $rejectedQuery): Builder => $rejectedQuery
                ->where('status', 'rejected')
                ->whereIn('action', ['replace', 'postpone'])));
    }

    /**
     * Penugasan terbelah dua tabel: mekanik penanggung jawab ke WO, tanggal
     * pengerjaan ke item. Keduanya opsional dan berdiri sendiri — penanggung
     * jawab boleh ditetapkan lebih dulu, jadwal tiap item menyusul.
     *
     * @return array{work_order: array<string, int>, item: array<string, string>}
     */
    private function optionalAssignmentPayload(Request $request, int $siteId): array
    {
        $assignment = $request->validate([
            'assigned_mechanic_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Mekanik->value)->where('site_id', $siteId),
            ],
            'scheduled_date' => ['nullable', 'date'],
        ]);

        return [
            'work_order' => ($assignment['assigned_mechanic_id'] ?? null) === null
                ? []
                : ['assigned_mechanic_id' => (int) $assignment['assigned_mechanic_id']],
            'item' => ($assignment['scheduled_date'] ?? null) === null
                ? []
                : ['scheduled_date' => $assignment['scheduled_date']],
        ];
    }

    private function syncWorkOrderStatusFromItems(WorkOrder $workOrder): void
    {
        $this->workOrderProgressService->sync($workOrder);
    }

    private function reopenCancelledWorkOrder(WorkOrder $workOrder): void
    {
        if ($workOrder->status === 'cancelled') {
            $workOrder->update(['status' => 'open']);
        }
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
