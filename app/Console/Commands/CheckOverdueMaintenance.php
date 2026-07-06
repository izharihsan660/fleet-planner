<?php

namespace App\Console\Commands;

use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckOverdueMaintenance extends Command
{
    protected $signature = 'maintenance:check-overdue';

    protected $description = 'Mark overdue maintenance work order items and notify operation users.';

    public function handle(FleetNotificationService $notifications): int
    {
        $overdueItems = WorkOrderItem::query()
            ->with(['workOrder.unit:id,current_plate,current_odo', 'workOrder.site:id,name', 'planningItem:id,name', 'unitPlanning:id,next_due_date,next_due_km'])
            ->whereIn('status', ['on_hold', 'in_progress'])
            ->where(function ($query): void {
                $query
                    ->whereHas('unitPlanning', fn ($unitPlanningQuery) => $unitPlanningQuery->whereDate('next_due_date', '<', now()->toDateString()))
                    ->orWhereHas('unitPlanning.unit', function ($unitQuery): void {
                        $unitQuery->whereColumn('units.current_odo', '>=', 'unit_plannings.next_due_km');
                    });
            })
            ->get();

        DB::transaction(function () use ($overdueItems): void {
            $overdueItems->each(function (WorkOrderItem $item): void {
                $item->update(['status' => 'overdue']);
            });
        });

        WorkOrderItem::query()
            ->with(['workOrder.unit', 'workOrder.site', 'planningItem'])
            ->where('status', 'overdue')
            ->get()
            ->each(function (WorkOrderItem $item) use ($notifications): void {
                $notifications->maintenanceOverdue($item);
            });

        $this->info("{$overdueItems->count()} work order item overdue diproses.");

        return self::SUCCESS;
    }
}
