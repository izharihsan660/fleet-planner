<?php

namespace App\Jobs;

use App\Models\MaintenanceImport;
use App\Models\PlanningItem;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Services\MaintenanceImportReader;
use App\Services\PlanningIntervalResolver;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportUnitPlanningsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $maintenanceImportId) {}

    public function handle(MaintenanceImportReader $reader, PlanningIntervalResolver $intervalResolver): void
    {
        $import = MaintenanceImport::query()->findOrFail($this->maintenanceImportId);
        $import->update(['status' => 'processing']);

        $rows = $reader->rows(Storage::path($import->stored_path), $import->type);
        $units = Unit::query()->get()->keyBy(fn (Unit $unit): string => strtoupper($unit->current_plate));
        $items = PlanningItem::query()->get()->keyBy(fn (PlanningItem $item): string => strtoupper($item->name));
        $successRows = 0;
        $failedRows = 0;
        $estimatedRows = 0;
        $excludedRows = 0;
        $failures = [];

        DB::transaction(function () use ($rows, $units, $items, $reader, $intervalResolver, &$successRows, &$failedRows, &$estimatedRows, &$excludedRows, &$failures): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $unit = $units->get(strtoupper($row['plat_nomor'] ?? ''));
                $planningItem = $items->get(strtoupper($row['nama_item'] ?? ''));
                $lastDoneKm = $this->parseInteger($row['last_done_km'] ?? '0');
                $exclusion = $reader->parseExclusionMarker($row['last_done_date'] ?? '');
                $isExcluded = $exclusion !== null;
                $isEstimated = ! $isExcluded && str_contains(strtoupper($row['catatan'] ?? ''), 'TIDAK ADA RIWAYAT COMPLETE');

                if (! $unit || ! $planningItem || (! $isExcluded && $lastDoneKm > ($unit?->current_odo ?? 0))) {
                    $failedRows++;
                    $failures[] = ['line' => $line, 'plate' => $row['plat_nomor'] ?? '', 'item' => $row['nama_item'] ?? '', 'message' => 'Plat/item tidak valid atau KM melebihi odometer.'];

                    continue;
                }

                $lastDoneDate = ! $isExcluded && ($row['last_done_date'] ?? '') !== ''
                    ? CarbonImmutable::parse($row['last_done_date'])
                    : null;
                $interval = $isExcluded ? null : $intervalResolver->resolve($planningItem, $unit);

                UnitPlanning::query()->updateOrCreate(
                    ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
                    [
                        'last_done_km' => $lastDoneKm,
                        'last_done_date' => $lastDoneDate?->toDateString(),
                        'next_due_km' => $isExcluded ? null : $lastDoneKm + $interval['interval_km'],
                        'next_due_date' => $isExcluded ? null : $lastDoneDate?->addDays($interval['interval_days'])->toDateString(),
                        'is_estimated' => $isEstimated,
                        'is_excluded' => $isExcluded,
                        'excluded_reason' => $exclusion['reason'] ?? null,
                        'freeze_start' => null,
                    ],
                );

                $successRows++;
                $estimatedRows += $isEstimated ? 1 : 0;
                $excludedRows += $isExcluded ? 1 : 0;
            }
        });

        $import->update([
            'status' => 'finished',
            'success_rows' => $successRows,
            'failed_rows' => $failedRows,
            'estimated_rows' => $estimatedRows,
            'summary' => [
                'failures' => array_slice($failures, 0, 50),
                'excluded_rows' => $excludedRows,
            ],
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        MaintenanceImport::query()->whereKey($this->maintenanceImportId)->update([
            'status' => 'failed',
            'summary' => ['error' => $exception->getMessage()],
            'finished_at' => now(),
        ]);
    }

    private function parseInteger(string $value): int
    {
        return (int) preg_replace('/\D/', '', $value);
    }
}
