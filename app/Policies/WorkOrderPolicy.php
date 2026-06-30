<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::AdminSite]);
    }

    public function approve(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOneOf([UserRole::SpvOps, UserRole::Superadmin]);
    }

    public function complete(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::AdminSite, UserRole::Mekanik]);
    }

    public function markBlocked(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::AdminSite, UserRole::Mekanik]);
    }

    public function markBreakdown(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::AdminSite, UserRole::Mekanik]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }
}
