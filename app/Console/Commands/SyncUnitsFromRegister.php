<?php

namespace App\Console\Commands;

use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlateHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SplFileObject;

class SyncUnitsFromRegister extends Command
{
    protected $signature = 'units:sync-from-register
                            {--dry-run : Preview changes without applying them (default)}
                            {--execute : Apply changes to sites and units}
                            {--path= : CSV path. Defaults to data-migration/UNITS_dari_Register_Kendaraan.csv}';

    protected $description = 'Dry-run or sync master Site and Unit data from Register Kendaraan CSV.';

    /**
     * @var array<string, string>
     */
    private array $siteRenames = [
        'M. LAWA' => 'MUARA LAWA',
        'TGR' => 'TENGGARONG',
    ];

    /**
     * @var list<string>
     */
    private array $newSites = ['TARAKAN', 'SEPARI', 'JAKARTA', 'HO'];

    public function handle(): int
    {
        $path = $this->csvPath();

        if (! is_file($path)) {
            $this->error("CSV tidak ditemukan: {$path}");
            $this->warn('Letakkan UNITS_dari_Register_Kendaraan.csv di data-migration/ lalu jalankan ulang dry-run.');

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        $analysis = $this->analyze($rows);
        $execute = (bool) $this->option('execute');

        $this->printReport($analysis, $execute);

        if (! $execute) {
            $this->info('DRY-RUN saja. Tidak ada data yang diubah. Jalankan dengan --execute setelah dikonfirmasi.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows): void {
            $this->syncSites();
            $this->syncUnits($rows);
        });

        $this->info('EXECUTE selesai. Sites dan Units sudah disinkronkan.');

        return self::SUCCESS;
    }

    private function csvPath(): string
    {
        $path = $this->option('path') ?: base_path('data-migration/UNITS_dari_Register_Kendaraan.csv');

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * @return list<array{site: string, plat_nomor: string, tipe_merk: string, kategori_kendaraan: string, tahun: int|null, customer: string, plat_lama: string|null}>
     */
    private function readCsv(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

        $header = null;
        $rows = [];

        foreach ($file as $line) {
            if ($line === [null] || $line === false) {
                continue;
            }

            $values = array_map(fn ($value): string => trim((string) $value), $line);

            if ($header === null) {
                $header = array_map(fn (string $value): string => str($value)->lower()->trim()->replace(' ', '_')->toString(), $values);
                $this->assertRequiredHeaders($header);

                continue;
            }

            if (count(array_filter($values, fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }

            $row = array_combine($header, array_pad($values, count($header), ''));

            $plate = $this->normalizePlate($row['plat_nomor'] ?? '');

            if ($plate === '') {
                continue;
            }

            $rows[] = [
                'site' => $this->normalizeSite($row['site'] ?? ''),
                'plat_nomor' => $plate,
                'tipe_merk' => $row['tipe_merk'] ?? '',
                'kategori_kendaraan' => $row['kategori_kendaraan'] ?? 'pickup_suv',
                'tahun' => $this->parseYear($row['tahun'] ?? ''),
                'customer' => $row['customer'] ?? '',
                'plat_lama' => ($row['plat_lama'] ?? '') !== '' ? $this->normalizePlate($row['plat_lama']) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $header
     */
    private function assertRequiredHeaders(array $header): void
    {
        $required = ['site', 'plat_nomor', 'tipe_merk', 'kategori_kendaraan', 'tahun', 'customer', 'plat_lama'];
        $missing = array_values(array_diff($required, $header));

        if ($missing !== []) {
            throw new RuntimeException('Header CSV kurang: '.implode(', ', $missing));
        }
    }

    /**
     * @param  list<array{site: string, plat_nomor: string, tipe_merk: string, kategori_kendaraan: string, tahun: int|null, customer: string, plat_lama: string|null}>  $rows
     * @return array<string, mixed>
     */
    private function analyze(array $rows): array
    {
        $registerPlates = collect($rows)->pluck('plat_nomor')->unique()->values();
        $existingUnits = Unit::query()->whereIn('current_plate', $registerPlates)->get()->keyBy('current_plate');
        $existingPlates = Unit::query()->pluck('current_plate')->map(fn (string $plate): string => $this->normalizePlate($plate));

        $updates = collect($rows)->filter(fn (array $row): bool => $existingUnits->has($row['plat_nomor']))->values();
        $creates = collect($rows)->reject(fn (array $row): bool => $existingUnits->has($row['plat_nomor']))->values();
        $missingFromRegister = $existingPlates->diff($registerPlates)->values();

        return [
            'total_rows' => count($rows),
            'updates' => $updates,
            'creates' => $creates,
            'missing_from_register' => $missingFromRegister,
            'site_creates' => collect($this->newSites)->filter(fn (string $name): bool => ! Site::query()->where('name', $name)->exists())->values(),
            'site_renames' => collect($this->siteRenames)->filter(fn (string $to, string $from): bool => Site::query()->where('name', $from)->exists())->map(fn (string $to, string $from): string => "{$from} → {$to}")->values(),
            'has_plate_history' => Schema::hasTable('unit_plate_histories'),
            'has_odometer_reading' => Schema::hasColumn('units', 'has_odometer_reading'),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function printReport(array $analysis, bool $execute): void
    {
        $this->line($execute ? 'MODE: EXECUTE' : 'MODE: DRY-RUN');
        $this->info('Total baris register: '.$analysis['total_rows']);
        $this->info('Unit akan UPDATE: '.$analysis['updates']->count());
        $this->info('Unit akan CREATE: '.$analysis['creates']->count());
        $this->info('Unit DB tidak ada di register: '.$analysis['missing_from_register']->count());

        $this->line('Site rename: '.($analysis['site_renames']->isEmpty() ? '-' : $analysis['site_renames']->implode(', ')));
        $this->line('Site baru: '.($analysis['site_creates']->isEmpty() ? '-' : $analysis['site_creates']->implode(', ')));
        $this->line('Plate history: '.($analysis['has_plate_history'] ? 'tersedia, plat_lama akan disimpan' : 'belum tersedia, plat_lama di-skip'));
        $this->line('Kolom has_odometer_reading: '.($analysis['has_odometer_reading'] ? 'tersedia, unit baru akan false' : 'belum tersedia, di-skip'));

        $this->table(['Aksi', 'Plat', 'Site', 'Customer', 'Tipe/Merk'], $analysis['updates']->map(fn (array $row): array => ['UPDATE', $row['plat_nomor'], $row['site'], $row['customer'], $row['tipe_merk']])->all());
        $this->table(['Aksi', 'Plat', 'Site', 'Customer', 'Tipe/Merk'], $analysis['creates']->map(fn (array $row): array => ['CREATE', $row['plat_nomor'], $row['site'], $row['customer'], $row['tipe_merk']])->all());
        $this->table(['Plat di DB tapi tidak ada di register'], $analysis['missing_from_register']->map(fn (string $plate): array => [$plate])->all());
    }

    private function syncSites(): void
    {
        $kalimantan = Region::query()->firstOrCreate(['name' => 'Kalimantan']);

        foreach ($this->siteRenames as $from => $to) {
            Site::query()->where('name', $from)->update([
                'name' => $to,
                'region' => 'Kalimantan',
                'region_id' => $kalimantan->id,
            ]);
        }

        foreach ($this->newSites as $site) {
            Site::query()->firstOrCreate(
                ['name' => $site],
                ['region' => 'Kalimantan', 'region_id' => $kalimantan->id],
            );
        }
    }

    /**
     * @param  list<array{site: string, plat_nomor: string, tipe_merk: string, kategori_kendaraan: string, tahun: int|null, customer: string, plat_lama: string|null}>  $rows
     */
    private function syncUnits(array $rows): void
    {
        $hasOdometerReading = Schema::hasColumn('units', 'has_odometer_reading');
        $hasPlateHistory = Schema::hasTable('unit_plate_histories');

        foreach ($rows as $row) {
            $site = Site::query()->where('name', $row['site'])->firstOrFail();
            $unit = Unit::query()->where('current_plate', $row['plat_nomor'])->first();
            $payload = [
                'site_id' => $site->id,
                'customer' => $row['customer'],
                'type' => $row['tipe_merk'],
                'brand' => $this->brandFromType($row['tipe_merk']),
                'vehicle_category' => $row['kategori_kendaraan'],
                'year' => $row['tahun'] ?? 0,
                'status' => 'active',
            ];

            if ($unit) {
                $unit->update($payload);
            } else {
                if ($hasOdometerReading) {
                    $payload['has_odometer_reading'] = false;
                }

                $unit = Unit::query()->create($payload + [
                    'current_plate' => $row['plat_nomor'],
                    'current_odo' => 0,
                ]);
            }

            if ($hasPlateHistory && $row['plat_lama']) {
                UnitPlateHistory::query()->firstOrCreate(
                    ['unit_id' => $unit->id, 'plate_number' => $row['plat_lama']],
                    ['active_from' => now()->toDateString(), 'active_until' => null],
                );
            }
        }
    }

    private function normalizeSite(string $site): string
    {
        $site = str($site)->squish()->upper()->toString();

        return $this->siteRenames[$site] ?? $site;
    }

    private function normalizePlate(string $plate): string
    {
        return str($plate)->squish()->upper()->toString();
    }

    private function parseYear(string $year): ?int
    {
        $year = trim($year);

        return $year === '' ? null : (int) $year;
    }

    private function brandFromType(string $type): string
    {
        $brand = str($type)->squish()->before(' ')->upper()->toString();

        return $brand !== '' ? $brand : '-';
    }
}
