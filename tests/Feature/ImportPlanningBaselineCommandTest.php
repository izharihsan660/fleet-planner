<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ImportPlanningBaselineCommandTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_reports_updates_and_all_skip_reasons_without_writing_data(): void
    {
        $planningItem = $this->planningItem('Service A');
        $unit = $this->unit('KT 1001 AA');
        $unitPlanning = $unit->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();
        $missingPairItem = $this->planningItem('Brake Pad');
        $before = $unitPlanning->only([
            'last_done_km',
            'last_done_date',
            'next_due_km',
            'next_due_date',
            'is_estimated',
            'due_manually_set',
            'updated_at',
        ]);
        $csv = $this->writeCsv([
            ['KT 1001 AA', 'Service A', '12000', '2026-08-01', '17000', '2026-11-01', '1', '1'],
            ['KT 9999 ZZ', 'Service A', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'],
            ['KT 1001 AA', 'Item Tidak Ada', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'],
            ['KT 1001 AA', 'Brake Pad', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'],
        ]);

        $this->assertDatabaseMissing('unit_plannings', [
            'unit_id' => $unit->id,
            'planning_item_id' => $missingPairItem->id,
        ]);

        $this->artisan('planning:import-baseline', ['file' => $csv])
            ->expectsOutputToContain('MODE: DRY-RUN')
            ->expectsOutputToContain('Baris CSV: 4')
            ->expectsOutputToContain('Akan di-update: 1')
            ->expectsOutputToContain('SKIPPED: 3')
            ->expectsOutputToContain('- plat tidak ketemu: 1')
            ->expectsOutputToContain('- item tidak ketemu: 1')
            ->expectsOutputToContain('- unit_planning row tidak ada: 1')
            ->expectsOutputToContain('Contoh perubahan (maks. 10 baris):')
            ->assertSuccessful();

        $unitPlanning->refresh();

        $this->assertSame($before['last_done_km'], $unitPlanning->last_done_km);
        $this->assertEquals($before['last_done_date'], $unitPlanning->last_done_date);
        $this->assertSame($before['next_due_km'], $unitPlanning->next_due_km);
        $this->assertEquals($before['next_due_date'], $unitPlanning->next_due_date);
        $this->assertSame($before['is_estimated'], $unitPlanning->is_estimated);
        $this->assertSame($before['due_manually_set'], $unitPlanning->due_manually_set);
        $this->assertEquals($before['updated_at'], $unitPlanning->updated_at);
    }

    public function test_execute_updates_existing_row_stores_blank_next_due_km_as_null_and_logs_audit(): void
    {
        $planningItem = $this->planningItem('Service B');
        $unit = $this->unit('KT 2002 BB');
        $unitPlanning = $unit->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();
        $beforeLastDoneKm = (int) $unitPlanning->last_done_km;
        $csv = $this->writeCsv([
            ['KT 2002 BB', 'Service B', '22000', '2026-08-15', '', '2027-02-15', 'true', '1'],
            ['KT 9999 ZZ', 'Service B', '22000', '2026-08-15', '27000', '2027-02-15', 'false', '0'],
        ]);

        Log::spy();

        $this->artisan('planning:import-baseline', ['file' => $csv, '--execute' => true])
            ->expectsOutputToContain('MODE: EXECUTE')
            ->expectsOutputToContain('EXECUTE selesai.')
            ->expectsOutputToContain('Total updated: 1')
            ->expectsOutputToContain('Total skipped: 1')
            ->assertSuccessful();

        $unitPlanning->refresh();

        $this->assertSame(22000, $unitPlanning->last_done_km);
        $this->assertSame('2026-08-15', $unitPlanning->last_done_date?->toDateString());
        $this->assertNull($unitPlanning->next_due_km);
        $this->assertSame('2027-02-15', $unitPlanning->next_due_date?->toDateString());
        $this->assertTrue($unitPlanning->is_estimated);
        $this->assertTrue($unitPlanning->due_manually_set);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Planning baseline imported.', Mockery::on(
                function (array $context) use ($beforeLastDoneKm, $unitPlanning): bool {
                    return $context['csv_line'] === 2
                        && $context['unit_planning_id'] === $unitPlanning->id
                        && $context['plat'] === 'KT 2002 BB'
                        && $context['item'] === 'Service B'
                        && $context['before']['last_done_km'] === $beforeLastDoneKm
                        && $context['after']['last_done_km'] === 22000
                        && $context['after']['next_due_km'] === null
                        && $context['after']['is_estimated'] === true
                        && $context['after']['due_manually_set'] === true;
                },
            ));
    }

    public function test_execute_stores_literal_null_next_due_km_as_null(): void
    {
        $planningItem = $this->planningItem('Service NULL');
        $unit = $this->unit('KT 2502 BC');
        $unitPlanning = $unit->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();
        $csv = $this->writeCsv([
            ['KT 2502 BC', 'Service NULL', '25000', '2026-08-20', 'NULL', '2027-02-20', '0', '0'],
        ]);

        Log::spy();

        $this->artisan('planning:import-baseline', ['file' => $csv, '--execute' => true])
            ->assertSuccessful();

        $this->assertNull($unitPlanning->refresh()->next_due_km);
    }

    public function test_execute_rolls_back_every_update_and_reports_failing_csv_line(): void
    {
        $firstItem = $this->planningItem('Service C');
        $secondItem = $this->planningItem('Service D');
        $unit = $this->unit('KT 3003 CC');
        $firstPlanning = $unit->unitPlannings()->where('planning_item_id', $firstItem->id)->firstOrFail();
        $secondPlanning = $unit->unitPlannings()->where('planning_item_id', $secondItem->id)->firstOrFail();
        $firstBefore = $firstPlanning->only(self::baselineColumns());
        $secondBefore = $secondPlanning->only(self::baselineColumns());
        $csv = $this->writeCsv([
            ['KT 3003 CC', 'Service C', '31000', '2026-07-01', '36000', '2026-10-01', '0', '0'],
            ['KT 3003 CC', 'Service D', '32000', '2026-07-02', '37000', '2026-10-02', '1', '1'],
        ]);

        UnitPlanning::updating(function (UnitPlanning $unitPlanning) use ($secondPlanning): void {
            if ($unitPlanning->is($secondPlanning)) {
                throw new RuntimeException('Forced baseline import failure.');
            }
        });
        Log::spy();

        $this->artisan('planning:import-baseline', ['file' => $csv, '--execute' => true])
            ->expectsOutputToContain('Import gagal pada baris CSV 3: Forced baseline import failure.')
            ->expectsOutputToContain('Semua perubahan dibatalkan (rollback).')
            ->assertFailed();

        $firstPlanning->refresh();
        $secondPlanning->refresh();

        foreach (self::baselineColumns() as $column) {
            $this->assertEquals($firstBefore[$column], $firstPlanning->{$column});
            $this->assertEquals($secondBefore[$column], $secondPlanning->{$column});
        }

        Log::shouldNotHaveReceived('info');
    }

    public function test_command_rejects_non_exact_csv_header_before_writing_data(): void
    {
        $planningItem = $this->planningItem('Service E');
        $unit = $this->unit('KT 4004 DD');
        $unitPlanning = $unit->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();
        $beforeLastDoneKm = $unitPlanning->last_done_km;
        $csv = $this->writeCsv(
            [['KT 4004 DD', 'Service E', '41000', '2026-09-01', '46000', '2026-12-01', '0', '0']],
            ['plate', 'item', 'last_done_km', 'last_done_date', 'next_due_km', 'next_due_date', 'is_estimated', 'due_manually_set'],
        );

        $this->artisan('planning:import-baseline', ['file' => $csv, '--execute' => true])
            ->expectsOutputToContain('Header CSV tidak sesuai.')
            ->assertFailed();

        $this->assertSame($beforeLastDoneKm, $unitPlanning->refresh()->last_done_km);
    }

    private function planningItem(string $name): PlanningItem
    {
        return PlanningItem::query()->create([
            'name' => $name,
            'interval_km' => 5000,
            'interval_days' => 90,
        ]);
    }

    private function unit(string $plate): Unit
    {
        $site = Site::query()->firstOrCreate(
            ['name' => 'Site Baseline Import'],
            ['region' => 'Kalimantan'],
        );

        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 10000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $headers
     */
    private function writeCsv(array $rows, array $headers = [
        'plat',
        'item',
        'last_done_km',
        'last_done_date',
        'next_due_km',
        'next_due_date',
        'is_estimated',
        'due_manually_set',
    ]): string
    {
        $path = tempnam(sys_get_temp_dir(), 'planning-baseline-');

        if ($path === false) {
            throw new RuntimeException('Unable to create temporary CSV file.');
        }

        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary CSV file.');
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function baselineColumns(): array
    {
        return [
            'last_done_km',
            'last_done_date',
            'next_due_km',
            'next_due_date',
            'is_estimated',
            'due_manually_set',
        ];
    }
}
