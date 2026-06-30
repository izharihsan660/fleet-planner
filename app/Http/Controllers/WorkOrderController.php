<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\CompleteWorkOrderItemRequest;
use App\Http\Resources\SiteResource;
use App\Http\Resources\UnitResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\Site;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkOrderController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $filters = $request->only(['site_id', 'status', 'unit_id']);
        $user = $request->user();

        $workOrders = WorkOrder::query()
            ->with(['unit.site', 'site'])
            ->withCount('items')
            ->withExists(['items as has_blocked_items' => fn ($query) => $query->where('status', 'blocked')])
            ->withExists(['items as has_high_usage_items' => fn ($query) => $query->where('triggered_by_high_usage', true)])
            ->when(! $this->canAccessAllSites($user), fn ($query) => $query->where('site_id', $user->site_id))
            ->when($filters['site_id'] ?? null, fn ($query, string $siteId) => $query->where('site_id', $siteId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['unit_id'] ?? null, fn ($query, string $unitId) => $query->where('unit_id', $unitId))
            ->latest()
            ->get();

        return Inertia::render('WorkOrders/Index', [
            'workOrders' => WorkOrderResource::collection($workOrders),
            'sites' => SiteResource::collection($this->visibleSites($request)),
            'units' => UnitResource::collection($this->visibleUnits($request)),
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
            ])),
        ]);
    }

    public function approve(Request $request, WorkOrder $wo): RedirectResponse
    {
        Gate::authorize('approve', $wo);
        $this->abortIfCannotAccessSite($request, $wo);

        DB::transaction(function () use ($request, $wo): void {
            $wo->update([
                'status' => 'in_progress',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            $wo->items()->where('status', 'on_hold')->update([
                'status' => 'in_progress',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('work-orders.show', $wo)->with('status', 'Work order berhasil disetujui.');
    }

    public function complete(CompleteWorkOrderItemRequest $request, WorkOrder $wo, WorkOrderItem $item): RedirectResponse
    {
        $this->abortIfCannotAccessSite($request, $wo);

        if ($item->work_order_id !== $wo->id) {
            abort(404);
        }

        DB::transaction(function () use ($request, $wo, $item): void {
            $item->load('unitPlanning.planningItem');

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

            $unitPlanning->update([
                'last_done_km' => $completedOdo,
                'last_done_date' => $completedDate->toDateString(),
                'next_due_km' => $completedOdo + $planningItem->interval_km,
                'next_due_date' => $completedDate->addDays($planningItem->interval_days)->toDateString(),
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

    private function canAccessAllSites(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerHo, UserRole::SpvOps]);
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
}
