<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\User;
use App\Support\AccessScope;

class HighUsagePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, HighUsageFlag $highUsageFlag): bool
    {
        if ($user->isOneOf([UserRole::Superadmin, UserRole::SpvHo])) {
            return true;
        }

        $highUsageFlag->loadMissing('unit.site:id,region_id');

        return AccessScope::canAccessSite($user, $highUsageFlag->unit?->site_id, $highUsageFlag->unit?->site?->region_id);
    }

    public function takeAction(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea])
            && $this->view($user, $highUsageFlag);
    }

    public function submitSchedule(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return $this->takeAction($user, $highUsageFlag);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return false;
    }
}
