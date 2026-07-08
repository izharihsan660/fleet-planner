<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait AuthorizesMasterData
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]);
    }

    public function update(User $user): bool
    {
        return $this->create($user);
    }

    public function delete(User $user): bool
    {
        return $this->create($user);
    }
}
