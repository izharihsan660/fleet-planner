<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HighUsageFlagResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $daysSinceFlagged = $this->flagged_at ? max(1, (int) floor($this->flagged_at->diffInDays(now())) + 1) : 1;

        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'planning_item_id' => $this->planning_item_id,
            'unit_planning_id' => $this->unit_planning_id,
            'avg_km_per_day' => (float) $this->avg_km_per_day,
            'estimated_due_days' => $this->estimated_due_days,
            'flagged_at' => $this->flagged_at?->toDateTimeString(),
            'action_taken' => $this->action_taken,
            'action_taken_at' => $this->action_taken_at?->toDateTimeString(),
            'resolved_at' => $this->resolved_at?->toDateTimeString(),
            'days_since_flagged' => $daysSinceFlagged,
            'window' => $daysSinceFlagged <= 5 ? 1 : 2,
            'unit' => UnitResource::make($this->whenLoaded('unit')),
            'planning_item' => $this->whenLoaded('planningItem'),
            'unit_planning' => $this->whenLoaded('unitPlanning'),
            'action_taken_by_user' => $this->whenLoaded('actionTakenBy'),
        ];
    }
}
