<?php

namespace App\Services;

use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;

class ReplaceTaskSubmissionService
{
    public function __construct(
        private WorkOrderItemGuard $guard,
        private FleetNotificationService $notifications,
    ) {}

    /**
     * @param  array{work_order: array{assigned_mechanic_id: int}, item: array{scheduled_date: string}}  $assignment
     */
    public function create(
        UnitPlanning $planning,
        User $actor,
        string $reason,
        array $assignment,
        ?WorkOrder $workOrder = null,
        bool $ignoreRejected = false,
        ?string $workOrderNotes = null,
    ): ?WorkOrderItem {
        $item = $this->guard->createIfUnblocked($planning, function () use ($planning, $actor, $reason, $assignment, &$workOrder, $workOrderNotes): WorkOrderItem {
            $workOrder ??= WorkOrder::query()->create([
                'unit_id' => $planning->unit_id,
                'site_id' => $planning->unit->site_id,
                'trigger_type' => 'manual',
                'status' => 'open',
                'submitted_by' => $actor->id,
                'notes' => $workOrderNotes ?? $reason,
                ...$assignment['work_order'],
            ]);

            return WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => 'replace',
                'action' => 'replace',
                'reason' => $reason,
                'previous_due_km' => $planning->next_due_km,
                'previous_due_date' => $planning->next_due_date?->toDateString(),
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                ...$assignment['item'],
            ]);
        }, ignoreRejected: $ignoreRejected);

        if ($item !== null) {
            $item->setRelation('workOrder', $workOrder);
            $item->setRelation('planningItem', $planning->planningItem);
            $this->notifications->taskSubmitted($item, 'replace');
        }

        return $item;
    }
}
