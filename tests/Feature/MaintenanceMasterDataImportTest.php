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
        $this->seed(PlanningItemSeeder::class);
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

        $unit = Unit::query()->where('current_plate', 'DD 1111 AA')->firstOrFail();

        $this->assertSame('pickup_suv', $unit->vehicle_category);
        $this->assertSame(12345, $unit->current_odo);
        $this->assertTrue($unit->has_odometer_reading);
        $this->assertSame(20, $unit->unitPlannings()->count());
        $this->assertSame(20, $unit->unitPlannings()->whereNotNull('next_due_km')->count());
    }

    public function test_import_units_without_odometer_column_creates_units_without_readings(): void
    {
        Storage::fake('local');
        $this->seed(PlanningItemSeeder::class);
        Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $csv = UploadedFile::fake()->createWithContent(
            'units-without-odometer.csv',
            "site,plat_nomor,kategori_kendaraan\nBPN,DD 1112 AB,pickup_suv\n",
        );

        $this->actingAs($user)
            ->post(route('maintenance-imports.preview'), ['type' => 'units', 'file' => $csv])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preview.valid_rows', 1)
                ->where('preview.invalid_rows', 0));

        $path = collect(Storage::disk('local')->files('imports'))->first();

        $this->actingAs($user)
            ->post(route('maintenance-imports.commit'), [
                'type' => 'units',
                'path' => $path,
                'original_filename' => 'units-without-odometer.csv',
            ])
            ->assertRedirect(route('maintenance-imports.index'));

        $unit = Unit::query()->where('current_plate', 'DD 1112 AB')->firstOrFail();

        $this->assertSame(0, $unit->current_odo);
        $this->assertFalse($unit->has_odometer_reading);
        $this->assertSame('-', $unit->type);
        $this->assertSame('-', $unit->customer);
        $this->assertSame(now()->year, $unit->year);
        $this->assertSame(20, $unit->unitPlannings()->count());
        $this->assertSame(0, $unit->unitPlannings()->whereNotNull('next_due_km')->count());
    }

    public function test_unit_planning_km_validation_only_uses_confirmed_odometer_readings(): void
    {
        Storage::fake('local');
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 5001 AA', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 0, 'has_odometer_reading' => false, 'status' => 'active']);
        Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 5002 BB', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 50000, 'has_odometer_reading' => true, 'status' => 'active']);
        Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 5003 CC', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 80000, 'has_odometer_reading' => true, 'status' => 'active']);

        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $csv = UploadedFile::fake()->createWithContent(
            'planning-odometer-validation.csv',
            "plat_nomor,nama_item,last_done_km\nDD 5001 AA,Service A,70078\nDD 5002 BB,Service A,70078\nDD 5003 CC,Service A,70078\n",
        );

        $this->actingAs($user)
            ->post(route('maintenance-imports.preview'), ['type' => 'unit_plannings', 'file' => $csv])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('preview.total_rows', 3)
                ->where('preview.valid_rows', 2)
                ->where('preview.invalid_rows', 1)
                ->where('preview.rows.0.valid', true)
                ->where('preview.rows.0.errors', [])
                ->where('preview.rows.1.valid', false)
                ->where('preview.rows.1.errors.0', 'Last done KM melebihi odometer unit.')
                ->where('preview.rows.2.valid', true)
                ->where('preview.rows.2.errors', []));
    }

    public function test_unit_planning_job_only_compares_km_with_confirmed_odometer_readings(): void
    {
        Storage::fake('local');
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        $unknownOdometerUnit = Unit::withoutEvents(fn () => Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 6001 AA', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 0, 'has_odometer_reading' => false, 'status' => 'active']));
        $exceededOdometerUnit = Unit::withoutEvents(fn () => Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 6002 BB', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 50000, 'has_odometer_reading' => true, 'status' => 'active']));
        $validOdometerUnit = Unit::withoutEvents(fn () => Unit::query()->create(['site_id' => $site->id, 'customer' => 'PT NAJ', 'current_plate' => 'DD 6003 CC', 'type' => 'Truck', 'brand' => 'Hino', 'vehicle_category' => 'truk_ringan', 'year' => 2024, 'current_odo' => 80000, 'has_odometer_reading' => true, 'status' => 'active']));
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $path = 'imports/planning-job-odometer-validation.csv';

        Storage::disk('local')->put(
            $path,
            "plat_nomor,nama_item,last_done_km\nDD 6001 AA,Service A,70078\nDD 6002 BB,Service A,70078\nDD 6003 CC,Service A,70078\n",
        );

        $import = MaintenanceImport::query()->create([
            'type' => 'unit_plannings',
            'status' => 'queued',
            'original_filename' => 'planning-job-odometer-validation.csv',
            'stored_path' => $path,
            'total_rows' => 3,
            'created_by' => $user->id,
        ]);

        (new ImportUnitPlanningsJob($import->id))->handle(app(MaintenanceImportReader::class), app(PlanningIntervalResolver::class));

        $import->refresh();
        $serviceA = PlanningItem::query()->where('name', 'Service A')->firstOrFail();

        $this->assertSame('finished', $import->status);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame(1, $import->failed_rows);
        $this->assertSame('DD 6002 BB', $import->summary['failures'][0]['plate']);
        $this->assertDatabaseHas('unit_plannings', ['unit_id' => $unknownOdometerUnit->id, 'planning_item_id' => $serviceA->id, 'last_done_km' => 70078]);
        $this->assertDatabaseMissing('unit_plannings', ['unit_id' => $exceededOdometerUnit->id, 'planning_item_id' => $serviceA->id]);
        $this->assertDatabaseHas('unit_plannings', ['unit_id' => $validOdometerUnit->id, 'planning_item_id' => $serviceA->id, 'last_done_km' => 70078]);
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
            'has_odometer_reading' => true,
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
        $this->assertSame('2026-01-01', $planning->last_done_date?->toDateString());
        $this->assertSame(60000, $planning->next_due_km);
        $this->assertTrue($planning->is_estimated);
    }

    public function test_official_prefilled_templates_preview_all_setup_awal_item_rows(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $templates = [
            ['Template_Data_Fleet_Planner_Kalimantan_PREFILLED.xlsx', 2660, 'B 2444 UIL'],
            ['Template_Data_Fleet_Planner_Sulawesi_PREFILLED.xlsx', 800, 'KT 8037 YV'],
        ];

        foreach ($templates as [$filename, $expectedRows, $firstPlate]) {
            $response = $this->actingAs($user)
                ->post(route('maintenance-imports.preview'), [
                    'type' => 'unit_plannings',
                    'file' => $this->makeOfficialTemplateUpload($filename),
                ])
                ->assertOk();

            $response->assertInertia(fn ($page) => $page
                ->where('preview.total_rows', $expectedRows)
                ->where('preview.rows.0.data.plat_nomor', $firstPlate)
                ->where('preview.rows.0.data.nama_item', 'PM Check / Reguler Services')
                ->where('preview.rows.0.data.last_done_date', '')
                ->where('preview.rows.0.data.last_done_km', ''));
        }
    }

    public function test_import_unit_planning_marks_tidak_perlu_date_as_excluded(): void
    {
        Storage::fake('local');
        Queue::fake();

        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur']);
        Unit::withoutEvents(fn () => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT NAJ',
            'current_plate' => 'DD 4444 XX',
            'type' => 'Automatic',
            'brand' => 'Toyota',
            'vehicle_category' => 'mpv',
            'year' => 2024,
            'current_odo' => 60000,
            'status' => 'active',
        ]));
        PlanningItem::query()->create(['name' => 'Kampas Kopling Set', 'interval_km' => 40000, 'interval_days' => 365]);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $file = UploadedFile::fake()->createWithContent(
            'setup-awal-item.csv',
            "plat_nomor,nama_item,last_done_km,Kapan Terakhir Diganti,catatan\nDD 4444 XX,Kampas Kopling Set,,  TIDAK   PERLU (METIC)  ,TIDAK ADA RIWAYAT COMPLETE\n",
        );

        $preview = $this->actingAs($user)
            ->post(route('maintenance-imports.preview'), ['type' => 'unit_plannings', 'file' => $file])
            ->assertOk();

        $preview->assertInertia(fn ($page) => $page
            ->where('preview.invalid_rows', 0)
            ->where('preview.estimated_rows', 0)
            ->where('preview.excluded_rows', 1)
            ->where('preview.rows.0.is_excluded', true)
            ->where('preview.rows.0.excluded_reason', 'METIC'));

        $path = collect(Storage::disk('local')->files('imports'))->first();

        $this->actingAs($user)
            ->post(route('maintenance-imports.commit'), [
                'type' => 'unit_plannings',
                'path' => $path,
                'original_filename' => 'setup-awal-item.csv',
            ])
            ->assertRedirect(route('maintenance-imports.index'));

        Queue::assertPushed(ImportUnitPlanningsJob::class, function (ImportUnitPlanningsJob $job): bool {
            $job->handle(app(MaintenanceImportReader::class), app(PlanningIntervalResolver::class));

            return true;
        });

        $planning = UnitPlanning::query()->firstOrFail();

        $this->assertTrue($planning->is_excluded);
        $this->assertSame('METIC', $planning->excluded_reason);
        $this->assertFalse($planning->is_estimated);
        $this->assertNull($planning->next_due_km);
        $this->assertNull($planning->next_due_date);
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
            ['Site', 'Plat Nomor', 'Tipe/Merk Unit', 'Kategori Kendaraan', 'Tahun', 'Customer', 'Odometer Saat Ini', 'helper_formula'],
            ['BPN', '=CONCAT("DD ","3333"," XX")', 'HINO 300', 'truk_ringan', 2024, 'PT NAJ', 45678, '=CONCAT(B2," - helper")'],
        ]);

        $setupAwalItem = $spreadsheet->createSheet();
        $setupAwalItem->setTitle('SETUP AWAL ITEM');
        $setupAwalItem->fromArray([
            ['Plat Nomor (otomatis)', 'Nama Item (otomatis)', 'Kapan Terakhir Diganti (Tanggal)', 'KM Saat Diganti (opsional)', 'catatan', 'helper_formula'],
            ['=\'Data Unit\'!B2', 'Service A', '2026-01-01', 50000, 'TIDAK ADA RIWAYAT COMPLETE - formula dari template', '=CONCAT(A2,"|",B2)'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'fleet-template-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function makeOfficialTemplateUpload(string $filename): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'official-fleet-template-').'.xlsx';
        copy(base_path("data-migration/{$filename}"), $path);

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
