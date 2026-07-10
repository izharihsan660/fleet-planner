<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\ImportUnitPlanningsJob;
use App\Models\MaintenanceImport;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\MaintenanceImportReader;
use App\Services\PlanningIntervalResolver;
use Database\Seeders\PlanningItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MaintenanceMasterDataImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_planning_item_seeder_creates_twenty_items_without_single_ban(): void
    {
        $this->seed(PlanningItemSeeder::class);

        $this->assertSame(20, PlanningItem::query()->count());
        $this->assertFalse(PlanningItem::query()->where('name', 'Ban')->exists());
        $this->assertTrue(PlanningItem::query()->whereIn('name', ['Ban Depan', 'Ban Belakang', 'Ban Serep'])->count() === 3);
    }

    public function test_truk_ringan_service_a_uses_override_interval(): void
    {
        $this->seed(PlanningItemSeeder::class);
        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);

        $pickup = Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 1001 AA', 'type' => 'Pickup', 'brand' => 'Toyota', 'vehicle_category' => 'pickup_suv', 'year' => 2024, 'current_odo' => 1000, 'status' => 'active']);
        $truck = Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 1002 BB', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 1000, 'status' => 'active']);

        $serviceA = PlanningItem::query()->where('name', 'Service A')->firstOrFail();

        $this->assertSame(today()->addDays(180)->toDateString(), $pickup->unitPlannings()->whereBelongsTo($serviceA)->firstOrFail()->next_due_date->toDateString());
        $this->assertSame(today()->addDays(96)->toDateString(), $truck->unitPlannings()->whereBelongsTo($serviceA)->firstOrFail()->next_due_date->toDateString());
    }

    public function test_import_units_previews_and_commits_valid_csv(): void
    {
        Storage::fake('local');
        Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $csv = UploadedFile::fake()->createWithContent('units.csv', "site,plat_nomor,tipe_merk,kategori_kendaraan,tahun,customer,odometer_saat_ini\nBPN,DD 1111 AA,TOYOTA AVANZA,pickup_suv,2022,PT UT,12345\n");

        $this->actingAs($user)
            ->post(route('maintenance-imports.preview'), ['type' => 'units', 'file' => $csv])
            ->assertOk();

        $path = collect(Storage::disk('local')->files('imports'))->first();

        $this->actingAs($user)
            ->post(route('maintenance-imports.commit'), ['type' => 'units', 'path' => $path, 'original_filename' => 'units.csv'])
            ->assertRedirect(route('maintenance-imports.index'));

        $this->assertDatabaseHas('units', ['current_plate' => 'DD 1111 AA', 'vehicle_category' => 'pickup_suv', 'current_odo' => 12345]);
    }

    public function test_commit_rejects_import_path_traversal(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        Storage::disk('local')->put('imports/valid.csv', 'site,plat_nomor');

        $this->actingAs($user)
            ->from(route('maintenance-imports.index'))
            ->post(route('maintenance-imports.commit'), [
                'type' => 'units',
                'path' => 'imports/../private.csv',
                'original_filename' => 'private.csv',
            ])
            ->assertRedirect(route('maintenance-imports.index'))
            ->assertSessionHasErrors('path');

        $this->assertSame(0, MaintenanceImport::query()->count());
    }

    public function test_commit_rejects_paths_outside_imports_directory(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        Storage::disk('local')->put('private.csv', 'site,plat_nomor');

        $this->actingAs($user)
            ->from(route('maintenance-imports.index'))
            ->post(route('maintenance-imports.commit'), [
                'type' => 'units',
                'path' => 'private.csv',
                'original_filename' => 'private.csv',
            ])
            ->assertRedirect(route('maintenance-imports.index'))
            ->assertSessionHasErrors('path');

        $this->assertSame(0, MaintenanceImport::query()->count());
    }

    public function test_unit_planning_import_is_queued_and_job_calculates_override_due(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 2222 BB', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 50000, 'status' => 'active']);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $csv = UploadedFile::fake()->createWithContent('plannings.csv', "plat_nomor,nama_item,last_done_km,last_done_date,catatan\nDD 2222 BB,Service A,40000,2026-01-01,TIDAK ADA RIWAYAT COMPLETE - perlu dicek manual\n");

        $this->actingAs($user)->post(route('maintenance-imports.preview'), ['type' => 'unit_plannings', 'file' => $csv])->assertOk();
        $path = collect(Storage::disk('local')->files('imports'))->first();

        $this->actingAs($user)->post(route('maintenance-imports.commit'), ['type' => 'unit_plannings', 'path' => $path, 'original_filename' => 'plannings.csv'])->assertRedirect(route('maintenance-imports.index'));

        Queue::assertPushed(ImportUnitPlanningsJob::class);
        Queue::assertPushed(ImportUnitPlanningsJob::class, function (ImportUnitPlanningsJob $job): bool {
            $job->handle(app(MaintenanceImportReader::class), app(PlanningIntervalResolver::class));

            return true;
        });

        $planning = UnitPlanning::query()->whereHas('planningItem', fn ($query) => $query->where('name', 'Service A'))->firstOrFail();
        $this->assertSame(50000, $planning->next_due_km);
        $this->assertSame('2026-04-07', $planning->next_due_date->toDateString());
        $this->assertTrue($planning->is_estimated);
        $this->assertSame(1, MaintenanceImport::query()->firstOrFail()->estimated_rows);
    }

    public function test_import_units_reads_xlsx_data_unit_sheet_with_formula_values(): void
    {
        Storage::fake('local');
        Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $xlsx = $this->makeFleetTemplateUpload('Template_Data_Fleet_Planner_Kalimantan_PREFILLED.xlsx');

        $this->actingAs($user)
            ->post(route('maintenance-imports.preview'), ['type' => 'units', 'file' => $xlsx])
            ->assertOk();

        $path = collect(Storage::disk('local')->files('imports'))->first();

        $this->actingAs($user)
            ->post(route('maintenance-imports.commit'), ['type' => 'units', 'path' => $path, 'original_filename' => 'Template_Data_Fleet_Planner_Kalimantan_PREFILLED.xlsx'])
            ->assertRedirect(route('maintenance-imports.index'));

        $this->assertDatabaseHas('units', [
            'current_plate' => 'DD 3333 XX',
            'vehicle_category' => 'truk_ringan',
            'current_odo' => 45678,
        ]);
        $this->assertDatabaseMissing('units', ['current_plate' => 'PANDUAN']);
    }

    public function test_import_unit_plannings_reads_xlsx_setup_awal_item_sheet_with_formula_values(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 3333 XX', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 60000, 'status' => 'active']);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $xlsx = $this->makeFleetTemplateUpload('Template_Data_Fleet_Planner_Sulawesi_PREFILLED.xlsx');

        $this->actingAs($user)
            ->post(route('maintenance-imports.preview'), ['type' => 'unit_plannings', 'file' => $xlsx])
            ->assertOk();

        $path = collect(Storage::disk('local')->files('imports'))->first();

        $this->actingAs($user)
            ->post(route('maintenance-imports.commit'), ['type' => 'unit_plannings', 'path' => $path, 'original_filename' => 'Template_Data_Fleet_Planner_Sulawesi_PREFILLED.xlsx'])
            ->assertRedirect(route('maintenance-imports.index'));

        Queue::assertPushed(ImportUnitPlanningsJob::class, function (ImportUnitPlanningsJob $job): bool {
            $job->handle(app(MaintenanceImportReader::class), app(PlanningIntervalResolver::class));

            return true;
        });

        $planning = UnitPlanning::query()->whereHas('planningItem', fn ($query) => $query->where('name', 'Service A'))->firstOrFail();
        $this->assertSame(50000, $planning->last_done_km);
        $this->assertSame(60000, $planning->next_due_km);
        $this->assertTrue($planning->is_estimated);
    }

    private function makeFleetTemplateUpload(string $filename): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('PANDUAN');
        $spreadsheet->getActiveSheet()->fromArray([
            ['Panduan upload template fleet planner'],
            ['Sheet ini harus diabaikan importer.'],
        ]);

        $dataUnit = $spreadsheet->createSheet();
        $dataUnit->setTitle('Data Unit');
        $dataUnit->fromArray([
            ['site', 'plat_nomor', 'tipe_merk', 'kategori_kendaraan', 'tahun', 'customer', 'odometer_saat_ini', 'helper_formula'],
            ['BPN', '=CONCAT("DD ","3333"," XX")', 'HINO 300', 'truk_ringan', 2024, 'PT NAJ', 45678, '=CONCAT(B2," - helper")'],
        ]);

        $setupAwalItem = $spreadsheet->createSheet();
        $setupAwalItem->setTitle('SETUP AWAL ITEM');
        $setupAwalItem->fromArray([
            ['plat_nomor', 'nama_item', 'last_done_km', 'last_done_date', 'catatan', 'helper_formula'],
            ['=\'Data Unit\'!B2', 'Service A', 50000, '2026-01-01', 'TIDAK ADA RIWAYAT COMPLETE - formula dari template', '=CONCAT(A2,"|",B2)'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'fleet-template-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
