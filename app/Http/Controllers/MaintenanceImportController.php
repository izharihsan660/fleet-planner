<?php

namespace App\Http\Controllers;

use App\Enums\VehicleCategory;
use App\Http\Requests\CommitMaintenanceImportRequest;
use App\Http\Requests\PreviewMaintenanceImportRequest;
use App\Jobs\ImportUnitPlanningsJob;
use App\Models\MaintenanceImport;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Services\MaintenanceImportReader;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('MaintenanceImports/Index', [
            'imports' => MaintenanceImport::query()->latest()->take(20)->get(),
        ]);
    }

    public function preview(PreviewMaintenanceImportRequest $request, MaintenanceImportReader $reader): Response
    {
        $path = $request->file('file')->store('imports');
        $type = $request->string('type')->toString();
        $rows = $reader->rows(Storage::path($path), $type);
        $validatedRows = $this->validateRows($type, $rows, $reader);

        return Inertia::render('MaintenanceImports/Index', [
            'imports' => MaintenanceImport::query()->latest()->take(20)->get(),
            'preview' => [
                'type' => $type,
                'path' => $path,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'total_rows' => count($validatedRows),
                'valid_rows' => collect($validatedRows)->where('valid', true)->count(),
                'invalid_rows' => collect($validatedRows)->where('valid', false)->count(),
                'estimated_rows' => collect($validatedRows)->where('is_estimated', true)->count(),
                'excluded_rows' => collect($validatedRows)->where('is_excluded', true)->count(),
                'rows' => array_slice($validatedRows, 0, 25),
            ],
        ]);
    }

    public function commit(CommitMaintenanceImportRequest $request, MaintenanceImportReader $reader): RedirectResponse
    {
        $type = $request->string('type')->toString();
        $path = $request->string('path')->toString();
        $rows = $reader->rows(Storage::path($path), $type);
        $validatedRows = $this->validateRows($type, $rows, $reader);

        if (collect($validatedRows)->contains(fn (array $row): bool => ! $row['valid'])) {
            return redirect()->route('maintenance-imports.index')->withErrors(['file' => 'Masih ada baris tidak valid. Perbaiki CSV lalu upload ulang.']);
        }

        $import = MaintenanceImport::query()->create([
            'type' => $type,
            'status' => $type === 'unit_plannings' ? 'queued' : 'processing',
            'original_filename' => $request->string('original_filename')->toString(),
            'stored_path' => $path,
            'total_rows' => count($validatedRows),
            'created_by' => $request->user()->id,
        ]);

        if ($type === 'unit_plannings') {
            ImportUnitPlanningsJob::dispatch($import->id);

            return redirect()->route('maintenance-imports.index')->with('status', 'Import Unit Plannings masuk queue. Jalankan worker queue untuk memproses data besar.');
        }

        $this->commitUnits($validatedRows, $import);

        return redirect()->route('maintenance-imports.index')->with('status', 'Import Units berhasil diproses.');
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function validateRows(string $type, array $rows, MaintenanceImportReader $reader): array
    {
        return $type === 'units' ? $this->validateUnitRows($rows) : $this->validatePlanningRows($rows, $reader);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function validateUnitRows(array $rows): array
    {
        $sites = Site::query()->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name): array => [strtoupper($name) => $id]);
        $existingPlates = Unit::query()->pluck('current_plate')->map(fn (string $plate): string => strtoupper($plate))->all();
        $seen = [];
        $categories = array_column(VehicleCategory::cases(), 'value');

        return collect($rows)->map(function (array $row, int $index) use ($sites, $existingPlates, &$seen, $categories): array {
            $errors = [];
            $site = strtoupper($row['site'] ?? '');
            $plate = strtoupper($row['plat_nomor'] ?? '');
            $category = $row['kategori_kendaraan'] ?? '';

            if (! $sites->has($site)) {
                $errors[] = 'Site tidak ditemukan.';
            }

            if ($plate === '' || in_array($plate, $existingPlates, true) || in_array($plate, $seen, true)) {
                $errors[] = 'Plat kosong/duplikat.';
            }

            if (! in_array($category, $categories, true)) {
                $errors[] = 'Kategori kendaraan tidak valid.';
            }

            $seen[] = $plate;

            return ['line' => $index + 2, 'valid' => $errors === [], 'errors' => $errors, 'data' => $row, 'is_estimated' => false];
        })->all();
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function validatePlanningRows(array $rows, MaintenanceImportReader $reader): array
    {
        $units = Unit::query()->get(['id', 'current_plate', 'current_odo', 'has_odometer_reading'])->keyBy(fn (Unit $unit): string => strtoupper($unit->current_plate));
        $items = PlanningItem::query()->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name): array => [strtoupper($name) => $id]);

        return collect($rows)->map(function (array $row, int $index) use ($units, $items, $reader): array {
            $errors = [];
            $plate = strtoupper($row['plat_nomor'] ?? '');
            $item = strtoupper($row['nama_item'] ?? '');
            $lastDoneKm = $this->parseInteger($row['last_done_km'] ?? '0');
            $unit = $units->get($plate);
            $lastDoneDateValue = $row['last_done_date'] ?? '';
            $exclusion = $reader->parseExclusionMarker($lastDoneDateValue);
            $isExcluded = $exclusion !== null;

            if (! $unit) {
                $errors[] = 'Plat belum ada di master unit.';
            }

            if (! $items->has($item)) {
                $errors[] = 'Planning item tidak ditemukan.';
            }

            if (! $isExcluded && $unit && $unit->has_odometer_reading && $lastDoneKm > $unit->current_odo) {
                $errors[] = 'Last done KM melebihi odometer unit.';
            }

            if (! $isExcluded) {
                try {
                    $reader->parseLastDoneDate($lastDoneDateValue);
                } catch (InvalidFormatException) {
                    $errors[] = 'Tanggal terakhir diganti tidak valid.';
                }
            }

            return [
                'line' => $index + 2,
                'valid' => $errors === [],
                'errors' => $errors,
                'data' => $row,
                'is_estimated' => ! $isExcluded && str_contains(strtoupper($row['catatan'] ?? ''), 'TIDAK ADA RIWAYAT COMPLETE'),
                'is_excluded' => $isExcluded,
                'excluded_reason' => $exclusion['reason'] ?? null,
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function commitUnits(array $rows, MaintenanceImport $import): void
    {
        DB::transaction(function () use ($rows, $import): void {
            $sites = Site::query()->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name): array => [strtoupper($name) => $id]);

            foreach ($rows as $row) {
                $data = $row['data'] ?? [];
                $site = strtoupper((string) ($data['site'] ?? ''));
                $plate = strtoupper((string) ($data['plat_nomor'] ?? ''));
                $typeBrand = trim((string) ($data['tipe_merk'] ?? '')) ?: '-';
                $customer = trim((string) ($data['customer'] ?? '')) ?: '-';
                $category = (string) ($data['kategori_kendaraan'] ?? '');
                $year = trim((string) ($data['tahun'] ?? ''));
                $odometer = $this->parseOptionalInteger($data['odometer_saat_ini'] ?? null);

                Unit::query()->create([
                    'site_id' => $sites->get($site),
                    'customer' => $customer,
                    'current_plate' => $plate,
                    'type' => $typeBrand,
                    'brand' => str($typeBrand)->before(' ')->toString() ?: $typeBrand,
                    'vehicle_category' => $category,
                    'year' => $this->parseInteger($year !== '' ? $year : (string) now()->year),
                    'current_odo' => $odometer ?? 0,
                    'has_odometer_reading' => $odometer !== null,
                    'status' => 'active',
                ]);
            }

            $import->update(['status' => 'finished', 'success_rows' => count($rows), 'summary' => ['message' => 'Semua unit berhasil dibuat.'], 'finished_at' => now()]);
        });
    }

    private function parseInteger(string $value): int
    {
        return (int) preg_replace('/\D/', '', $value);
    }

    private function parseOptionalInteger(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return $digits === '' ? null : (int) $digits;
    }
}
