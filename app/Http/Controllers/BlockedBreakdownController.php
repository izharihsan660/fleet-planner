<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\MarkConditionRequest;
use App\Http\Requests\StoreBreakdownInspectionRequest;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\BlockedBreakdownService;
use App\Services\PlanningIntervalResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class BlockedBreakdownController extends Controller
{
    public function markBlocked(MarkConditionRequest $request, WorkOrderItem $item, BlockedBreakdownService $service): RedirectResponse
    {
        Gate::authorize('markBlocked', WorkOrder::class);

        $item->load('workOrder.unit');
        $this->abortIfCannotAccessSite($request->user(), $item->workOrder->site_id);

        if ($item->workOrder->unit?->status === 'breakdown') {
            return back()->withErrors(['action' => 'Unit sedang Breakdown. Input KM baru dan isi part yang diganti sebelum melanjutkan aksi normal.']);
        }

        if (! in_array($item->status, ['on_hold', 'in_progress', 'overdue'], true)) {
            return back()->withErrors(['action' => 'Hanya item On Hold, In Progress, atau Overdue yang bisa ditandai Blocked.']);
        }

        $service->markBlocked($item, $request->user(), $request->string('reason')->toString());

        return back()->with('status', 'Item work order berhasil ditandai Blocked.');
    }

    public function resolveBlocked(WorkOrderItem $item): RedirectResponse
    {
        Gate::authorize('markBlocked', WorkOrder::class);

        $item->load('workOrder');
        $this->abortIfCannotAccessSite(request()->user(), $item->workOrder->site_id);

        if ($item->status !== 'blocked') {
            return back()->withErrors(['action' => 'Hanya item Blocked yang bisa di-resolve.']);
        }

        $item->update(['status' => 'on_hold']);

        return back()->with('status', 'Blocked berhasil di-resolve. Item kembali On Hold.');
    }

    public function markBreakdown(MarkConditionRequest $request, Unit $unit, BlockedBreakdownService $service): RedirectResponse
    {
        Gate::authorize('markBreakdown', WorkOrder::class);
        $this->abortIfCannotAccessSite($request->user(), $unit->site_id);

        $service->markBreakdown($unit, $request->user(), $request->string('reason')->toString());

        return back()->with('status', 'Unit berhasil ditandai Breakdown.');
    }

    public function storeInspection(StoreBreakdownInspectionRequest $request, Unit $unit, PlanningIntervalResolver $intervalResolver): RedirectResponse
    {
        Gate::authorize('markBreakdown', WorkOrder::class);
        $this->abortIfCannotAccessSite($request->user(), $unit->site_id);

        $unitPlanning = UnitPlanning::query()
            ->where('unit_id', $unit->id)
            ->findOrFail($request->integer('unit_planning_id'));

        $unitPlanning->load(['planningItem', 'unit']);
        $completedOdo = $request->integer('completed_odo');
        $interval = $intervalResolver->resolve($unitPlanning->planningItem, $unitPlanning->unit);

        $unitPlanning->update([
            'last_done_km' => $completedOdo,
            'last_done_date' => Carbon::today()->toDateString(),
            'next_due_km' => $completedOdo + $interval['interval_km'],
            'next_due_date' => Carbon::today()->addDays($interval['interval_days'])->toDateString(),
            'freeze_start' => null,
        ]);

        WorkOrderItem::query()
            ->where('unit_planning_id', $unitPlanning->id)
            ->where('status', 'breakdown')
            ->update([
                'status' => 'complete',
                'action' => 'breakdown',
                'completed_odo' => $completedOdo,
                'completed_date' => Carbon::today()->toDateString(),
                'freeze_end' => now(),
            ]);

        if (! WorkOrderItem::query()->where('status', 'breakdown')->whereHas('workOrder', fn ($query) => $query->where('unit_id', $unit->id))->exists()) {
            $unit->update(['status' => 'active']);
        }

        return back()->with('status', 'Inspeksi breakdown tersimpan, cycle lanjut normal.');
    }

    private function abortIfCannotAccessSite(User $user, int $siteId): void
    {
        if ($user->isOneOf([UserRole::Superadmin, UserRole::SpvHo])) {
            return;
        }

        abort_unless($user->site_id === $siteId, 403);
    }
}
