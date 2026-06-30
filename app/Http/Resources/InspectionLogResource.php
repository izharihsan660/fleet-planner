<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'mechanic_id' => $this->mechanic_id,
            'inspection_date' => $this->inspection_date?->toDateString(),
            'odometer' => $this->odometer,
            'insufficient_data' => $this->insufficient_data,
            'unit' => new UnitResource($this->whenLoaded('unit')),
            'mechanic' => $this->whenLoaded('mechanic'),
        ];
    }
}
