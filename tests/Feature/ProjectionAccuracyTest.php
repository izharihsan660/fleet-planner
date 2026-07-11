<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\InspectionService;
use App\Services\ProjectionService;
use Carbon\CarbonImmutable;
use Database\Seeders\SystemThresholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Golden scenario yang mengunci rumus proyeksi FSD §8.1 dengan angka
 * yang bisa dihitung tangan: laju konstan tepat 100 km/hari selama 10 hari.
 *
 *   avg_km_per_day        = (1100 - 100) / 10          = 100
 *   est_odo_akhir_periode = 1100 + (100 × sisa_hari)
 *   item masuk proyeksi   = next_due_km <= est_odo  ATAU  next_due_date <= akhir periode
 *   est_due_date (by KM)  = hari ini + ceil((next_due_km - current_odo) / avg)
 */
class ProjectionAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_matches_hand_calculated_values_for_constant_usage(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Golden', 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        // Unit dibuat sebelum planning item ada, jadi observer tidak membuat planning lain.
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'QA Golden',
            'current_plate' => 'QA 1000 GD',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 0,
            'status' => 'active',
        ]);

        // Item A due di 3.100 KM -> selalu masuk proyeksi 1 bulan (est odo minimal 3.900 di bulan terpendek).
        // Item B due di 6.600 KM -> selalu di luar 1 bulan (est odo maksimal 4.200) dan masuk 2 bulan (est odo minimal 7.000).
        $itemA = PlanningItem::query()->create(['name' => 'Golden A', 'interval_km' => 2000, 'interval_days' => 365]);
        $itemB = PlanningItem::query()->create(['name' => 'Golden B', 'interval_km' => 5500, 'interval_days' => 365]);

        foreach ([[$itemA, 2000], [$itemB, 5500]] as [$item, $interval]) {
            UnitPlanning::query()->updateOrCreate(
                ['unit_id' => $unit->id, 'planning_item_id' => $item->id],
                [
                    'last_done_km' => 1100,
                    'last_done_date' => Carbon::today()->subDays(10)->toDateString(),
                    'next_due_km' => 1100 + $interval,
                    'next_due_date' => null,
                ],
            );
        }

        // 11 input KM harian lewat pipeline asli: hari -10 s/d hari ini, odo 100 -> 1100 (tepat 100 km/hari).
        $service = app(InspectionService::class);

        for ($i = 10; $i >= 0; $i--) {
            $service->record($unit->refresh(), 100 + (10 - $i) * 100, $mechanic, Carbon::today()->subDays($i));
        }

        $this->assertSame(1100, $unit->refresh()->current_odo);

        $projection = app(ProjectionService::class);
        $today = CarbonImmutable::today();

        // ---- Proyeksi 1 bulan ----
        $oneMonth = $projection->calculate(1);
        $remainingDaysOneMonth = (int) $today->diffInDays($today->addMonthsNoOverflow(1));

        $byUnit = collect($oneMonth['by_unit'])->firstWhere('plate_number', 'QA 1000 GD');

        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $this->assertSame(100.0, $byUnit['avg_km_per_day']);
        $this->assertSame(1100 + (100 * $remainingDaysOneMonth), $byUnit['estimated_period_odo']);

        $itemNames = collect($byUnit['items'])->pluck('planning_item_name');
        $this->assertTrue($itemNames->contains('Golden A'));
        $this->assertFalse($itemNames->contains('Golden B'), 'Item due 6.600 KM tidak boleh masuk proyeksi 1 bulan.');

        $goldenA = collect($byUnit['items'])->firstWhere('planning_item_name', 'Golden A');
        $this->assertSame(3100, $goldenA['estimated_due_km']);
        // Sisa 2.000 KM pada 100 km/hari = 20 hari dari hari ini.
        $this->assertSame($today->addDays(20)->toDateString(), $goldenA['estimated_due_date']);

        // ---- Proyeksi 2 bulan ----
        $twoMonths = $projection->calculate(2);
        $remainingDaysTwoMonths = (int) $today->diffInDays($today->addMonthsNoOverflow(2));

        $byUnitTwoMonths = collect($twoMonths['by_unit'])->firstWhere('plate_number', 'QA 1000 GD');

        $this->assertNotNull($byUnitTwoMonths);
        $this->assertSame(100.0, $byUnitTwoMonths['avg_km_per_day']);
        $this->assertSame(1100 + (100 * $remainingDaysTwoMonths), $byUnitTwoMonths['estimated_period_odo']);

        $goldenB = collect($byUnitTwoMonths['items'])->firstWhere('planning_item_name', 'Golden B');
        $this->assertNotNull($goldenB, 'Item due 6.600 KM harus masuk proyeksi 2 bulan.');
        $this->assertSame(6600, $goldenB['estimated_due_km']);
        // Sisa 5.500 KM pada 100 km/hari = 55 hari dari hari ini.
        $this->assertSame($today->addDays(55)->toDateString(), $goldenB['estimated_due_date']);
    }

    public function test_projection_uses_date_leg_when_km_data_is_insufficient(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Golden 2', 'region' => 'Region Test']);

        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'QA Golden',
            'current_plate' => 'QA 2000 GD',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 0,
            'status' => 'active',
        ]);

        $item = PlanningItem::query()->create(['name' => 'Golden C', 'interval_km' => 5000, 'interval_days' => 30]);

        UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $item->id],
            [
                'last_done_km' => 0,
                'last_done_date' => Carbon::today()->subDays(20)->toDateString(),
                'next_due_km' => 5000,
                'next_due_date' => Carbon::today()->addDays(10)->toDateString(),
            ],
        );

        $result = app(ProjectionService::class)->calculate(1);
        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'QA 2000 GD');

        // Tanpa data KM: unit tetap muncul lewat jalur tanggal, ditandai insufficient_data,
        // dan masuk daftar warning "menunggu input mekanik".
        $this->assertNotNull($byUnit);
        $this->assertTrue($byUnit['insufficient_data']);
        $this->assertSame(
            CarbonImmutable::today()->addDays(10)->toDateString(),
            collect($byUnit['items'])->firstWhere('planning_item_name', 'Golden C')['estimated_due_date'],
        );
        $this->assertTrue(collect($result['warnings'])->pluck('plate_number')->contains('QA 2000 GD'));
    }
}
