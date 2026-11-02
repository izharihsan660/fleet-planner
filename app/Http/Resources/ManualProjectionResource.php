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
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'planning_item_id' => $this->planning_item_id,
            'unit_planning_id' => $this->unit_planning_id,
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
