<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use App\Policies\PlanningItemPolicy;
use App\Policies\SitePolicy;
use App\Policies\SystemThresholdPolicy;
use App\Policies\UnitPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(PlanningItem::class, PlanningItemPolicy::class);
        Gate::policy(SystemThreshold::class, SystemThresholdPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        Gate::define('manage-users', fn (User $user): bool => $user->hasRole(UserRole::Superadmin));

        Gate::define('manage-master-data', fn (User $user): bool => $user->isOneOf([
            UserRole::Superadmin,
            UserRole::PlannerHo,
        ]));
    }
}
