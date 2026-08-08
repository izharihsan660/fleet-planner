<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\SubmitApprovalQueueRequest;
use App\Http\Resources\RegionResource;
use App\Models\Region;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\WorkOrderProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalQueueController extends Controller
{
    public function __construct(private WorkOrderProgressService $workOrderProgressService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);
        $user = $request->user();

        abort_unless($user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]), 403);

        $filters = $request->only(['region_id', 'search']);
        $now = CarbonImmutable::now();

        $items = WorkOrderItem::query()
            ->applicable()
            ->with([
                'planningItem:id,name',
                'unitPlanning:id,last_done_km,last_done_date',
                'workOrder.unit' => fn ($query) => $query
                    ->select(['id', 'current_plate', 'current_odo', 'has_odometer_reading', 'site_id'])
                    ->with('unitPlannings:id,unit_id,last_done_km'),
                'workOrder.site:id,name,region_id',
                'workOrder.site.area:id,name',
                'submittedBy:id,name',
            ])
            ->whereIn('status', ['replace', 'postpone', 'pending_create'])
            ->when($filters['region_id'] ?? null, fn (Builder $query, string $regionId) => $query->whereHas('workOrder.site', fn (Builder $siteQuery) => $siteQuery->where('region_id', $regionId)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->whereHas('workOrder.unit', fn (Builder $unitQuery) => $unitQuery->where('current_plate', 'like', "%{$search}%"))
                        ->orWhereHas('planningItem', fn (Builder $planningQuery) => $planningQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->get()
            ->map(function (WorkOrderItem $item) use ($now): array {
                $submittedAt = CarbonImmutable::parse($item->updated_at);
                $waitingHours = max(0, (int) $submittedAt->diffInHours($now));
                $waitingDays = intdiv($waitingHours, 24);
                $unit = $item->workOrder?->unit;

                return [
                    'id' => $item->id,
                    'work_order_id' => $item->work_order_id,
                    'plate_number' => $item->workOrder?->unit?->current_plate ?? '-',
                    'current_odo' => $unit?->current_odo ?? 0,
                    'baseline_incomplete' => $unit?->hasIncompletePlanningBaseline() ?? true,
                    'item_name' => $item->planningItem?->name ?? 'Item maintenance',
                    'reason' => $item->reason ?: $item->notes,
                    'site_name' => $item->workOrder?->site?->name ?? '-',
                    'region_name' => $item->workOrder?->site?->area?->name ?? '-',
                    'submitted_by_name' => $item->submittedBy?->name ?? '-',
                    'submitted_at' => $item->updated_at?->toDateTimeString(),
                    'waiting_hours' => $waitingHours,
                    'waiting_label' => $waitingDays > 0 ? $waitingDays.' hari' : max(1, $waitingHours).' jam',
                    'is_warning' => $waitingHours > 48,
                    'status' => $item->status,
                ];
            })
            ->sortByDesc('waiting_hours')
            ->values();

        return Inertia::render('ApprovalQueue/Index', [
            'items' => $items,
            'regions' => RegionResource::collection(Region::query()->orderBy('name')->get()),
            'filters' => [
                'region_id' => $filters['region_id'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
        ]);
    }

    public function store(SubmitApprovalQueueRequest $request, FleetNotificationService $notifications): RedirectResponse
    {
        $payload = $request->validated();
        $processedCount = 0;

        DB::transaction(function () use ($payload, $request, $notifications, &$processedCount): void {
            foreach ($payload['item_ids'] as $itemId) {
                $item = WorkOrderItem::query()
                    ->applicable()
                    ->with(['workOrder.items', 'workOrder.unit', 'unitPlanning', 'planningItem'])
                    ->lockForUpdate()
                    ->findOrFail($itemId);

                if (! in_array($item->status, ['replace', 'postpone', 'pending_create'], true)) {
                    abort(422, 'Ada item yang sudah berubah status. Muat ulang halaman lalu pilih ulang.');
                }

                if ($item->unitPlanning?->isBaselineMissing() ?? true) {
                    abort(422, 'Baseline item belum diisi. Item tidak dapat diproses melalui antrean approval.');
                }

                if ($payload['decision'] === 'approve') {
                    $this->approveItem($item, $request->user()->id, $notifications);
                } else {
                    $this->rejectItem($item, $request->user()->id, $payload['reason']);
                }

                $processedCount++;
            }
        });

        $message = $payload['decision'] === 'approve'
            ? $processedCount.' item berhasil disetujui.'
            : $processedCount.' item berhasil ditolak.';

        return redirect()->route('approval-queue.index')->with('status', $message);
    }

    private function approveItem(WorkOrderItem $item, int $userId, FleetNotificationService $notifications): void
    {
        $workOrder = $item->workOrder;

        $workOrder->update([
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        if ($item->status === 'pending_create') {
            $item->update([
                'status' => $workOrder->assigned_mechanic_id === null ? 'on_hold' : 'in_progress',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            $notifications->taskAutoGenerated($item->refresh());
            $this->syncWorkOrderStatusFromItems($workOrder->refresh());

            return;
        }

        if ($item->status === 'postpone') {
            $item->unitPlanning?->update([
                'next_due_km' => $item->new_due_km,
                'next_due_date' => $item->new_due_date?->toDateString(),
                'last_done_date' => $item->available_date?->toDateString() ?? $item->unitPlanning?->last_done_date?->toDateString(),
            ]);

            $item->update([
                'status' => 'postponed',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            $this->syncWorkOrderStatusFromItems($workOrder->refresh());

            return;
        }

        $item->update([
            'status' => 'in_progress',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $notifications->replaceApprovedForLogistics($workOrder, $item->refresh());
        $this->syncWorkOrderStatusFromItems($workOrder->refresh());
    }

    private function rejectItem(WorkOrderItem $item, int $userId, string $reason): void
    {
        $workOrder = $item->workOrder;

        $item->update([
            'status' => 'rejected',
            'notes' => $reason,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $workOrder->update([
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $this->syncWorkOrderStatusFromItems($workOrder->refresh());
    }

    private function syncWorkOrderStatusFromItems(WorkOrder $workOrder): void
    {
        $this->workOrderProgressService->sync($workOrder);
    }
}
