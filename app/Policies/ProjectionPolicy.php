<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class ProjectionPolicy
{
    public function view(User $user): bool
    {
        return $user->isOneOf([
            UserRole::Superadmin,
            UserRole::SpvHo,
            UserRole::PlannerArea,
        ]);
    }
}
