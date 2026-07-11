<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Policies\HighUsagePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PlanningItemPolicy;
use App\Policies\ProjectionPolicy;
use App\Policies\ReportPolicy;
use App\Policies\SitePolicy;
use App\Policies\SystemThresholdPolicy;
use App\Policies\UnitPolicy;
use App\Policies\UserPolicy;
use App\Policies\WorkOrderPolicy;
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
        Gate::policy(WorkOrder::class, WorkOrderPolicy::class);
        Gate::policy(HighUsageFlag::class, HighUsagePolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);

        Gate::define('view-projections', fn (User $user): bool => app(ProjectionPolicy::class)->view($user));
        Gate::define('view-reports', fn (User $user): bool => app(ReportPolicy::class)->view($user));
        Gate::define('view-reports.wo-summary', fn (User $user): bool => app(ReportPolicy::class)->viewWoSummary($user));
        Gate::define('view-reports.by-item', fn (User $user): bool => app(ReportPolicy::class)->viewByItem($user));
        Gate::define('view-reports.by-unit', fn (User $user): bool => app(ReportPolicy::class)->viewByUnit($user));
        Gate::define('view-reports.overdue', fn (User $user): bool => app(ReportPolicy::class)->viewOverdue($user));
        Gate::define('view-reports.accuracy', fn (User $user): bool => app(ReportPolicy::class)->viewAccuracy($user));

        Gate::define('manage-users', fn (User $user): bool => $user->hasRole(UserRole::Superadmin));

        Gate::define('manage-master-data', fn (User $user): bool => $user->isOneOf([
            UserRole::Superadmin,
            UserRole::SpvHo,
        ]));
    }
}
