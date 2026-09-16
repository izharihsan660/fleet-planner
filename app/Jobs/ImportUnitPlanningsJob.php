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
        $skippedRows = 0;
        $failures = [];
        DB::transaction(function () use ($rows, $units, $items, $reader, $intervalResolver, &$successRows, &$failedRows, &$estimatedRows, &$excludedRows, &$skippedRows, &$failures): void {
            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $unit = $units->get(strtoupper($row['plat_nomor'] ?? ''));
                $planningItem = $items->get(strtoupper($row['nama_item'] ?? ''));
                $lastDoneKm = $this->parseInteger($row['last_done_km'] ?? '0');
                $lastDoneDateValue = $row['last_done_date'] ?? '';
                $exclusion = $reader->parseExclusionMarker($lastDoneDateValue);
                $isExcluded = $exclusion !== null;
                $isEstimated = ! $isExcluded && str_contains(strtoupper($row['catatan'] ?? ''), 'TIDAK ADA RIWAYAT COMPLETE');

                if (! $unit || ! $planningItem || (! $isExcluded && $unit->has_odometer_reading && $lastDoneKm > $unit->current_odo)) {
                    $failedRows++;
                    $failures[] = ['line' => $line, 'plate' => $row['plat_nomor'] ?? '', 'item' => $row['nama_item'] ?? '', 'message' => 'Plat/item tidak valid atau KM melebihi odometer.'];

                    continue;
                }

                $lastDoneDate = $isExcluded ? null : $reader->parseLastDoneDate($lastDoneDateValue);
                $hasLastDoneKm = $lastDoneKm > 0;

                // Baris tanpa KM, tanpa tanggal, dan tanpa penanda "TIDAK PERLU" tidak
                // membawa informasi apa pun. Menuliskannya tetap akan menimpa baseline
                // yang sudah ada dengan 0 — itu yang dulu menghapus riwayat penggantian
                // satu armada penuh dalam sekali import tanpa satu pun pesan error.
                if (! $isExcluded && ! $hasLastDoneKm && $lastDoneDate === null) {
                    UnitPlanning::query()->firstOrCreate([
                        'unit_id' => $unit->id,
                        'planning_item_id' => $planningItem->id,
                    ]);

                    $skippedRows++;

                    continue;
                }

                $interval = $isExcluded ? null : $intervalResolver->resolve($planningItem, $unit);
                $unitPlanning = UnitPlanning::query()->firstOrNew([
                    'unit_id' => $unit->id,
                    'planning_item_id' => $planningItem->id,
                ]);

                $attributes = [
                    'is_estimated' => $isEstimated,
                    'due_manually_set' => false,
                    'is_excluded' => $isExcluded,
                    'excluded_reason' => $exclusion['reason'] ?? null,
                    'freeze_start' => null,
                ];

                // Kolom kosong berarti "tidak ada informasi", bukan "kosongkan".
                if ($hasLastDoneKm) {
                    $attributes['last_done_km'] = $lastDoneKm;
                }

                if ($isExcluded) {
                    $attributes['last_done_date'] = null;
                    $attributes['next_due_km'] = null;
                    $attributes['next_due_date'] = null;
                } else {
                    if ($lastDoneDate !== null) {
                        $attributes['last_done_date'] = $lastDoneDate->toDateString();
                    }

                    $effectiveDate = $lastDoneDate ?? $this->existingLastDoneDate($unitPlanning);
                    $effectiveKm = $hasLastDoneKm ? $lastDoneKm : (int) $unitPlanning->last_done_km;

                    $attributes['next_due_km'] = $effectiveDate === null
                        ? null
                        : $intervalResolver->nextDueKm(
                            $effectiveKm,
                            (int) $unit->current_odo,
                            (bool) $unit->has_odometer_reading,
                            $interval['interval_km'],
                        );
                    $attributes['next_due_date'] = $effectiveDate === null
                        ? null
                        : $effectiveDate->addDays($interval['interval_days'])->toDateString();
                }

                $unitPlanning->fill($attributes)->save();

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
                'skipped_rows' => $skippedRows,
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

    private function existingLastDoneDate(UnitPlanning $unitPlanning): ?CarbonImmutable
    {
        return $unitPlanning->last_done_date === null
            ? null
            : CarbonImmutable::parse($unitPlanning->last_done_date);
    }

    private function parseInteger(string $value): int
    {
        return (int) preg_replace('/\D/', '', $value);
    }
}
