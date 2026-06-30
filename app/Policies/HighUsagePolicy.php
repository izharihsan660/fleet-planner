<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\User;

class HighUsagePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerHo, UserRole::AdminSite, UserRole::SpvOps]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, HighUsageFlag $highUsageFlag): bool
    {
        if ($user->isOneOf([UserRole::Superadmin, UserRole::PlannerHo, UserRole::SpvOps])) {
            return true;
        }

        return $user->hasRole(UserRole::AdminSite) && $user->site_id === $highUsageFlag->unit?->site_id;
    }

    public function takeAction(User $user, HighUsageFlag $highUsageFlag): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::AdminSite])
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
