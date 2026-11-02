<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ManualProjection;
use App\Models\Unit;
use App\Models\User;
use App\Support\AccessScope;

class ManualProjectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-projections');
    }

    public function view(User $user, ManualProjection $manualProjection): bool
    {
        $manualProjection->loadMissing('unit.site:id,region_id');

        return $this->viewAny($user)
            && AccessScope::canAccessSite($user, $manualProjection->unit->site_id, $manualProjection->unit->site?->region_id);
    }

    public function create(User $user, ?Unit $unit = null): bool
    {
        if ($user->isOneOf([UserRole::Superadmin, UserRole::SpvHo])) {
            return true;
        }

        if (! $user->hasRole(UserRole::PlannerArea) || $user->region_id === null) {
            return false;
        }

        $unit?->loadMissing('site:id,region_id');

        return $unit === null || $unit->site?->region_id === $user->region_id;
    }

    public function cancel(User $user, ManualProjection $manualProjection): bool
    {
        return $this->view($user, $manualProjection) && $this->create($user, $manualProjection->unit);
    }
}
