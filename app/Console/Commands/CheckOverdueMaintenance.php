<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkOrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckOverdueMaintenance extends Command
{
    protected $signature = 'maintenance:check-overdue';

    protected $description = 'Mark overdue maintenance work order items and notify operation users.';

    public function handle(): int
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
                $this->notifyUsers($item->refresh()->load(['workOrder.unit', 'workOrder.site', 'planningItem']));
            });
        });

        $this->info("{$overdueItems->count()} work order item overdue diproses.");

        return self::SUCCESS;
    }

    private function notifyUsers(WorkOrderItem $item): void
    {
        $users = User::query()
            ->whereIn('role', [UserRole::SpvOps->value, UserRole::PlannerHo->value])
            ->get();

        $users->each(function (User $user) use ($item): void {
            $data = [
                'work_order_item_id' => $item->id,
                'work_order_id' => $item->work_order_id,
                'unit_id' => $item->workOrder->unit_id,
                'url' => route('work-orders.show', $item->workOrder),
            ];

            $exists = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', 'maintenance_overdue')
                ->where('data->work_order_item_id', $item->id)
                ->whereNull('read_at')
                ->exists();

            if ($exists) {
                return;
            }

            Notification::query()->create([
                'user_id' => $user->id,
                'type' => 'maintenance_overdue',
                'title' => 'Item maintenance overdue',
                'message' => sprintf(
                    '%s - %s di %s sudah overdue.',
                    $item->workOrder->unit?->current_plate ?? 'Unit',
                    $item->planningItem?->name ?? 'Item',
                    $item->workOrder->site?->name ?? 'site'
                ),
                'data' => $data,
            ]);
        });
    }
}
