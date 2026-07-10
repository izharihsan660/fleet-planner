<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\AccessScope;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $this->canAccessSite($user, $workOrder);
    }

    public function create(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]);
    }

    public function approve(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOneOf([UserRole::SpvHo, UserRole::Superadmin]) && $this->canAccessSite($user, $workOrder);
    }

    public function complete(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::Mekanik]) && $this->canAccessSite($user, $workOrder);
    }

    public function assignMechanic(User $user, WorkOrder $workOrder): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]) && $this->canAccessSite($user, $workOrder);
    }

    public function markBlocked(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::Mekanik]);
    }

    public function markBreakdown(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::Mekanik]);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    public function delete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    public function restore(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    public function forceDelete(User $user, WorkOrder $workOrder): bool
    {
        return false;
    }

    private function canAccessSite(User $user, WorkOrder $workOrder): bool
    {
        $workOrder->loadMissing('site:id,region_id');

        return AccessScope::canAccessSite($user, $workOrder->site_id, $workOrder->site?->region_id);
    }
}
