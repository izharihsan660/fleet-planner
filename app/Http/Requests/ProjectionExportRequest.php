<?php

namespace App\Http\Requests;

use App\Enums\UserRole;

class ProjectionExportRequest extends ProjectionIndexRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('view-projections')
            && (! $user->hasRole(UserRole::PlannerArea) || $user->region_id !== null);
    }
}
