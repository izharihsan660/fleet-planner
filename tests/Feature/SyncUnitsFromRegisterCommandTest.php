<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncUnitsFromRegisterCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_unit_is_updated_without_touching_odometer_plannings_or_work_orders(): void
    {
        $csv = $this->writeRegisterCsv([
            ['MUARA LAWA', 'KT 1234 AA', 'TOYOTA HILUX DC', 'pickup_suv', '2022', 'PT. UT', 'KT 0001 OLD'],
        ]);
        $region = Region::query()->create(['name' => 'Kalimantan']);
        $oldSite = Site::query()->create(['name' => 'M. LAWA', 'region' => 'Kalimantan Timur', 'region_id' => $region->id]);
        $unit = Unit::query()->create([
            'site_id' => $oldSite->id,
            'customer' => 'Customer Lama',
            'current_plate' => 'KT 1234 AA',
            'type' => 'Tipe Lama',
            'brand' => 'Brand Lama',
            'vehicle_category' => 'truk_ringan',
            'year' => 2020,
            'current_odo' => 98765,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Service A', 'interval_km' => 10000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 90000,
            'next_due_km' => 100000,
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $oldSite->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $item = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $planning->id, 'planning_item_id' => $planningItem->id, 'status' => 'on_hold']);

        $this->artisan('units:sync-from-register', ['--execute' => true, '--path' => $csv])->assertSuccessful();

        $newSite = Site::query()->where('name', 'MUARA LAWA')->firstOrFail();
        $unit->refresh();

        $this->assertSame($newSite->id, $unit->site_id);
        $this->assertSame('PT. UT', $unit->customer);
        $this->assertSame('TOYOTA HILUX DC', $unit->type);
        $this->assertSame('TOYOTA', $unit->brand);
        $this->assertSame('pickup_suv', $unit->vehicle_category);
        $this->assertSame(2022, $unit->year);
        $this->assertSame(98765, $unit->current_odo);
        $this->assertTrue($unit->has_odometer_reading);
        $this->assertModelExists($planning);
        $this->assertModelExists($workOrder);
        $this->assertModelExists($item);
        $this->assertDatabaseHas('unit_plate_histories', ['unit_id' => $unit->id, 'plate_number' => 'KT 0001 OLD']);
    }

    public function test_new_unit_is_created_with_zero_odometer_and_no_reading(): void
    {
        $csv = $this->writeRegisterCsv([
            ['TARAKAN', 'KT 5678 BB', 'MITSUBISHI CANTER', 'truk_ringan', '2023', 'PT. NAJR', ''],
        ]);
        Region::query()->create(['name' => 'Kalimantan']);

        $this->artisan('units:sync-from-register', ['--execute' => true, '--path' => $csv])->assertSuccessful();

        $site = Site::query()->where('name', 'TARAKAN')->firstOrFail();

        $this->assertDatabaseHas('units', [
            'site_id' => $site->id,
            'current_plate' => 'KT 5678 BB',
            'customer' => 'PT. NAJR',
            'type' => 'MITSUBISHI CANTER',
            'brand' => 'MITSUBISHI',
            'vehicle_category' => 'truk_ringan',
            'year' => 2023,
            'current_odo' => 0,
            'has_odometer_reading' => false,
        ]);
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $csv = $this->writeRegisterCsv([
            ['SEPARI', 'KT 9999 CC', 'TOYOTA HILUX SC', 'pickup_suv', '2024', 'PT. UT', ''],
        ]);
        Region::query()->create(['name' => 'Kalimantan']);

        $this->artisan('units:sync-from-register', ['--path' => $csv])
            ->expectsOutputToContain('MODE: DRY-RUN')
            ->expectsOutputToContain('Unit akan CREATE: 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sites', ['name' => 'SEPARI']);
        $this->assertDatabaseMissing('units', ['current_plate' => 'KT 9999 CC']);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>  $rows
     */
    private function writeRegisterCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'register-units-');
        $handle = fopen($path, 'w');

        fputcsv($handle, ['site', 'plat_nomor', 'tipe_merk', 'kategori_kendaraan', 'tahun', 'customer', 'plat_lama']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
