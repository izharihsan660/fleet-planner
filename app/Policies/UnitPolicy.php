<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\User;
use App\Policies\Concerns\AuthorizesMasterData;

class UnitPolicy
{
    use AuthorizesMasterData;

    public function view(User $user, Unit $unit): bool
    {
        if ($user->isOneOf([UserRole::AdminSite, UserRole::Mekanik])) {
            return $user->site_id === $unit->site_id;
        }

        return true;
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->create($user) && $this->view($user, $unit);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->create($user) && $this->view($user, $unit);
    }
}