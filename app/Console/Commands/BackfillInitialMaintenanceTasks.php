<?php

namespace App\Console\Commands;

use App\Models\SystemThreshold;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceTriggerService;
use App\Services\WorkOrderProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BackfillInitialMaintenanceTasks extends Command
{
    protected $signature = 'maintenance:backfill-initial-tasks
                            {--dry-run : Report changes without updating the database (default).}
                            {--execute : Persist generated work orders and item transitions.}
                            {--quiet-notifications : Send one batch summary per recipient instead of per-item notifications.}';

    protected $description = 'Backfill initial maintenance work orders for existing due unit plannings.';

    public function handle(
        MaintenanceTriggerService $trigger,
        WorkOrderProgressService $progress,
        FleetNotificationService $notifications,
    ): int {
        $execute = (bool) $this->option('execute');
        if ($execute && $this->option('dry-run')) {
            $this->error('Pilih hanya satu dari --dry-run atau --execute.');

            return self::FAILURE;
        }

        $warningKm = $this->thresholdValue('warning_km', 500);
        $warningDays = $this->thresholdValue('warning_days', 7);
        $cutoffDate = CarbonImmutable::today()->addDays($warningDays)->toDateString();

        $eligiblePlannings = $this->eligiblePlannings($warningKm, $cutoffDate)
            ->with(['unit.site.area', 'planningItem:id,name'])
            ->orderBy('unit_id')
            ->orderBy('id')
            ->get();

        $existingItems = WorkOrderItem::query()
            ->applicable()
            ->whereIn('status', ['on_hold', 'overdue'])
            ->with(['unitPlanning.unit.site.area', 'planningItem:id,name', 'workOrder'])
            ->get();
        $rows = [];
        $createdItems = collect();
        $newlyOverdueItems = collect();
        $reportPath = null;

        $this->components->info($execute ? 'Executing initial maintenance backfill.' : 'Dry-run only. No database changes were made.');

        $process = function () use ($eligiblePlannings, $existingItems, $execute, $trigger, $progress, $notifications, &$rows, &$createdItems, &$newlyOverdueItems, &$reportPath): void {
            $affectedWorkOrderIds = [];

            foreach ($eligiblePlannings as $planning) {
                $status = $trigger->dueStatus($planning->unit, $planning);
                if ($status === null) {
                    continue;
                }

                $item = $execute ? $trigger->triggerPlanning($planning, notify: false) : null;
                if ($execute && $item === null) {
                    continue;
                }

                if ($item !== null) {
                    $createdItems->push($item);
                    $affectedWorkOrderIds[] = $item->work_order_id;
                }

                $rows[] = $this->reportRow($planning, $status === 'overdue' ? 'task baru lewat due' : 'task baru masuk warning');
            }

            foreach ($existingItems as $item) {
                $planning = $item->unitPlanning;
                $status = $trigger->dueStatus($planning->unit, $planning);
                $action = $item->status === 'on_hold' && $status === 'overdue'
                    ? 'on_hold->overdue'
                    : ($item->status === 'overdue' && $status !== 'overdue'
                        ? ($item->isScheduled() ? 'overdue->in_progress' : 'overdue->on_hold') : null);

                if ($action === null) {
                    continue;
                }

                if ($execute) {
                    $targetStatus = $status === 'overdue' ? 'overdue' : ($item->isScheduled() ? 'in_progress' : 'on_hold');
                    if (WorkOrderItem::query()->whereKey($item->id)->where('status', $item->status)->update(['status' => $targetStatus]) !== 1) {
                        continue;
                    }

                    $item->status = $targetStatus;
                    $affectedWorkOrderIds[] = $item->work_order_id;
                    if ($targetStatus === 'overdue') {
                        $newlyOverdueItems->push($item);
                    }
                }

                $rows[] = $this->reportRow($planning, $action);
            }

            if ($execute) {
                WorkOrder::query()->whereIn('id', array_unique($affectedWorkOrderIds))->get()
                    ->each(fn (WorkOrder $workOrder) => $progress->sync($workOrder));
            }

            $reportPath = $this->writeReport($rows);

            if ($execute) {
                $siteIds = collect($rows)->pluck('site_id')->unique()->values()->all();
                if ($this->option('quiet-notifications')) {
                    $notifications->batchSummary('backfill_summary', 'Backfill maintenance', sprintf(
                        'Backfill: %d task baru dibuat, %d task jadi overdue.',
                        $createdItems->count(),
                        $newlyOverdueItems->count(),
                    ), $siteIds, ['report_path' => $reportPath]);
                } else {
                    $createdItems->each(fn (WorkOrderItem $item) => $notifications->taskAutoGenerated($item));
                    $createdItems->where('status', 'overdue')->concat($newlyOverdueItems)
                        ->each(fn (WorkOrderItem $item) => $item->loadMissing(['workOrder.unit', 'workOrder.site', 'planningItem']))
                        ->groupBy(fn (WorkOrderItem $item): string => $item->workOrder->unit_id.'-'.$item->planning_item_id)
                        ->each(fn ($items) => $notifications->maintenanceOverdue($items));
                }
            }
        };

        try {
            if ($execute) {
                DB::transaction($process);
            } else {
                $process();
            }
        } catch (\Throwable $exception) {
            if ($reportPath !== null && is_file($reportPath)) {
                unlink($reportPath);
            }

            $this->error('Backfill dibatalkan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Region', 'Task baru lewat due', 'Task baru masuk warning', 'On hold -> overdue', 'Overdue -> on hold', 'Overdue -> in progress'],
            collect($rows)->groupBy('region')->map(fn ($group, $region): array => [
                $region,
                $group->where('aksi', 'task baru lewat due')->count(),
                $group->where('aksi', 'task baru masuk warning')->count(),
                $group->where('aksi', 'on_hold->overdue')->count(),
                $group->where('aksi', 'overdue->on_hold')->count(),
                $group->where('aksi', 'overdue->in_progress')->count(),
            ])->values()->all());
        $newTaskRows = collect($rows)->filter(fn (array $row): bool => str_starts_with($row['aksi'], 'task baru'));
        $this->table(['Metric', 'Count'], [
            ['WorkOrders', $newTaskRows->pluck('unit_id')->unique()->count()],
            ['WorkOrderItems total', $newTaskRows->count()],
            ['WorkOrderItems on_hold', $newTaskRows->where('aksi', 'task baru masuk warning')->count()],
            ['WorkOrderItems overdue', $newTaskRows->where('aksi', 'task baru lewat due')->count()],
        ]);

        $this->line('CSV: '.$reportPath);

        if ($execute) {
            $this->components->info('Initial maintenance backfill completed.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<UnitPlanning>
     */
    private function eligiblePlannings(int $warningKm, string $cutoffDate): Builder
    {
        return UnitPlanning::query()
            ->applicable()
            ->join('units', 'units.id', '=', 'unit_plannings.unit_id')
            ->select('unit_plannings.*')
            ->whereDoesntHave('workOrderItems', fn (Builder $query) => $query->blocking())
            ->where(function (Builder $query) use ($warningKm, $cutoffDate): void {
                $query
                    ->where(function (Builder $dateQuery) use ($cutoffDate): void {
                        $dateQuery
                            ->dueByDate()
                            ->whereDate('unit_plannings.next_due_date', '<=', $cutoffDate);
                    })
                    ->orWhere(function (Builder $kmQuery) use ($warningKm): void {
                        $kmQuery
                            ->dueByKm()
                            ->whereRaw('units.current_odo >= unit_plannings.next_due_km - ?', [$warningKm]);
                    });
            });
    }

    /**
     * @return array{plat: string, site: string, region: string, item: string, next_due_date: ?string, aksi: string, site_id: int, unit_id: int}
     */
    private function reportRow(UnitPlanning $planning, string $action): array
    {
        return [
            'plat' => $planning->unit->current_plate,
            'site' => $planning->unit->site?->name ?? '-',
            'region' => $planning->unit->site?->area?->name ?? $planning->unit->site?->region ?? '-',
            'item' => $planning->planningItem?->name ?? '-',
            'next_due_date' => $planning->next_due_date?->toDateString(),
            'aksi' => $action,
            'site_id' => $planning->unit->site_id,
            'unit_id' => $planning->unit_id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeReport(array $rows): string
    {
        $directory = storage_path('app/import-reports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/backfill-'.now()->format('Ymd-His-u').'.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Gagal menulis laporan backfill.');
        }

        try {
            fputcsv($handle, ['plat', 'site', 'region', 'item', 'next_due_date', 'aksi']);
            foreach ($rows as $row) {
                fputcsv($handle, [$row['plat'], $row['site'], $row['region'], $row['item'], $row['next_due_date'], $row['aksi']]);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function thresholdValue(string $key, int $default): int
    {
        return (int) (SystemThreshold::query()->where('key', $key)->value('value') ?? $default);
    }
}
