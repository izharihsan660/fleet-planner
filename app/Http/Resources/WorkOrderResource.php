<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
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
            'unit_id' => $this->unit_id,
            'site_id' => $this->site_id,
            'trigger_type' => $this->trigger_type,
            'status' => $this->status,
            'submitted_by_id' => $this->submitted_by,
            'approved_by_id' => $this->approved_by,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'assigned_mechanic_id' => $this->assigned_mechanic_id,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toDateTimeString(),
            'unit' => UnitResource::make($this->whenLoaded('unit')),
            'site' => SiteResource::make($this->whenLoaded('site')),
            'items' => WorkOrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->when(isset($this->items_count), (int) $this->items_count),
            'completed_items_count' => $this->when(isset($this->completed_items_count), (int) $this->completed_items_count),
            'remaining_items_count' => $this->when(isset($this->remaining_items_count), (int) $this->remaining_items_count),
            'has_blocked_items' => $this->when(isset($this->has_blocked_items), (bool) $this->has_blocked_items),
            'has_high_usage_items' => $this->when(isset($this->has_high_usage_items), (bool) $this->has_high_usage_items),
            'has_overdue_items' => $this->when($this->has_overdue_items !== null, (bool) $this->has_overdue_items),
            'has_rejected_items' => $this->when($this->has_rejected_items !== null, (bool) $this->has_rejected_items),
            'planning_item_names' => $this->when($this->planning_item_names !== null, $this->planning_item_names),
            'nearest_due' => $this->when($this->nearest_due !== null, $this->nearest_due),
            'sub_status' => $this->when($this->sub_status !== null, $this->sub_status),
            'submitted_by' => $this->whenLoaded('submittedBy'),
            'approved_by' => $this->whenLoaded('approvedBy'),
            'assigned_mechanic' => $this->whenLoaded('assignedMechanic'),
        ];
    }
}
