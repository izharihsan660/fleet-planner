<?php

namespace App\Console\Commands;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\WorkOrderProgressService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckOverdueMaintenance extends Command
{
    protected $signature = 'maintenance:check-overdue {--dry-run : Report changes without updating work order items}';

    protected $description = 'Mark overdue maintenance work order items and notify operation users.';

    public function handle(FleetNotificationService $notifications, WorkOrderProgressService $workOrderProgressService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $overdueItems = WorkOrderItem::query()
            ->applicable()
            ->with(['workOrder.unit:id,current_plate,current_odo', 'workOrder.site:id,name', 'planningItem:id,name', 'unitPlanning:id,last_done_km,last_done_date,next_due_date,next_due_km'])
            ->whereIn('status', ['on_hold', 'in_progress'])
            ->withBaseline()
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
            ->applicable()
            ->with(['workOrder.unit:id,current_plate,current_odo', 'unitPlanning:id,last_done_km,last_done_date,next_due_date,next_due_km'])
            ->where('status', 'overdue')
            ->whereDoesntHave('unitPlanning', fn ($unitPlanningQuery) => $unitPlanningQuery
                ->withBaseline()
                ->where(fn ($dueQuery) => $dueQuery
                    ->where(fn ($dateQuery) => $dateQuery
                        ->whereNotNull('next_due_date')
                        ->whereDate('next_due_date', '<', now()->toDateString()))
                    ->orWhereHas('unit', fn ($unitQuery) => $unitQuery
                        ->whereNotNull('unit_plannings.next_due_km')
                        ->whereColumn('units.current_odo', '>=', 'unit_plannings.next_due_km'))))
            ->get();

        $restoredCount = $staleOverdueItems->filter(
            fn (WorkOrderItem $item): bool => $item->isScheduled()
        )->count();

        if ($isDryRun) {
            $this->info("{$overdueItems->count()} work order item akan ditandai overdue.");
            $this->info("{$staleOverdueItems->count()} work order item overdue stale akan dikembalikan ke on_hold.");
            $this->info("{$restoredCount} di antaranya kembali ke in_progress karena mekanik dan jadwalnya masih ada.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($overdueItems, $staleOverdueItems, $workOrderProgressService): void {
            $overdueItems->each(function (WorkOrderItem $item): void {
                $item->update(['status' => 'overdue']);
            });

            // Item yang keluar dari overdue dikembalikan ke tahap kerjanya semula.
            // Item yang masih memegang mekanik penanggung jawab dan tanggalnya
            // sendiri berarti tadinya in_progress — memaksanya ke on_hold membuat
            // pekerjaan itu lenyap dari Tugas Saya mekanik tanpa ada yang tahu.
            $staleOverdueItems->each(function (WorkOrderItem $item): void {
                $item->update(['status' => $item->isScheduled() ? 'in_progress' : 'on_hold']);
            });

            $this->syncAffectedWorkOrders($overdueItems, $staleOverdueItems, $workOrderProgressService);
        });

        WorkOrderItem::query()
            ->applicable()
            ->with(['workOrder.unit', 'workOrder.site', 'planningItem'])
            ->where('status', 'overdue')
            ->withBaseline()
            ->get()
            ->groupBy(fn (WorkOrderItem $item): string => $item->workOrder->unit_id.'-'.$item->planning_item_id)
            ->each(function (Collection $items) use ($notifications): void {
                $notifications->maintenanceOverdue($items);
            });

        $this->info("{$overdueItems->count()} work order item overdue diproses.");
        $this->info("{$staleOverdueItems->count()} work order item overdue stale dikembalikan ke on_hold.");
        $this->info("{$restoredCount} di antaranya kembali ke in_progress karena mekanik dan jadwalnya masih ada.");

        return self::SUCCESS;
    }

    /**
     * Status WO diturunkan dari status itemnya, jadi menggeser status item tanpa
     * ikut menyinkronkan induknya membuat work_orders.status membeku di nilai
     * lama sampai ada aksi manual berikutnya.
     *
     * @param  Collection<int, WorkOrderItem>  $overdueItems
     * @param  Collection<int, WorkOrderItem>  $staleOverdueItems
     */
    private function syncAffectedWorkOrders(
        Collection $overdueItems,
        Collection $staleOverdueItems,
        WorkOrderProgressService $workOrderProgressService,
    ): void {
        $workOrderIds = $overdueItems
            ->merge($staleOverdueItems)
            ->pluck('work_order_id')
            ->unique()
            ->values();

        if ($workOrderIds->isEmpty()) {
            return;
        }

        WorkOrder::query()
            ->whereIn('id', $workOrderIds)
            ->get()
            ->each(fn (WorkOrder $workOrder) => $workOrderProgressService->sync($workOrder));
    }
}
