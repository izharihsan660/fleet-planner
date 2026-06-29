<?php

namespace App\Providers;

use App\Models\Unit;
use App\Observers\UnitObserver;
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
        Unit::observe(UnitObserver::class);

        Vite::prefetch(concurrency: 3);
    }
}
