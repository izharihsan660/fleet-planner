<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
use App\Models\Region;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionMasterDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_seeds_only_complete_production_master_data(): void
    {
        $realUser = User::factory()->create([
            'name' => 'Production User',
            'email' => 'production.user@example.com',
        ]);

        $this->artisan('production:seed-master-data')
            ->expectsOutputToContain('Master data production berhasil disinkronkan tanpa data demo.')
            ->assertSuccessful();

        $this->assertSame(['Kalimantan', 'Sulawesi'], Region::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame([
            'ADARO',
            'BPN',
            'GORONTALO',
            'HO',
            'JAKARTA',
            'KENDARI',
            'LOA KULU',
            'LOAJANAN',
            'LOREH',
            'MAKASSAR',
            'MANADO',
            'MKS',
            'MUARA LAWA',
            'SANGA SANGA',
            'SANGATTA',
            'SEPARI',
            'SMD',
            'SOROAKO',
            'TABANG',
            'TARAKAN',
            'TENGGARONG',
            'TJ. REDEB',
        ], Site::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame([
            'Accu',
            'Ban Belakang',
            'Ban Depan',
            'Ban Serep',
            'Brake Pad',
            'Brake Shoe',
            'Flushing Injector',
            'Flushing Radiator',
            'Flushing Rem',
            'Flushing Steering',
            'Greasing',
            'Kampas Kopling Set',
            'Karpet Dasar',
            'Karpet Karet',
            'PM Check / Reguler Services',
            'Sarung Jok',
            'Service A',
            'Service B',
            'V-Belt',
            'Wiper Blade',
        ], PlanningItem::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(12, SystemThreshold::query()->count());
        $this->assertSame(0, PlanningItemOverride::query()->count());

        $kalimantan = Region::query()->where('name', 'Kalimantan')->firstOrFail();
        $sulawesi = Region::query()->where('name', 'Sulawesi')->firstOrFail();

        $this->assertSame(16, Site::query()->whereBelongsTo($kalimantan, 'area')->count());
        $this->assertSame(6, Site::query()->whereBelongsTo($sulawesi, 'area')->count());
        $this->assertSame(
            ['HO', 'JAKARTA', 'MUARA LAWA', 'SEPARI', 'TARAKAN', 'TENGGARONG'],
            Site::query()
                ->whereIn('name', ['HO', 'JAKARTA', 'MUARA LAWA', 'SEPARI', 'TARAKAN', 'TENGGARONG'])
                ->whereBelongsTo($kalimantan, 'area')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );
        $this->assertSame(
            ['GORONTALO', 'KENDARI', 'MAKASSAR', 'MANADO', 'MKS', 'SOROAKO'],
            Site::query()->whereBelongsTo($sulawesi, 'area')->orderBy('name')->pluck('name')->all(),
        );

        $this->assertSame([
            'ancang_ancang_days' => '14',
            'ancang_ancang_km' => '1000',
            'backdate_max_days' => '90',
            'backdate_self_service_days' => '30',
            'high_usage_threshold' => '20',
            'km_input_summary_interval_days' => '7',
            'min_inspection_data' => '3',
            'rolling_window_days' => '30',
            'upcoming_days' => '28',
            'upcoming_km' => '2000',
            'warning_days' => '7',
            'warning_km' => '500',
        ], SystemThreshold::query()->orderBy('key')->pluck('value', 'key')->all());

        $this->assertSame(0, Unit::query()->count());
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(0, WorkOrderItem::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertTrue($realUser->is(User::query()->firstOrFail()));
        $this->assertDatabaseMissing('users', ['email' => 'superadmin@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'spv_ho@example.com']);
    }

    public function test_command_is_idempotent_when_run_repeatedly(): void
    {
        $this->artisan('production:seed-master-data')->assertSuccessful();

        $regionIds = Region::query()->orderBy('name')->pluck('id', 'name')->all();
        $siteIds = Site::query()->orderBy('name')->pluck('id', 'name')->all();
        $planningItemIds = PlanningItem::query()->orderBy('name')->pluck('id', 'name')->all();
        $thresholdIds = SystemThreshold::query()->orderBy('key')->pluck('id', 'key')->all();

        $this->artisan('production:seed-master-data')->assertSuccessful();

        $this->assertSame(2, Region::query()->count());
        $this->assertSame(22, Site::query()->count());
        $this->assertSame(20, PlanningItem::query()->count());
        $this->assertSame(12, SystemThreshold::query()->count());
        $this->assertSame($regionIds, Region::query()->orderBy('name')->pluck('id', 'name')->all());
        $this->assertSame($siteIds, Site::query()->orderBy('name')->pluck('id', 'name')->all());
        $this->assertSame($planningItemIds, PlanningItem::query()->orderBy('name')->pluck('id', 'name')->all());
        $this->assertSame($thresholdIds, SystemThreshold::query()->orderBy('key')->pluck('id', 'key')->all());
    }
}
