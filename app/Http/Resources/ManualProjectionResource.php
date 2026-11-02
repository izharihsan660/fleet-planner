<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ManualProjectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->workOrderItem;

        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'planning_item_id' => $this->planning_item_id,
            'unit_planning_id' => $this->unit_planning_id,
            'work_order_item_id' => $this->work_order_item_id,
            'work_order_id' => $task?->work_order_id,
            'task_status' => $task?->status,
            'task_status_label' => $task === null ? null : match ($task->status) {
                'complete' => 'Selesai',
                'rejected' => 'Ditolak',
                'cancelled' => 'Dibatalkan',
                'in_progress' => $task->workHasStarted() ? 'Dikerjakan' : 'Disetujui',
                'pending_create', 'replace', 'postpone' => 'Menunggu Approval',
                default => $task->approved_at !== null ? 'Disetujui' : 'Menunggu Approval',
            },
            'assigned_mechanic_id' => $this->assigned_mechanic_id,
            'assigned_mechanic_name' => $this->assignedMechanic?->name,
            'plate_number' => $this->unit->current_plate,
            'site_name' => $this->unit->site?->name ?? '-',
            'planning_item_name' => $this->planningItem->name,
            'target_date' => $this->target_date->toDateString(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by_name' => $this->createdBy->name,
            'created_at' => $this->created_at->toIso8601String(),
            'can_cancel' => $request->user()->can('cancel', $this->resource),
        ];
    }
}
