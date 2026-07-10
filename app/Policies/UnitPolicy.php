<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\User;
use App\Policies\Concerns\AuthorizesMasterData;
use App\Support\AccessScope;

class UnitPolicy
{
    use AuthorizesMasterData;

    public function view(User $user, Unit $unit): bool
    {
        if ($user->isOneOf([UserRole::PlannerArea, UserRole::Mekanik])) {
            $unit->loadMissing('site:id,region_id');

            return AccessScope::canAccessSite($user, $unit->site_id, $unit->site?->region_id);
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
