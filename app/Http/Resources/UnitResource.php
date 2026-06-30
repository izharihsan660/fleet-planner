<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'customer' => $this->customer,
            'current_plate' => $this->current_plate,
            'type' => $this->type,
            'brand' => $this->brand,
            'year' => $this->year,
            'current_odo' => $this->current_odo,
            'avg_km_per_day' => $this->avg_km_per_day,
            'status' => $this->status,
            'is_warranty' => $this->is_warranty,
            'site' => $this->whenLoaded('site'),
            'inspection_logs_count' => $this->whenCounted('inspectionLogs'),
        ];
    }
}
