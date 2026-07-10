<?php

use App\Enums\UserRole;
use App\Http\Controllers\ApprovalQueueController;
use App\Http\Controllers\BlockedBreakdownController;
use App\Http\Controllers\HighUsageController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\MaintenanceImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlanningItemController;
use App\Http\Controllers\PlanningItemOverrideController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectionController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SystemThresholdController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UnitHistoryController;
use App\Http\Controllers\UnitSiteTransferController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkListController;
use App\Http\Controllers\WorkOrderController;
use App\Models\WorkOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/login');

Route::get('/dashboard', function (Request $request) {
    if ($request->user()?->hasRole(UserRole::Mekanik)) {
        return redirect()->route('mechanic.tasks');
    }

    return Inertia::render('Dashboard', [
        'overdueBanner' => [
            'threshold' => 20,
            'count' => WorkOrderItem::query()->where('status', 'overdue')->count(),
        ],
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('inspections', [InspectionController::class, 'index'])->name('inspections.index');
    Route::get('inspections/create', [InspectionController::class, 'create'])->name('inspections.create');
    Route::post('inspections', [InspectionController::class, 'store'])->name('inspections.store');
    Route::delete('inspections/{inspectionLog}/today', [InspectionController::class, 'cancelToday'])->name('inspections.cancel-today');
    Route::get('tugas-saya', [WorkOrderController::class, 'myTasks'])->name('mechanic.tasks');
    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('daftar-kerja', [WorkListController::class, 'index'])->name('work-list.index');
    Route::post('daftar-kerja', [WorkListController::class, 'store'])->name('work-list.store');
    Route::get('antrian-approval', [ApprovalQueueController::class, 'index'])->name('approval-queue.index');
    Route::post('antrian-approval', [ApprovalQueueController::class, 'store'])->name('approval-queue.store');
    Route::get('projections', [ProjectionController::class, 'index'])->name('projections.index');
    Route::get('high-usage', [HighUsageController::class, 'index'])->name('high-usage.index');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/wo-summary', [ReportController::class, 'woSummary'])->name('reports.wo-summary');
    Route::get('reports/by-item', [ReportController::class, 'byItem'])->name('reports.by-item');
    Route::get('reports/by-unit', [ReportController::class, 'byUnit'])->name('reports.by-unit');
    Route::get('reports/overdue', [ReportController::class, 'overdueByArea'])->name('reports.overdue');
    Route::get('units/{unit}/history', [UnitHistoryController::class, 'show'])->name('units.history');
    Route::post('units/{unit}/site-transfers', [UnitSiteTransferController::class, 'store'])->name('units.site-transfers.store');
    Route::post('unit-site-transfers/{transfer}/approve', [UnitSiteTransferController::class, 'approve'])->name('unit-site-transfers.approve');
    Route::post('unit-site-transfers/{transfer}/reject', [UnitSiteTransferController::class, 'reject'])->name('unit-site-transfers.reject');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('high-usage/{flag}/action', [HighUsageController::class, 'takeAction'])->name('high-usage.action');
    Route::post('high-usage/{flag}/schedule', [HighUsageController::class, 'submitSchedule'])->name('high-usage.schedule');
    Route::get('work-orders/{wo}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::post('work-orders/{wo}/approve', [WorkOrderController::class, 'approve'])->name('work-orders.approve');
    Route::post('work-orders/{wo}/reject', [WorkOrderController::class, 'reject'])->name('work-orders.reject');
    Route::post('work-orders/{wo}/assign-mechanic', [WorkOrderController::class, 'assignMechanic'])->name('work-orders.assign-mechanic');
    Route::post('unit-plannings/{planning}/create-work-order', [WorkOrderController::class, 'createFromPlanning'])->name('unit-plannings.create-work-order');
    Route::post('units/{unit}/manual-findings', [WorkOrderController::class, 'storeManualFinding'])->name('units.manual-findings.store');
    Route::post('work-orders/{wo}/items/{item}/replace', [WorkOrderController::class, 'submitReplace'])->name('work-orders.items.replace');
    Route::post('work-orders/{wo}/items/{item}/postpone', [WorkOrderController::class, 'submitPostpone'])->name('work-orders.items.postpone');
    Route::post('work-orders/{wo}/items/{item}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.items.complete');
    Route::post('work-order-items/{item}/blocked', [BlockedBreakdownController::class, 'markBlocked'])->name('work-order-items.blocked');
    Route::post('work-order-items/{item}/resolve-blocked', [BlockedBreakdownController::class, 'resolveBlocked'])->name('work-order-items.resolve-blocked');
    Route::post('units/{unit}/breakdown', [BlockedBreakdownController::class, 'markBreakdown'])->name('units.breakdown');
    Route::post('units/{unit}/breakdown-inspection', [BlockedBreakdownController::class, 'storeInspection'])->name('units.breakdown-inspection');

    Route::middleware('role:superadmin,spv_ho')->group(function () {
        Route::resource('sites', SiteController::class)->except('show');
        Route::resource('regions', RegionController::class)->except('show');
        Route::resource('units', UnitController::class)->except('show');
        Route::resource('planning-items', PlanningItemController::class)->except('show');
        Route::resource('planning-item-overrides', PlanningItemOverrideController::class)->except('show');
        Route::get('maintenance-imports', [MaintenanceImportController::class, 'index'])->name('maintenance-imports.index');
        Route::post('maintenance-imports/preview', [MaintenanceImportController::class, 'preview'])->name('maintenance-imports.preview');
        Route::post('maintenance-imports/commit', [MaintenanceImportController::class, 'commit'])->name('maintenance-imports.commit');
        Route::resource('system-thresholds', SystemThresholdController::class)->except('show');
    });

    Route::middleware('role:superadmin')->group(function () {
        Route::resource('users', UserController::class)->except('show');
    });
});

require __DIR__.'/auth.php';
