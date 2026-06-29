<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Superadmin);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole(UserRole::Superadmin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::Superadmin);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole(UserRole::Superadmin);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasRole(UserRole::Superadmin) && $user->isNot($model);
    }
}
