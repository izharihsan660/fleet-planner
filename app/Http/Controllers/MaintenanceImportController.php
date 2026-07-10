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
        $validatedRows = $this->validateRows($type, $rows);

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
                'rows' => array_slice($validatedRows, 0, 25),
            ],
        ]);
    }

    public function commit(CommitMaintenanceImportRequest $request, MaintenanceImportReader $reader): RedirectResponse
    {
        $type = $request->string('type')->toString();
        $path = $request->string('path')->toString();
        $rows = $reader->rows(Storage::path($path), $type);
        $validatedRows = $this->validateRows($type, $rows);

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
    private function validateRows(string $type, array $rows): array
    {
        return $type === 'units' ? $this->validateUnitRows($rows) : $this->validatePlanningRows($rows);
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
    private function validatePlanningRows(array $rows): array
    {
        $units = Unit::query()->get(['id', 'current_plate', 'current_odo'])->keyBy(fn (Unit $unit): string => strtoupper($unit->current_plate));
        $items = PlanningItem::query()->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name): array => [strtoupper($name) => $id]);

        return collect($rows)->map(function (array $row, int $index) use ($units, $items): array {
            $errors = [];
            $plate = strtoupper($row['plat_nomor'] ?? '');
            $item = strtoupper($row['nama_item'] ?? '');
            $lastDoneKm = $this->parseInteger($row['last_done_km'] ?? '0');
            $unit = $units->get($plate);

            if (! $unit) {
                $errors[] = 'Plat belum ada di master unit.';
            }

            if (! $items->has($item)) {
                $errors[] = 'Planning item tidak ditemukan.';
            }

            if ($unit && $lastDoneKm > $unit->current_odo) {
                $errors[] = 'Last done KM melebihi odometer unit.';
            }

            return [
                'line' => $index + 2,
                'valid' => $errors === [],
                'errors' => $errors,
                'data' => $row,
                'is_estimated' => str_contains(strtoupper($row['catatan'] ?? ''), 'TIDAK ADA RIWAYAT COMPLETE'),
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
                $data = $row['data'];
                $typeBrand = trim((string) $data['tipe_merk']);

                Unit::query()->create([
                    'site_id' => $sites[strtoupper($data['site'])],
                    'customer' => $data['customer'] ?: '-',
                    'current_plate' => strtoupper($data['plat_nomor']),
                    'type' => $typeBrand,
                    'brand' => str($typeBrand)->before(' ')->toString() ?: $typeBrand,
                    'vehicle_category' => $data['kategori_kendaraan'],
                    'year' => $this->parseInteger($data['tahun'] ?: (string) now()->year),
                    'current_odo' => $this->parseInteger($data['odometer_saat_ini']),
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
}
