<?php

namespace App\Console\Commands;

use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckOverdueMaintenance extends Command
{
    protected $signature = 'maintenance:check-overdue {--dry-run : Report changes without updating work order items}';

    protected $description = 'Mark overdue maintenance work order items and notify operation users.';

    public function handle(FleetNotificationService $notifications): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $overdueItems = WorkOrderItem::query()
            ->with(['workOrder.unit:id,current_plate,current_odo', 'workOrder.site:id,name', 'planningItem:id,name', 'unitPlanning:id,next_due_date,next_due_km'])
            ->whereIn('status', ['on_hold', 'in_progress'])
            ->where(function ($query): void {
                $query
                    ->whereHas('unitPlanning', fn ($unitPlanningQuery) => $unitPlanningQuery
                        ->whereNotNull('next_due_date')
                        ->whereDate('next_due_date', '<', now()->toDateString()))
                    ->orWhereHas('unitPlanning.unit', function ($unitQuery): void {
                        $unitQuery
                            ->whereNotNull('unit_plannings.next_due_km')
                            ->whereColumn('units.current_odo', '>=', 'unit_plannings.next_due_km');
                    });
            })
            ->get();

        $staleOverdueItems = WorkOrderItem::query()
            ->with(['workOrder.unit:id,current_plate,current_odo', 'unitPlanning:id,next_due_date,next_due_km'])
            ->where('status', 'overdue')
            ->whereDoesntHave('unitPlanning', fn ($unitPlanningQuery) => $unitPlanningQuery
                ->where(fn ($dueQuery) => $dueQuery
                    ->whereNotNull('next_due_date')
                    ->whereDate('next_due_date', '<', now()->toDateString()))
                ->orWhereHas('unit', fn ($unitQuery) => $unitQuery
                    ->whereNotNull('unit_plannings.next_due_km')
                    ->whereColumn('units.current_odo', '>=', 'unit_plannings.next_due_km')))
            ->get();

        if ($isDryRun) {
            $this->info("{$overdueItems->count()} work order item akan ditandai overdue.");
            $this->info("{$staleOverdueItems->count()} work order item overdue stale akan dikembalikan ke on_hold.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($overdueItems, $staleOverdueItems): void {
            $overdueItems->each(function (WorkOrderItem $item): void {
                $item->update(['status' => 'overdue']);
            });

            $staleOverdueItems->each(function (WorkOrderItem $item): void {
                $item->update(['status' => 'on_hold']);
            });
        });

        WorkOrderItem::query()
            ->with(['workOrder.unit', 'workOrder.site', 'planningItem'])
            ->where('status', 'overdue')
            ->get()
            ->groupBy(fn (WorkOrderItem $item): string => $item->workOrder->unit_id.'-'.$item->planning_item_id)
            ->each(function (Collection $items) use ($notifications): void {
                $notifications->maintenanceOverdue($items);
            });

        $this->info("{$overdueItems->count()} work order item overdue diproses.");
        $this->info("{$staleOverdueItems->count()} work order item overdue stale dikembalikan ke on_hold.");

        return self::SUCCESS;
    }
}
