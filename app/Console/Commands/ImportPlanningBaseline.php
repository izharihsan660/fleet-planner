<?php

namespace App\Console\Commands;

use App\Models\PlanningItem;
use App\Models\Unit;
use App\Models\UnitPlanning;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SplFileObject;
use Throwable;

class ImportPlanningBaseline extends Command
{
    protected $signature = 'planning:import-baseline
                            {file : Path file CSV baseline}
                            {--execute : Apply updates to unit_plannings}';

    protected $description = 'Preview or import baseline planning data from CSV into existing unit_plannings rows.';

    /**
     * @var list<string>
     */
    private const CSV_HEADERS = [
        'plat',
        'item',
        'last_done_km',
        'last_done_date',
        'next_due_km',
        'next_due_date',
        'is_estimated',
        'due_manually_set',
    ];

    public function handle(): int
    {
        $startedAt = microtime(true);

        try {
            $path = $this->resolvePath((string) $this->argument('file'));
            $rows = $this->readCsv($path);
            $analysis = $this->analyzeRows($rows);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        $this->displayReport($analysis, $execute);

        if (! $execute) {
            $this->info('DRY-RUN selesai. Tidak ada data yang diubah. Jalankan ulang dengan --execute untuk menyimpan perubahan.');

            return self::SUCCESS;
        }

        $currentLine = null;
        $auditEntries = [];

        try {
            DB::transaction(function () use ($analysis, &$currentLine, &$auditEntries): void {
                foreach ($analysis['updates'] as $update) {
                    $currentLine = $update['line'];
                    $unitPlanning = UnitPlanning::query()
                        ->whereKey($update['unit_planning_id'])
                        ->lockForUpdate()
                        ->first();

                    if (! $unitPlanning) {
                        throw new RuntimeException('Unit planning target tidak lagi tersedia.');
                    }

                    $before = $this->snapshot($unitPlanning);

                    if (! $unitPlanning->update($update['after'])) {
                        throw new RuntimeException('Update ditolak oleh model event.');
                    }

                    $auditEntries[] = [
                        'csv_line' => $update['line'],
                        'unit_planning_id' => $unitPlanning->id,
                        'plat' => $update['plat'],
                        'item' => $update['item'],
                        'before' => $before,
                        'after' => $this->snapshot($unitPlanning->refresh()),
                    ];
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            $line = $currentLine === null ? 'tidak diketahui' : (string) $currentLine;
            $this->error("Import gagal pada baris CSV {$line}: {$exception->getMessage()}");
            $this->warn('Semua perubahan dibatalkan (rollback).');

            return self::FAILURE;
        }

        try {
            foreach ($auditEntries as $entry) {
                Log::info('Planning baseline imported.', [
                    'csv_file' => $path,
                    ...$entry,
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Data sudah tersimpan, tetapi penulisan audit log gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->displayExecutionSummary($analysis, microtime(true) - $startedAt);

        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        $file = trim($file);

        if ($file === '') {
            throw new RuntimeException('Path CSV tidak boleh kosong.');
        }

        $path = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : base_path($file);

        if (! is_file($path)) {
            throw new RuntimeException("File CSV tidak ditemukan: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("File CSV tidak dapat dibaca: {$path}");
        }

        return $path;
    }

    /**
     * @return list<array{
     *     line: int,
     *     plat: string,
     *     item: string,
     *     last_done_km: string,
     *     last_done_date: string,
     *     next_due_km: string,
     *     next_due_date: string,
     *     is_estimated: string,
     *     due_manually_set: string
     * }>
     */
    private function readCsv(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV);

        $headerFound = false;
        $rows = [];

        foreach ($file as $lineIndex => $columns) {
            $line = $lineIndex + 1;

            if ($columns === false || $columns === [null]) {
                continue;
            }

            $values = array_map(
                fn (mixed $value): string => (string) $value,
                $columns,
            );

            if (! $headerFound) {
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];

                if ($values !== self::CSV_HEADERS) {
                    throw new RuntimeException(
                        'Header CSV tidak sesuai. Header wajib: '.implode(',', self::CSV_HEADERS),
                    );
                }

                $headerFound = true;

                continue;
            }

            $values = array_map(trim(...), $values);

            if ($this->isBlankRow($values)) {
                continue;
            }

            if (count($values) !== count(self::CSV_HEADERS)) {
                throw new RuntimeException(
                    "Baris CSV {$line}: jumlah kolom harus ".count(self::CSV_HEADERS).'.',
                );
            }

            $row = array_combine(self::CSV_HEADERS, $values);

            if ($row === false) {
                throw new RuntimeException("Baris CSV {$line}: data tidak dapat dipetakan ke header.");
            }

            $rows[] = [
                'line' => $line,
                'plat' => $row['plat'],
                'item' => $row['item'],
                'last_done_km' => $row['last_done_km'],
                'last_done_date' => $row['last_done_date'],
                'next_due_km' => $row['next_due_km'],
                'next_due_date' => $row['next_due_date'],
                'is_estimated' => $row['is_estimated'],
                'due_manually_set' => $row['due_manually_set'],
            ];
        }

        if (! $headerFound) {
            throw new RuntimeException('File CSV kosong atau tidak memiliki header.');
        }

        return $rows;
    }

    /**
     * @param  list<array{
     *     line: int,
     *     plat: string,
     *     item: string,
     *     last_done_km: string,
     *     last_done_date: string,
     *     next_due_km: string,
     *     next_due_date: string,
     *     is_estimated: string,
     *     due_manually_set: string
     * }>  $rows
     * @return array{
     *     total_rows: int,
     *     updates: list<array{
     *         line: int,
     *         unit_planning_id: int,
     *         plat: string,
     *         item: string,
     *         before: array<string, int|string|bool|null>,
     *         after: array<string, int|string|bool|null>
     *     }>,
     *     skipped: list<array{line: int, plat: string, item: string, reason: string}>,
     *     skipped_counts: array<string, int>
     * }
     */
    private function analyzeRows(array $rows): array
    {
        $plates = collect($rows)->pluck('plat')->unique()->values()->all();
        $itemNames = collect($rows)->pluck('item')->unique()->values()->all();

        $units = Unit::query()
            ->whereIn('current_plate', $plates)
            ->get(['id', 'current_plate'])
            ->keyBy(fn (Unit $unit): string => $unit->current_plate);
        $planningItems = PlanningItem::query()
            ->whereIn('name', $itemNames)
            ->get(['id', 'name'])
            ->keyBy(fn (PlanningItem $planningItem): string => $planningItem->name);

        $unitPlannings = UnitPlanning::query()
            ->whereIn('unit_id', $units->pluck('id'))
            ->whereIn('planning_item_id', $planningItems->pluck('id'))
            ->get()
            ->keyBy(
                fn (UnitPlanning $unitPlanning): string => $unitPlanning->unit_id.':'.$unitPlanning->planning_item_id,
            );

        $updates = [];
        $skipped = [];
        $skippedCounts = [
            'plat tidak ketemu' => 0,
            'item tidak ketemu' => 0,
            'unit_planning row tidak ada' => 0,
        ];

        foreach ($rows as $row) {
            $unit = $units->get($row['plat']);

            if (! $unit) {
                $this->addSkippedRow($skipped, $skippedCounts, $row, 'plat tidak ketemu');

                continue;
            }

            $planningItem = $planningItems->get($row['item']);

            if (! $planningItem) {
                $this->addSkippedRow($skipped, $skippedCounts, $row, 'item tidak ketemu');

                continue;
            }

            $unitPlanning = $unitPlannings->get($unit->id.':'.$planningItem->id);

            if (! $unitPlanning) {
                $this->addSkippedRow($skipped, $skippedCounts, $row, 'unit_planning row tidak ada');

                continue;
            }

            $after = [
                'last_done_km' => $this->parseRequiredInteger($row['last_done_km'], 'last_done_km', $row['line']),
                'last_done_date' => $this->parseNullableDate($row['last_done_date'], 'last_done_date', $row['line']),
                'next_due_km' => $this->parseNullableInteger($row['next_due_km'], 'next_due_km', $row['line']),
                'next_due_date' => $this->parseNullableDate($row['next_due_date'], 'next_due_date', $row['line']),
                'is_estimated' => $this->parseBoolean($row['is_estimated'], 'is_estimated', $row['line']),
                'due_manually_set' => $this->parseBoolean($row['due_manually_set'], 'due_manually_set', $row['line']),
            ];

            $updates[] = [
                'line' => $row['line'],
                'unit_planning_id' => $unitPlanning->id,
                'plat' => $row['plat'],
                'item' => $row['item'],
                'before' => $this->snapshot($unitPlanning),
                'after' => $after,
            ];
        }

        return [
            'total_rows' => count($rows),
            'updates' => $updates,
            'skipped' => $skipped,
            'skipped_counts' => $skippedCounts,
        ];
    }

    /**
     * @param  list<array{line: int, plat: string, item: string, reason: string}>  $skipped
     * @param  array<string, int>  $skippedCounts
     * @param  array{line: int, plat: string, item: string}  $row
     */
    private function addSkippedRow(array &$skipped, array &$skippedCounts, array $row, string $reason): void
    {
        $skipped[] = [
            'line' => $row['line'],
            'plat' => $row['plat'],
            'item' => $row['item'],
            'reason' => $reason,
        ];
        $skippedCounts[$reason]++;
    }

    /**
     * @param  array{
     *     total_rows: int,
     *     updates: list<array{
     *         line: int,
     *         unit_planning_id: int,
     *         plat: string,
     *         item: string,
     *         before: array<string, int|string|bool|null>,
     *         after: array<string, int|string|bool|null>
     *     }>,
     *     skipped: list<array{line: int, plat: string, item: string, reason: string}>,
     *     skipped_counts: array<string, int>
     * }  $analysis
     */
    private function displayReport(array $analysis, bool $execute): void
    {
        $this->info('MODE: '.($execute ? 'EXECUTE' : 'DRY-RUN'));
        $this->line("Baris CSV: {$analysis['total_rows']}");
        $this->line('Akan di-update: '.count($analysis['updates']));
        $this->line('SKIPPED: '.count($analysis['skipped']));
        $this->displaySkippedCounts($analysis['skipped_counts']);

        if ($analysis['updates'] !== []) {
            $this->newLine();
            $this->line('Contoh perubahan (maks. 10 baris):');
            $this->table(
                [
                    'Line',
                    'Plat',
                    'Item',
                    'last_done_km',
                    'last_done_date',
                    'next_due_km',
                    'next_due_date',
                    'is_estimated',
                    'due_manually_set',
                ],
                collect($analysis['updates'])
                    ->take(10)
                    ->map(fn (array $update): array => [
                        $update['line'],
                        $update['plat'],
                        $update['item'],
                        $this->formatChange($update['before']['last_done_km'], $update['after']['last_done_km']),
                        $this->formatChange($update['before']['last_done_date'], $update['after']['last_done_date']),
                        $this->formatChange($update['before']['next_due_km'], $update['after']['next_due_km']),
                        $this->formatChange($update['before']['next_due_date'], $update['after']['next_due_date']),
                        $this->formatChange($update['before']['is_estimated'], $update['after']['is_estimated']),
                        $this->formatChange($update['before']['due_manually_set'], $update['after']['due_manually_set']),
                    ])
                    ->all(),
            );
        }

        if ($analysis['skipped'] !== []) {
            $this->newLine();
            $this->line('Contoh SKIPPED (maks. 10 baris):');
            $this->table(
                ['Line', 'Plat', 'Item', 'Alasan'],
                collect($analysis['skipped'])
                    ->take(10)
                    ->map(fn (array $row): array => [
                        $row['line'],
                        $row['plat'],
                        $row['item'],
                        $row['reason'],
                    ])
                    ->all(),
            );
        }

        $this->newLine();
    }

    /**
     * @param  array{
     *     updates: list<array<string, mixed>>,
     *     skipped: list<array<string, mixed>>,
     *     skipped_counts: array<string, int>
     * }  $analysis
     */
    private function displayExecutionSummary(array $analysis, float $duration): void
    {
        $this->info('EXECUTE selesai.');
        $this->line('Total updated: '.count($analysis['updates']));
        $this->line('Total skipped: '.count($analysis['skipped']));
        $this->displaySkippedCounts($analysis['skipped_counts']);
        $this->line('Waktu eksekusi: '.number_format($duration, 3).' detik');
    }

    /**
     * @param  array<string, int>  $skippedCounts
     */
    private function displaySkippedCounts(array $skippedCounts): void
    {
        foreach ($skippedCounts as $reason => $count) {
            $this->line("- {$reason}: {$count}");
        }
    }

    /**
     * @return array<string, int|string|bool|null>
     */
    private function snapshot(UnitPlanning $unitPlanning): array
    {
        return [
            'last_done_km' => $unitPlanning->last_done_km === null ? null : (int) $unitPlanning->last_done_km,
            'last_done_date' => $unitPlanning->last_done_date?->toDateString(),
            'next_due_km' => $unitPlanning->next_due_km === null ? null : (int) $unitPlanning->next_due_km,
            'next_due_date' => $unitPlanning->next_due_date?->toDateString(),
            'is_estimated' => (bool) $unitPlanning->is_estimated,
            'due_manually_set' => (bool) $unitPlanning->due_manually_set,
        ];
    }

    private function formatChange(int|string|bool|null $before, int|string|bool|null $after): string
    {
        return $this->formatValue($before).' -> '.$this->formatValue($after);
    }

    private function formatValue(int|string|bool|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $values
     */
    private function isBlankRow(array $values): bool
    {
        return count(array_filter($values, fn (string $value): bool => $value !== '')) === 0;
    }

    private function parseRequiredInteger(string $value, string $column, int $line): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($parsed === false) {
            throw new RuntimeException("Baris CSV {$line}: {$column} harus integer non-negatif.");
        }

        return $parsed;
    }

    private function parseNullableInteger(string $value, string $column, int $line): ?int
    {
        if ($this->isNullValue($value)) {
            return null;
        }

        return $this->parseRequiredInteger($value, $column, $line);
    }

    private function parseNullableDate(string $value, string $column, int $line): ?string
    {
        if ($this->isNullValue($value)) {
            return null;
        }

        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();
        } catch (Throwable) {
            $date = false;
            $errors = false;
        }

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new RuntimeException("Baris CSV {$line}: {$column} harus berformat YYYY-MM-DD atau NULL.");
        }

        return $date->format('Y-m-d');
    }

    private function parseBoolean(string $value, string $column, int $line): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'y', 'ya' => true,
            '0', 'false', 'no', 'n', 'tidak' => false,
            default => throw new RuntimeException(
                "Baris CSV {$line}: {$column} harus boolean (1/0, true/false, yes/no).",
            ),
        };
    }

    private function isNullValue(string $value): bool
    {
        return $value === '' || strcasecmp($value, 'NULL') === 0;
    }
}
