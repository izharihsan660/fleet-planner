<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class ReportPolicy
{
    public function view(User $user): bool
    {
        return true;
    }

    public function viewWoSummary(User $user): bool
    {
        return ! $user->hasRole(UserRole::Logistik);
    }

    public function viewByItem(User $user): bool
    {
        return true;
    }

    public function viewByUnit(User $user): bool
    {
        return ! $user->hasRole(UserRole::Logistik);
    }

    public function viewOverdue(User $user): bool
    {
        return ! $user->hasRole(UserRole::Logistik);
    }
}
