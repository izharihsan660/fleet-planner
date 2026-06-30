<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'unit_planning_id' => $this->unit_planning_id,
            'planning_item_id' => $this->planning_item_id,
            'action' => $this->action,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'new_due_km' => $this->new_due_km,
            'new_due_date' => $this->new_due_date?->toDateString(),
            'freeze_start' => $this->freeze_start?->toDateTimeString(),
            'freeze_end' => $this->freeze_end?->toDateTimeString(),
            'completed_odo' => $this->completed_odo,
            'completed_date' => $this->completed_date?->toDateString(),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'triggered_by_high_usage' => (bool) $this->triggered_by_high_usage,
            'planning_item' => $this->whenLoaded('planningItem'),
            'unit_planning' => $this->whenLoaded('unitPlanning'),
            'submitted_by' => $this->whenLoaded('submittedBy'),
            'approved_by' => $this->whenLoaded('approvedBy'),
        ];
    }
}
