<?php

namespace App\Providers;

use App\Models\InspectionLog;
use App\Models\Unit;
use App\Observers\UnitObserver;
use App\Policies\InspectionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(InspectionLog::class, InspectionPolicy::class);

        Unit::observe(UnitObserver::class);

        Vite::prefetch(concurrency: 3);
    }
}
