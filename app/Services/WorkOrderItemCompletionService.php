<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class WorkOrderItemCompletionService
{
    public function __construct(
        private PlanningIntervalResolver $intervalResolver,
        private WorkOrderProgressService $workOrderProgressService,
        private FleetNotificationService $notifications,
    ) {}

    public function complete(
        WorkOrder $workOrder,
        WorkOrderItem $item,
        int $completedOdo,
        CarbonImmutable $completedDate,
        ?string $notes,
        int $submittedBy,
        ?int $baselineLastDoneKm = null,
        ?CarbonImmutable $baselineLastDoneDate = null,
    ): WorkOrderItem {
        $item = DB::transaction(function () use ($workOrder, $item, $completedOdo, $completedDate, $notes, $submittedBy, $baselineLastDoneKm, $baselineLastDoneDate): WorkOrderItem {
            $item->load(['unitPlanning.planningItem', 'unitPlanning.unit']);

            $unitPlanning = $item->unitPlanning;
            $interval = $this->intervalResolver->resolve($unitPlanning->planningItem, $unitPlanning->unit);
            $baselineSnapshot = [];

            if ($baselineLastDoneKm !== null && $baselineLastDoneDate !== null) {
                $baselineSnapshot = [
                    'baseline_last_done_km' => $baselineLastDoneKm,
                    'baseline_last_done_date' => $baselineLastDoneDate->toDateString(),
                    'previous_due_km' => $baselineLastDoneKm + $interval['interval_km'],
                    'previous_due_date' => $baselineLastDoneDate->addDays($interval['interval_days'])->toDateString(),
                ];
            }

            $item->update([
                ...$baselineSnapshot,
                'status' => 'complete',
                'action' => 'replace',
                'completed_odo' => $completedOdo,
                'completed_date' => $completedDate->toDateString(),
                'notes' => filled($notes) ? $notes : null,
                'submitted_by' => $submittedBy,
            ]);

            $unitPlanning->update([
                'last_done_km' => $completedOdo,
                'last_done_date' => $completedDate->toDateString(),
                'next_due_km' => $completedOdo + $interval['interval_km'],
                'next_due_date' => $completedDate->addDays($interval['interval_days'])->toDateString(),
                'is_estimated' => false,
            ]);

            $this->workOrderProgressService->sync($workOrder->refresh());
            $this->notifications->workOrderItemCompleted($item->refresh());

            return $item->refresh();
        });

        return $item;
    }
}
