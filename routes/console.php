<?php

use App\Services\HighUsageService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(HighUsageService::class)->checkPendingFlags())->daily();
Schedule::command('maintenance:check-overdue')->daily();
