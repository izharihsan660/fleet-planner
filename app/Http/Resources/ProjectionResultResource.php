<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'period_months' => $this->resource['period_months'],
            'period_end' => $this->resource['period_end'],
            'by_unit' => $this->resource['by_unit'],
            'by_item' => $this->resource['by_item'],
            'by_part' => $this->resource['by_part'],
            'warnings' => $this->resource['warnings'],
        ];
    }
}
