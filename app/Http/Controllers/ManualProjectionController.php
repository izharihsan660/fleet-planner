<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManualProjectionRequest;
use App\Models\ManualProjection;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrderItem;
use App\Services\ReplaceTaskSubmissionService;
use App\Services\WorkOrderProgressService;
use App\Support\WorkOrderAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManualProjectionController extends Controller
{
    public function store(StoreManualProjectionRequest $request, ReplaceTaskSubmissionService $submissions): RedirectResponse
    {
        DB::transaction(function () use ($request, $submissions): void {
            $unit = Unit::query()->lockForUpdate()->findOrFail($request->integer('unit_id'));
            Gate::authorize('create', [ManualProjection::class, $unit]);

            if (ManualProjection::query()->active()->where('unit_id', $unit->id)
                ->where('planning_item_id', $request->integer('planning_item_id'))->exists()) {
                throw ValidationException::withMessages([
                    'planning_item_id' => 'Unit dan item ini sudah memiliki kebutuhan manual aktif.',
                ]);
            }

            $planning = UnitPlanning::query()->firstOrCreate(
                ['unit_id' => $unit->id, 'planning_item_id' => $request->integer('planning_item_id')],
                ['last_done_km' => $unit->current_odo, 'last_done_date' => now()->toDateString()],
            );
            $planning = UnitPlanning::query()->lockForUpdate()->findOrFail($planning->id);

            if ($planning->is_excluded) {
                throw ValidationException::withMessages(['planning_item_id' => 'Item ini dikecualikan untuk unit tersebut']);
            }

            $manual = ManualProjection::query()->create([
                ...$request->validated(),
                'unit_planning_id' => $planning->id,
                'created_by' => $request->user()->id,
                'status' => 'active',
            ]);

            $item = WorkOrderItem::query()->where('unit_planning_id', $planning->id)
                ->whereNotIn('status', ['complete', 'cancelled', 'rejected'])->latest('id')->first();

            if ($item === null) {
                $item = $submissions->create(
                    $planning,
                    $request->user(),
                    'Kebutuhan Manual: '.$manual->reason->label().' — '.($manual->notes ?? ''),
                    WorkOrderAssignment::payload($request->validated(), 'target_date'),
                    ignoreRejected: true,
                    workOrderNotes: $manual->taskOriginNote(),
                );
            }

            if ($item === null) {
                throw ValidationException::withMessages(['planning_item_id' => 'Item ini sudah memiliki task aktif. Silakan coba kembali.']);
            }

            $manual->update(['work_order_item_id' => $item->id]);
        });

        return back()->with('status', 'Kebutuhan manual berhasil ditambahkan.');
    }

    public function cancel(Request $request, ManualProjection $manualProjection, WorkOrderProgressService $progress): RedirectResponse
    {
        Gate::authorize('cancel', $manualProjection);

        DB::transaction(function () use ($request, $manualProjection, $progress): void {
            $manual = ManualProjection::query()->lockForUpdate()->findOrFail($manualProjection->id);

            if ($manual->status !== 'active') {
                return;
            }

            $item = $manual->work_order_item_id === null ? null : WorkOrderItem::query()
                ->with('workOrder')->lockForUpdate()->find($manual->work_order_item_id);

            if ($item !== null && $manual->ownsTask($item)) {
                if (! in_array($item->status, ['complete', 'cancelled', 'rejected'], true)
                    && ($item->approved_at !== null || $item->status === 'in_progress')) {
                    throw ValidationException::withMessages([
                        'manual_projection' => 'Task sudah disetujui, selesaikan atau batalkan lewat Work Order',
                    ]);
                }

                if (in_array($item->status, ['pending_create', 'replace', 'postpone', 'on_hold', 'overdue'], true)) {
                    $item->update(['status' => 'cancelled', 'reason' => 'Kebutuhan manual dibatalkan']);
                    $progress->sync($item->workOrder->refresh());
                }
            }

            $manual->update([
                'status' => 'cancelled',
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
            ]);
        });

        return back()->with('status', 'Kebutuhan manual dibatalkan.');
    }
}
