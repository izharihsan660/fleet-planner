<?php

namespace App\Console\Commands;

use App\Models\SystemThreshold;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BackfillInitialMaintenanceTasks extends Command
{
    protected $signature = 'maintenance:backfill-initial-tasks
                            {--execute : Persist generated work orders and items. Without this option the command only reports a dry-run.}';

    protected $description = 'Backfill initial maintenance work orders for existing due unit plannings.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $warningKm = $this->thresholdValue('warning_km', 500);
        $warningDays = $this->thresholdValue('warning_days', 7);
        $today = CarbonImmutable::today();
        $cutoffDate = $today->addDays($warningDays)->toDateString();

        $eligiblePlannings = $this->eligiblePlannings($warningKm, $cutoffDate)
            ->with(['unit:id,site_id,current_plate,current_odo', 'planningItem:id,name'])
            ->orderBy('unit_id')
            ->orderBy('id')
            ->get();

        $overdueCount = $eligiblePlannings->filter(fn (UnitPlanning $planning): bool => $this->isOverdue($planning, $today))->count();
        $onHoldCount = $eligiblePlannings->count() - $overdueCount;
        $workOrderCount = $eligiblePlannings->pluck('unit_id')->unique()->count();

        $this->components->info($execute ? 'Executing initial maintenance backfill.' : 'Dry-run only. No database changes were made.');
        $this->table(['Metric', 'Count'], [
            ['WorkOrders', $workOrderCount],
            ['WorkOrderItems total', $eligiblePlannings->count()],
            ['WorkOrderItems on_hold', $onHoldCount],
            ['WorkOrderItems overdue', $overdueCount],
        ]);

        if (! $execute || $eligiblePlannings->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($eligiblePlannings, $today): void {
            $eligiblePlannings
                ->groupBy('unit_id')
                ->each(function ($unitPlannings) use ($today): void {
                    /** @var UnitPlanning $firstPlanning */
                    $firstPlanning = $unitPlannings->first();

                    $workOrder = WorkOrder::query()
                        ->where('unit_id', $firstPlanning->unit_id)
                        ->where('status', 'open')
                        ->latest('id')
                        ->first();

                    if (! $workOrder) {
                        $workOrder = WorkOrder::query()->create([
                            'unit_id' => $firstPlanning->unit_id,
                            'site_id' => $firstPlanning->unit->site_id,
                            'trigger_type' => 'normal',
                            'status' => 'open',
                        ]);
                    }

                    $unitPlannings->each(function (UnitPlanning $planning) use ($workOrder, $today): void {
                        WorkOrderItem::query()->create([
                            'work_order_id' => $workOrder->id,
                            'unit_planning_id' => $planning->id,
                            'planning_item_id' => $planning->planning_item_id,
                            'status' => $this->isOverdue($planning, $today) ? 'overdue' : 'on_hold',
                        ]);
                    });
                });
        });

        $this->components->info('Initial maintenance backfill completed.');

        return self::SUCCESS;
    }

    /**
     * @return Builder<UnitPlanning>
     */
    private function eligiblePlannings(int $warningKm, string $cutoffDate): Builder
    {
        return UnitPlanning::query()
            ->join('units', 'units.id', '=', 'unit_plannings.unit_id')
            ->select('unit_plannings.*')
            ->whereDoesntHave('workOrderItems', fn (Builder $query) => $query->whereNotIn('status', ['complete', 'postponed', 'cancelled']))
            ->where(function (Builder $query) use ($warningKm, $cutoffDate): void {
                $query
                    ->where(function (Builder $dateQuery) use ($cutoffDate): void {
                        $dateQuery
                            ->whereNotNull('unit_plannings.next_due_date')
                            ->whereDate('unit_plannings.next_due_date', '<=', $cutoffDate);
                    })
                    ->orWhere(function (Builder $kmQuery) use ($warningKm): void {
                        $kmQuery
                            ->whereNotNull('unit_plannings.next_due_km')
                            ->whereRaw('units.current_odo >= unit_plannings.next_due_km - ?', [$warningKm]);
                    });
            });
    }

    private function isOverdue(UnitPlanning $planning, CarbonImmutable $today): bool
    {
        $isDateOverdue = $planning->next_due_date !== null
            && $today->greaterThan(CarbonImmutable::parse($planning->next_due_date));
        $isKmOverdue = $planning->next_due_km !== null
            && $planning->unit !== null
            && $planning->unit->current_odo >= $planning->next_due_km;

        return $isDateOverdue || $isKmOverdue;
    }

    private function thresholdValue(string $key, int $default): int
    {
        return (int) (SystemThreshold::query()->where('key', $key)->value('value') ?? $default);
    }
}
