<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\BackdateWorkOrderItemRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\CompletionBackdatePolicy;
use App\Services\WorkOrderItemCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Koreksi Tanggal Selesai: form terpisah milik Superadmin untuk mencatat
 * pekerjaan yang mundurnya melewati backdate_max_days, sehingga form Complete
 * biasa tetap bisa menolak tegas tanpa membuat pekerjaan lama jadi mustahil
 * dicatat.
 */
class BackdateCompletionController extends Controller
{
    public function edit(Request $request, WorkOrder $wo, WorkOrderItem $item, CompletionBackdatePolicy $policy): Response
    {
        $this->authorizeSuperadmin($request);
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);

        abort_if($item->status === 'complete', 422, 'Item work order ini sudah selesai.');

        $item->load(['planningItem:id,name', 'unitPlanning:id,last_done_km,last_done_date']);
        $wo->load(['unit:id,current_plate,current_odo', 'site:id,name']);

        return Inertia::render('WorkOrders/BackdateCompletion', [
            'workOrder' => [
                'id' => $wo->id,
                'plate_number' => $wo->unit?->current_plate ?? '-',
                'site_name' => $wo->site?->name ?? '-',
                'current_odo' => $wo->unit?->current_odo ?? 0,
            ],
            'item' => [
                'id' => $item->id,
                'item_name' => $item->planningItem?->name ?? 'Item maintenance',
                'status' => $item->status,
                'last_done_date' => $item->unitPlanning?->last_done_date?->toDateString(),
                'last_done_km' => $item->unitPlanning?->last_done_km,
            ],
            'backdateThresholds' => $policy->toArray(),
        ]);
    }

    public function update(
        BackdateWorkOrderItemRequest $request,
        WorkOrder $wo,
        WorkOrderItem $item,
        WorkOrderItemCompletionService $completionService,
        CompletionBackdatePolicy $policy,
    ): RedirectResponse {
        $this->abortIfItemDoesNotBelongToWorkOrder($wo, $item);

        abort_if($item->status === 'complete', 422, 'Item work order ini sudah selesai.');
        abort_if($item->unitPlanning === null || $item->unitPlanning->isBaselineMissing(), 422, 'Baseline item belum diisi. Isi baseline sebelum mengoreksi tanggal selesai.');

        $completedDate = CarbonImmutable::parse($request->validated('completed_date'));

        $completionService->complete(
            $wo,
            $item,
            $request->integer('completed_odo'),
            $completedDate,
            $request->string('notes')->toString(),
            $request->user()->id,
            backdateOverrideBy: $request->user()->id,
        );

        return redirect()
            ->route('work-orders.show', $wo)
            ->with('status', sprintf(
                'Tanggal selesai dikoreksi mundur %d hari dan tercatat sebagai koreksi Superadmin.',
                $policy->daysBackdated($completedDate),
            ));
    }

    private function authorizeSuperadmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(UserRole::Superadmin) ?? false, 403);
    }

    private function abortIfItemDoesNotBelongToWorkOrder(WorkOrder $wo, WorkOrderItem $item): void
    {
        abort_unless($item->work_order_id === $wo->id, 404);
    }
}
