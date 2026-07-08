<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Resources\UnitHistoryResource;
use App\Models\Unit;
use App\Models\WorkOrderItem;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UnitHistoryController extends Controller
{
    public function show(Request $request, Unit $unit): Response
    {
        Gate::authorize('view-reports.by-unit');
        $this->abortIfCannotAccessUnit($request, $unit);

        $unit->load('site:id,name,region');

        return Inertia::render('Units/History', [
            'history' => UnitHistoryResource::make([
                'unit' => [
                    'id' => $unit->id,
                    'current_plate' => $unit->current_plate,
                    'site' => $unit->site?->name,
                    'customer' => $unit->customer,
                    'type' => $unit->type,
                    'brand' => $unit->brand,
                    'year' => $unit->year,
                    'current_odo' => $unit->current_odo,
                    'status' => $unit->status,
                ],
                'replacements' => $this->historyPage($this->itemsFor($unit, ['complete'])->where('action', 'replace')->latest('completed_date')->paginate(25, ['*'], 'replacements_page')->withQueryString()),
                'plate_histories' => $this->historyPage($unit->plateHistories()->latest('active_from')->paginate(25, ['*'], 'plates_page')->withQueryString(), fn ($history): array => [
                    'id' => $history->id,
                    'plate_number' => $history->plate_number,
                    'active_from' => $history->active_from?->toDateString(),
                    'active_until' => $history->active_until?->toDateString(),
                ]),
                'blocked_breakdowns' => $this->historyPage($this->itemsFor($unit, ['blocked', 'breakdown'])->latest()->paginate(25, ['*'], 'blocked_page')->withQueryString()),
                'postpones' => $this->historyPage($this->itemsFor($unit, ['postponed'])->latest()->paginate(25, ['*'], 'postpones_page')->withQueryString()),
            ])->resolve(),
        ]);
    }

    private function historyPage(LengthAwarePaginator $paginator, ?callable $mapper = null): array
    {
        return [
            'data' => collect($paginator->items())->map($mapper ?? fn (WorkOrderItem $item): array => $this->historyItem($item))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => $paginator->linkCollection()->toArray(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function itemsFor(Unit $unit, array $statuses)
    {
        return WorkOrderItem::query()
            ->with(['planningItem:id,name', 'submittedBy:id,name', 'workOrder:id,unit_id,site_id,created_at'])
            ->whereIn('status', $statuses)
            ->whereHas('workOrder', fn ($query) => $query->where('unit_id', $unit->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function historyItem(WorkOrderItem $item): array
    {
        return [
            'id' => $item->id,
            'work_order_id' => $item->work_order_id,
            'planning_item' => $item->planningItem?->name,
            'action' => $item->action,
            'status' => $item->status,
            'reason' => $item->reason,
            'notes' => $item->notes,
            'completed_odo' => $item->completed_odo,
            'completed_date' => $item->completed_date?->toDateString(),
            'previous_due_km' => $item->previous_due_km,
            'previous_due_date' => $item->previous_due_date?->toDateString(),
            'new_due_km' => $item->new_due_km,
            'new_due_date' => $item->new_due_date?->toDateString(),
            'submitted_by' => $item->submittedBy?->name,
            'created_at' => $item->created_at?->toDateTimeString(),
        ];
    }

    private function abortIfCannotAccessUnit(Request $request, Unit $unit): void
    {
        $user = $request->user();

        if ($user->isOneOf([UserRole::Superadmin, UserRole::SpvHo])) {
            return;
        }

        if ($user->hasRole(UserRole::PlannerArea) && $unit->site_id === $user->site_id) {
            return;
        }

        if ($user->hasRole(UserRole::Mekanik) && $unit->workOrders()->whereHas('items', fn ($query) => $query->where('submitted_by', $user->id))->exists()) {
            return;
        }

        abort(403);
    }
}
