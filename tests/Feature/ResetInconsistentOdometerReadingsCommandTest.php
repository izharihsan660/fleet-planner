<?php

namespace Tests\Feature;

use App\Models\InspectionLog;
use App\Models\Site;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetInconsistentOdometerReadingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_inconsistent_units_without_updating_them(): void
    {
        $unit = $this->createUnit('KT 8493 ZI', currentOdo: 200000, hasReading: true);

        $this->artisan('units:reset-inconsistent-odometer-readings')
            ->expectsOutputToContain('MODE: DRY-RUN')
            ->expectsOutputToContain('Unit match: 1')
            ->expectsOutputToContain('KT 8493 ZI')
            ->expectsOutputToContain('DRY-RUN saja. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $unit->refresh();

        $this->assertSame(200000, $unit->current_odo);
        $this->assertTrue($unit->has_odometer_reading);
    }

    public function test_execute_resets_only_inconsistent_units(): void
    {
        $inconsistentUnit = $this->createUnit('KT 8493 ZI', currentOdo: 200000, hasReading: true);
        $unitWithLog = $this->createUnit('KT 1111 AA', currentOdo: 5000, hasReading: true);
        $unitWithoutReading = $this->createUnit('KT 2222 BB', currentOdo: 0, hasReading: false);
        $mechanic = User::factory()->create(['site_id' => $unitWithLog->site_id]);

        InspectionLog::query()->create([
            'unit_id' => $unitWithLog->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 5000,
        ]);

        $this->artisan('units:reset-inconsistent-odometer-readings', ['--execute' => true])
            ->expectsOutputToContain('MODE: EXECUTE')
            ->expectsOutputToContain('Unit match: 1')
            ->expectsOutputToContain('EXECUTE selesai.')
            ->assertSuccessful();

        $inconsistentUnit->refresh();
        $unitWithLog->refresh();
        $unitWithoutReading->refresh();

        $this->assertSame(0, $inconsistentUnit->current_odo);
        $this->assertFalse($inconsistentUnit->has_odometer_reading);
        $this->assertSame(5000, $unitWithLog->current_odo);
        $this->assertTrue($unitWithLog->has_odometer_reading);
        $this->assertSame(0, $unitWithoutReading->current_odo);
        $this->assertFalse($unitWithoutReading->has_odometer_reading);
    }

    private function createUnit(string $plateNumber, int $currentOdo, bool $hasReading): Unit
    {
        $site = Site::query()->firstOrCreate(
            ['name' => 'TARAKAN'],
            ['region' => 'Kalimantan Utara'],
        );

        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => $plateNumber,
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'vehicle_category' => 'pickup_suv',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'has_odometer_reading' => $hasReading,
            'status' => 'active',
        ]);
    }
}
