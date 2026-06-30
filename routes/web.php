<?php

use App\Http\Controllers\BlockedBreakdownController;
use App\Http\Controllers\HighUsageController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlanningItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SystemThresholdController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnitHistoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('sites', SiteController::class)->except('show');
    Route::resource('units', UnitController::class)->except('show');
    Route::get('inspections', [InspectionController::class, 'index'])->name('inspections.index');
    Route::get('inspections/create', [InspectionController::class, 'create'])->name('inspections.create');
    Route::post('inspections', [InspectionController::class, 'store'])->name('inspections.store');
    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('projections', [ProjectionController::class, 'index'])->name('projections.index');
    Route::get('high-usage', [HighUsageController::class, 'index'])->name('high-usage.index');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/wo-summary', [ReportController::class, 'woSummary'])->name('reports.wo-summary');
    Route::get('reports/by-item', [ReportController::class, 'byItem'])->name('reports.by-item');
    Route::get('reports/by-unit', [ReportController::class, 'byUnit'])->name('reports.by-unit');
    Route::get('reports/overdue', [ReportController::class, 'overdueByArea'])->name('reports.overdue');
    Route::get('units/{unit}/history', [UnitHistoryController::class, 'show'])->name('units.history');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('high-usage/{flag}/action', [HighUsageController::class, 'takeAction'])->name('high-usage.action');
    Route::post('high-usage/{flag}/schedule', [HighUsageController::class, 'submitSchedule'])->name('high-usage.schedule');
    Route::get('work-orders/{wo}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::post('work-orders/{wo}/approve', [WorkOrderController::class, 'approve'])->name('work-orders.approve');
    Route::post('work-orders/{wo}/items/{item}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.items.complete');
    Route::post('work-order-items/{item}/blocked', [BlockedBreakdownController::class, 'markBlocked'])->name('work-order-items.blocked');
    Route::post('units/{unit}/breakdown', [BlockedBreakdownController::class, 'markBreakdown'])->name('units.breakdown');
    Route::post('units/{unit}/breakdown-inspection', [BlockedBreakdownController::class, 'storeInspection'])->name('units.breakdown-inspection');

    Route::middleware('role:superadmin,planner_ho')->group(function () {
        Route::resource('planning-items', PlanningItemController::class)->except('show');
        Route::resource('system-thresholds', SystemThresholdController::class)->except('show');
    });

    Route::middleware('role:superadmin')->group(function () {
        Route::resource('users', UserController::class)->except('show');
    });
});

require __DIR__.'/auth.php';
