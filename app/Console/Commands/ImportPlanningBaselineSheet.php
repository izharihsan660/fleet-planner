<?php

namespace App\Console\Commands;

use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceTriggerService;
use App\Services\PlanningBaselineSheetReader;
use App\Services\PlanningIntervalResolver;
use App\Services\UnitPlanningBaselineService;
use App\Services\WorkOrderProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;

class ImportPlanningBaselineSheet extends Command
{
    protected $signature = 'planning:import-baseline-sheet
                            {file : XLSX path, absolute or relative to storage/app}
                            {--sheet=Siap Import : Worksheet name}
                            {--dry-run : Simulate changes and roll back}
                            {--execute : Apply changes in one transaction}
                            {--force-overwrite : Override newer application baseline data}';

    protected $description = 'Import corrected maintenance baselines from a typed Excel worksheet.';

    private const LOCKED_STATUSES = ['in_progress', 'replace', 'postpone', 'pending_create', 'rejected'];

    public function handle(
        PlanningBaselineSheetReader $reader,
        PlanningIntervalResolver $intervalResolver,
        UnitPlanningBaselineService $baselineService,
        MaintenanceTriggerService $trigger,
        WorkOrderProgressService $progress,
        FleetNotificationService $notifications,
    ): int {
        if ((bool) $this->option('dry-run') === (bool) $this->option('execute')) {
            $this->error('Pilih tepat satu: --dry-run atau --execute.');

            return self::FAILURE;
        }

        $argument = (string) $this->argument('file');
        $path = str_starts_with($argument, '/') ? $argument : storage_path('app/'.$argument);
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('File tidak ditemukan atau tidak dapat dibaca: '.$path);

            return self::FAILURE;
        }

        try {
            $rows = $reader->read($path, (string) $this->option('sheet'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $units = Unit::query()->with(['plateHistories', 'site'])->get();
        $items = PlanningItem::query()->with('overrides')->get();
        $plannings = UnitPlanning::query()->with(['unit.site', 'planningItem.overrides'])
            ->whereIn('unit_id', $units->pluck('id'))
            ->get()
            ->keyBy(fn (UnitPlanning $planning): string => $planning->unit_id.':'.$planning->planning_item_id);
        $currentPlates = $units->keyBy(fn (Unit $unit): string => $this->normalise($unit->current_plate));
        $historicalPlates = $units->flatMap(fn (Unit $unit) => $unit->plateHistories
            ->mapWithKeys(fn ($history): array => [$this->normalise($history->plate_number) => $unit]));
        $planningItems = $items->keyBy(fn (PlanningItem $item): string => mb_strtolower(trim($item->name)));
        $rowKey = function (array $row) use ($currentPlates, $historicalPlates, $planningItems): string {
            $plate = $this->normalise((string) $row['plate']);
            $itemName = mb_strtolower(trim((string) $row['item']));
            $unit = $currentPlates->get($plate) ?? $historicalPlates->get($plate);
            $planningItem = $planningItems->get($itemName);

            return ($unit?->id ?? $plate).'|'.($planningItem?->id ?? $itemName);
        };
        $duplicateCounts = collect($rows)->countBy($rowKey);

        $reports = [];
        $changed = [];
        $odoCandidates = [];
        $affectedWorkOrders = [];
        $lockedRows = [];
        $outcomes = array_fill_keys(['CLOSED', 'KEPT', 'CLOSED_LOCKED', 'KEPT_LOCKED', 'CREATED'], 0);
        $updatedCount = 0;
        $createdCount = 0;
        $closedCount = 0;
        $closedLockedCount = 0;
        $raisedUnits = 0;
        $filename = basename($path);
        $reportPath = null;

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $report = $this->initialReport($row);
                $key = $rowKey($row);

                if ($duplicateCounts[$key] > 1) {
                    $reports[] = $this->reject($report, 'REJECTED', 'plat+item duplikat dalam file');

                    continue;
                }

                if ($this->blank($row['km']) && $this->blank($row['last_date']) && $this->blank($row['next_date'])) {
                    $reports[] = $this->reject($report, 'SKIP_EMPTY', 'KM, tanggal, dan next kosong');

                    continue;
                }

                $unit = $currentPlates->get($this->normalise((string) $row['plate']))
                    ?? $historicalPlates->get($this->normalise((string) $row['plate']));
                $planningItem = $planningItems->get(mb_strtolower(trim((string) $row['item'])));
                if ($unit === null || $planningItem === null) {
                    $reports[] = $this->reject($report, 'REJECTED', $unit === null ? 'plat tidak ditemukan' : 'item tidak ditemukan');

                    continue;
                }

                $planning = $plannings->get($unit->id.':'.$planningItem->id);
                if ($planning === null) {
                    $reports[] = $this->reject($report, 'REJECTED', 'unit_planning tidak ditemukan');

                    continue;
                }

                $report['site'] = $unit->site?->name;
                $report['region'] = $unit->site?->area?->name ?? $unit->site?->region;
                $report['site_id'] = $unit->site_id;
                $report['last_done_km'] = $this->changeText($planning->last_done_km, $planning->last_done_km);
                $report['last_done_date'] = $this->changeText($planning->last_done_date?->toDateString(), $planning->last_done_date?->toDateString());
                $report['next_due_km'] = $this->changeText($planning->next_due_km, $planning->next_due_km);
                $report['next_due_date'] = $this->changeText($planning->next_due_date?->toDateString(), $planning->next_due_date?->toDateString());
                $report['due_manually_set'] = $this->changeText((int) $planning->due_manually_set, (int) $planning->due_manually_set);
                $report['odometer'] = $this->changeText($unit->current_odo, $unit->current_odo);
                if ($planning->is_excluded) {
                    $reports[] = $this->reject($report, 'SKIP_EXCLUDED', 'planning Tidak Berlaku');

                    continue;
                }

                try {
                    $km = $this->parseKm($row['km']);
                    $lastDate = $this->parseDate($row['last_date'], (bool) $row['last_date_excel']);
                    $nextDate = $this->parseDate($row['next_date'], (bool) $row['next_date_excel']);
                } catch (InvalidArgumentException $exception) {
                    $reports[] = $this->reject($report, 'REJECTED', $exception->getMessage());

                    continue;
                }

                if ($lastDate?->greaterThan(CarbonImmutable::today())) {
                    $reports[] = $this->reject($report, 'REJECTED', 'tanggal terakhir di masa depan');

                    continue;
                }

                if ($lastDate !== null && $nextDate !== null && $nextDate->lessThanOrEqualTo($lastDate)) {
                    $nextDate = null;
                    $report['note'] = trim($report['note'].'; NEXT diabaikan karena tidak setelah tanggal terakhir', '; ');
                }

                if ($km !== null && $km > $unit->current_odo) {
                    if ($lastDate === null) {
                        $reports[] = $this->reject($report, 'REJECTED', 'KM melebihi odometer tanpa tanggal');

                        continue;
                    }

                    if (InspectionLog::query()->where('unit_id', $unit->id)
                        ->whereDate('inspection_date', '>', $lastDate->toDateString())
                        ->where('odometer', '<', $km)->exists()) {
                        $reports[] = $this->reject($report, 'REJECTED', 'bertentangan dengan histori inspeksi');

                        continue;
                    }

                    if (InspectionLog::query()->where('unit_id', $unit->id)
                        ->whereDate('inspection_date', $lastDate->toDateString())
                        ->where('odometer', '!=', $km)->exists()) {
                        $reports[] = $this->reject($report, 'REJECTED', 'inspeksi pada tanggal tersebut memiliki KM berbeda');

                        continue;
                    }
                }

                $existingDate = $planning->last_done_date === null ? null : CarbonImmutable::parse($planning->last_done_date);
                $existingEmpty = $planning->isBaselineMissing() && $existingDate === null;
                $nextOnly = $km === null && $lastDate === null && $nextDate !== null;
                $kmOnly = $km !== null && $lastDate === null && $nextDate === null;

                if (! $existingEmpty && ! $this->option('force-overwrite') && ! $nextOnly) {
                    $conflict = $this->conflictReason($planning, $lastDate, $km, $kmOnly, $existingDate);
                    if ($conflict !== null) {
                        $reports[] = $this->reject($report, 'CONFLICT', $conflict);

                        continue;
                    }
                }

                $interval = $intervalResolver->resolve($planningItem, $unit);
                $newKm = $nextOnly ? (int) $planning->last_done_km : ($km ?? 0);
                $newDate = ($nextOnly || $kmOnly) ? $existingDate : $lastDate;
                $newDueKm = $newKm > 0 ? $newKm + $interval['interval_km'] : null;
                $newDueDate = $nextDate?->toDateString()
                    ?? ($kmOnly ? $planning->next_due_date?->toDateString() : $newDate?->addDays($interval['interval_days'])->toDateString());
                $manual = $nextDate !== null || ($kmOnly && $planning->due_manually_set);
                $changes = [
                    'last_done_km' => $newKm,
                    'last_done_date' => $newDate?->toDateString(),
                    'next_due_km' => $newDueKm,
                    'next_due_date' => $newDueDate,
                    'due_manually_set' => $manual,
                    'is_estimated' => false,
                ];
                $before = $planning->only(array_keys($changes));
                $before['last_done_date'] = $existingDate?->toDateString();
                $before['next_due_date'] = $planning->next_due_date?->toDateString();

                if ($before === $changes) {
                    $reports[] = $this->reject($report, 'UNCHANGED', 'data sudah sama');

                    continue;
                }

                foreach (['last_done_km', 'last_done_date', 'next_due_km', 'next_due_date', 'due_manually_set'] as $field) {
                    $report[$field] = $this->changeText($before[$field], $changes[$field]);
                }

                $planning->update($changes);
                $report['result'] = 'UPDATED';
                $reports[] = $report;
                $reportIndex = count($reports) - 1;
                $changed[] = ['planning' => $planning, 'index' => $reportIndex, 'previous_km' => $before['next_due_km'], 'previous_date' => $before['next_due_date']];
                $updatedCount++;

                if ($km !== null && $km > $unit->current_odo
                    && (! isset($odoCandidates[$unit->id]) || $km > $odoCandidates[$unit->id]['km'])) {
                    $odoCandidates[$unit->id] = ['unit' => $unit, 'km' => $km, 'date' => $lastDate];
                }
            }

            foreach ($odoCandidates as $candidate) {
                $unit = $candidate['unit'];
                $previousOdo = $unit->current_odo;
                if (! InspectionLog::query()->where('unit_id', $unit->id)
                    ->whereDate('inspection_date', $candidate['date']->toDateString())->exists()) {
                    InspectionLog::query()->create([
                        'unit_id' => $unit->id,
                        'mechanic_id' => null,
                        'inspection_date' => $candidate['date']->toDateString(),
                        'odometer' => $candidate['km'],
                        'previous_odo' => $previousOdo,
                    ]);
                }

                $unit->update(['current_odo' => $candidate['km'], 'has_odometer_reading' => true]);
                $raisedUnits++;
                foreach ($changed as $entry) {
                    if ($entry['planning']->unit_id === $unit->id) {
                        $reports[$entry['index']]['odometer'] = $this->changeText($previousOdo, $candidate['km']);
                    }
                }
            }

            foreach ($changed as $entry) {
                $planning = $entry['planning'];
                $unit = $planning->unit->refresh();
                $reportIndex = $entry['index'];
                $status = $trigger->dueStatus($unit, $planning);
                $activeItems = WorkOrderItem::query()->where('unit_planning_id', $planning->id)->blocking()->lockForUpdate()->get();

                foreach ($activeItems as $item) {
                    $oldStatus = $item->status;
                    $baselineService->updateItemSnapshot($item, $planning, $entry['previous_km'], $entry['previous_date']);
                    $locked = in_array($oldStatus, self::LOCKED_STATUSES, true);
                    $outcome = $locked ? ($status === null ? 'CLOSED_LOCKED' : 'KEPT_LOCKED') : ($status === null ? 'CLOSED' : 'KEPT');

                    if ($status === null) {
                        $reason = 'Baseline diperbarui via import '.$filename;
                        if ($locked) {
                            $reason .= sprintf(' - sebelumnya %s, action %s, submitted_by %s, approved_by %s',
                                $oldStatus, $item->action ?? '-', $item->submitted_by ?? '-', $item->approved_by ?? '-');
                        }

                        $item->update(['status' => 'cancelled', 'reason' => $reason]);
                        $closedCount++;
                        $closedLockedCount += (int) $locked;
                    } elseif (! $locked && ! in_array($oldStatus, ['blocked', 'breakdown'], true)) {
                        $item->update(['status' => $status]);
                    }

                    $affectedWorkOrders[$item->work_order_id] = true;
                    $outcomes[$outcome]++;
                    $reports[$reportIndex]['impact'] = trim($reports[$reportIndex]['impact'].' '.$outcome.' #'.$item->id.' ('.$oldStatus.')');

                    if ($locked) {
                        $lockedRows[] = [
                            $unit->current_plate, $unit->site?->name ?? '-', $planning->planningItem?->name ?? '-',
                            $oldStatus, $item->action ?? '-', $item->submitted_by ?? '-', $item->approved_by ?? '-',
                            'KM '.($entry['previous_km'] ?? '-').', tanggal '.($entry['previous_date'] ?? '-'),
                            'KM '.($planning->next_due_km ?? '-').', tanggal '.($planning->next_due_date?->toDateString() ?? '-'), $outcome,
                        ];
                    }
                }

                if ($activeItems->isEmpty() && $status !== null) {
                    $newItem = $trigger->triggerPlanning($planning, notify: false);
                    if ($newItem !== null) {
                        $createdCount++;
                        $outcomes['CREATED']++;
                        $affectedWorkOrders[$newItem->work_order_id] = true;
                        $reports[$reportIndex]['impact'] = 'CREATED #'.$newItem->id;
                    }
                }
            }

            WorkOrder::query()->whereIn('id', array_keys($affectedWorkOrders))->get()
                ->each(fn (WorkOrder $workOrder) => $progress->sync($workOrder));

            $reportPath = $this->writeReport($reports, $filename);

            if ($this->option('execute')) {
                if ($updatedCount > 0) {
                    $siteIds = collect($changed)->map(fn (array $entry): int => $entry['planning']->unit->site_id)->unique()->values()->all();
                    $notifications->batchSummary('baseline_import_summary', 'Import baseline', sprintf(
                        'Import baseline %s: %d baseline diperbarui, %d task baru, %d task ditutup (termasuk %d yang sedang diproses/menunggu approval).',
                        $filename, $updatedCount, $createdCount, $closedCount, $closedLockedCount,
                    ), $siteIds, ['report_path' => $reportPath]);
                }

                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            if ($reportPath !== null && is_file($reportPath)) {
                unlink($reportPath);
            }
            $this->error('Import dibatalkan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metrik', 'Jumlah'], [
            ['total', count($rows)],
            ...collect($reports)->groupBy('result')->map(fn ($group, $result): array => [$result, $group->count()])->values()->all(),
            ...collect($reports)->whereIn('result', ['REJECTED', 'CONFLICT'])->groupBy('reason')->map(fn ($group, $reason): array => [$reason, $group->count()])->values()->all(),
            ...collect($outcomes)->map(fn (int $count, string $name): array => [$name, $count])->values()->all(),
            ['unit odometer dinaikkan', $raisedUnits],
        ]);
        $this->table(['Plat', 'Site', 'Item', 'Status lama', 'Action', 'Submitted by', 'Approved by', 'Due lama', 'Due baru', 'Hasil'], $lockedRows);

        $this->line('CSV: '.$reportPath);

        return self::SUCCESS;
    }

    private function normalise(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)));
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function parseKm(mixed $value): ?int
    {
        if ($this->blank($value)) {
            return null;
        }

        if (! (is_int($value) || is_float($value)) || ! is_finite((float) $value) || $value > PHP_INT_MAX || floor((float) $value) !== (float) $value) {
            throw new InvalidArgumentException('KM harus integer >= 1');
        }

        if ((int) $value === 0) {
            throw new InvalidArgumentException('KM 0 tidak valid');
        }

        if ((int) $value < 0) {
            throw new InvalidArgumentException('KM harus integer >= 1');
        }

        return (int) $value;
    }

    private function parseDate(mixed $value, bool $isExcelDate): ?CarbonImmutable
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_string($value)) {
            try {
                $date = CarbonImmutable::createFromFormat('!d/m/Y', $value);
            } catch (Throwable) {
                throw new InvalidArgumentException('format tanggal tidak dikenal');
            }

            if ($date === false || $date->format('d/m/Y') !== $value) {
                throw new InvalidArgumentException('format tanggal tidak dikenal');
            }

            return $date;
        }

        if (! $isExcelDate || ! is_numeric($value)) {
            throw new InvalidArgumentException('format tanggal tidak dikenal');
        }

        $date = CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value))->startOfDay();
        if ($date->day <= 12) {
            throw new InvalidArgumentException('tanggal ambigu');
        }

        return $date;
    }

    private function conflictReason(UnitPlanning $planning, ?CarbonImmutable $lastDate, ?int $km, bool $kmOnly, ?CarbonImmutable $existingDate): ?string
    {
        if ($lastDate !== null && WorkOrderItem::query()->where('unit_planning_id', $planning->id)
            ->where('status', 'complete')->whereDate('completed_date', '>=', $lastDate->toDateString())->exists()) {
            return 'data aplikasi lebih baru';
        }

        if ($kmOnly || ($lastDate === null && $km !== null)) {
            return $km > (int) $planning->last_done_km ? null : 'file lebih lama';
        }

        return $lastDate !== null && $existingDate !== null && $lastDate->lessThan($existingDate) ? 'file lebih lama' : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function initialReport(array $row): array
    {
        return [
            'plate' => (string) $row['plate'], 'site' => null, 'region' => null, 'site_id' => null,
            'item' => (string) $row['item'], 'km_file' => $row['km'],
            'last_date_file' => $row['last_date'], 'next_date_file' => $row['next_date'],
            'result' => '', 'reason' => '', 'last_done_km' => '', 'last_done_date' => '',
            'next_due_km' => '', 'next_due_date' => '', 'due_manually_set' => '', 'impact' => 'NONE',
            'odometer' => '', 'note' => (string) ($row['note'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function reject(array $report, string $result, string $reason): array
    {
        $report['result'] = $result;
        $report['reason'] = $reason;

        return $report;
    }

    private function changeText(mixed $before, mixed $after): string
    {
        return ($before === null ? 'NULL' : (string) $before).' -> '.($after === null ? 'NULL' : (string) $after);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reports
     */
    private function writeReport(array $reports, string $filename): string
    {
        $directory = storage_path('app/import-reports');
        File::ensureDirectoryExists($directory);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($filename, PATHINFO_FILENAME));
        $path = $directory.'/baseline-'.$safeName.'-'.now()->format('Ymd-His-u').'.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Gagal menulis laporan import.');
        }

        try {
            fputcsv($handle, ['plat', 'item', 'km_file', 'tanggal_file', 'next_file', 'hasil', 'alasan',
                'last_done_km lama->baru', 'last_done_date lama->baru', 'next_due_km lama->baru',
                'next_due_date lama->baru', 'due_manually_set lama->baru', 'dampak WO', 'odometer lama->baru', 'catatan']);
            foreach ($reports as $report) {
                fputcsv($handle, [
                    $report['plate'], $report['item'], $report['km_file'], $report['last_date_file'], $report['next_date_file'],
                    $report['result'], $report['reason'], $report['last_done_km'], $report['last_done_date'],
                    $report['next_due_km'], $report['next_due_date'], $report['due_manually_set'],
                    $report['impact'], $report['odometer'], $report['note'],
                ]);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }
}
