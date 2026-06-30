<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class InspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOneOf([
            UserRole::Superadmin,
            UserRole::AdminSite,
            UserRole::Mekanik,
        ]);
    }

    public function store(User $user): bool
    {
        return $this->create($user);
    }
}
