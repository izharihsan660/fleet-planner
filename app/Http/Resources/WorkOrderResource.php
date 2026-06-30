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
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toDateTimeString(),
            'unit' => UnitResource::make($this->whenLoaded('unit')),
            'site' => SiteResource::make($this->whenLoaded('site')),
            'items' => WorkOrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'has_blocked_items' => $this->when(isset($this->has_blocked_items), (bool) $this->has_blocked_items),
            'has_high_usage_items' => $this->when(isset($this->has_high_usage_items), (bool) $this->has_high_usage_items),
            'submitted_by' => $this->whenLoaded('submittedBy'),
            'approved_by' => $this->whenLoaded('approvedBy'),
        ];
    }
}
