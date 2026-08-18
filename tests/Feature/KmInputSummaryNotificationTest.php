<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InspectionLog;
use App\Models\Notification;
use App\Models\Region;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\User;
use App\Services\KmInputTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KmInputSummaryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_periodic_summary_is_scoped_to_recipients_and_respects_configured_interval(): void
    {
        $this->travelTo('2026-08-17 08:00:00');

        try {
            [$firstSite, $secondSite] = $this->createRegionScenario();
            $firstPlanner = User::factory()->create([
                'role' => UserRole::PlannerArea,
                'region_id' => $firstSite->region_id,
                'site_id' => null,
            ]);
            $secondPlanner = User::factory()->create([
                'role' => UserRole::PlannerArea,
                'region_id' => $secondSite->region_id,
                'site_id' => null,
            ]);
            $spvHo = User::factory()->create(['role' => UserRole::SpvHo]);
            $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);
            $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);
            $firstRegionUnits = [
                $this->createUnit($firstSite, 'KT 4101 AA'),
                $this->createUnit($firstSite, 'KT 4102 AA'),
            ];
            $secondRegionUnit = $this->createUnit($secondSite, 'KT 4201 BB');

            $this->createInspection($firstRegionUnits[0], $mechanic, '2026-08-17', 1400);
            $this->createInspection($firstRegionUnits[1], $mechanic, '2026-08-17', 1500);
            $this->createInspection($firstRegionUnits[0], $mechanic, '2026-08-16', 1300);
            $this->createInspection($firstRegionUnits[0], $mechanic, '2026-08-14', 1200);
            $this->createInspection($firstRegionUnits[0], $mechanic, '2026-08-10', 1100);
            $this->createInspection($firstRegionUnits[1], $mechanic, '2026-08-09', 1000);
            $this->createInspection($secondRegionUnit, $mechanic, '2026-08-17', 900);
            $this->createInspection($secondRegionUnit, $mechanic, '2026-08-15', 800);
            $this->createInspection($secondRegionUnit, $mechanic, '2026-08-10', 700);

            $this->assertSame('7', SystemThreshold::query()->where('key', KmInputTrendService::SUMMARY_INTERVAL_KEY)->value('value'));

            $this->artisan('notifications:send-km-input-summary')
                ->expectsOutput('3 notifikasi ringkasan input KM berhasil dikirim.')
                ->assertSuccessful();

            $firstPlannerNotification = $this->summaryNotification($firstPlanner);
            $secondPlannerNotification = $this->summaryNotification($secondPlanner);
            $spvNotification = $this->summaryNotification($spvHo);

            $this->assertSame(4, $firstPlannerNotification->data['current_total']);
            $this->assertSame(2, $firstPlannerNotification->data['previous_total']);
            $this->assertSame(2, $firstPlannerNotification->data['delta']);
            $this->assertSame(2, $secondPlannerNotification->data['current_total']);
            $this->assertSame(1, $secondPlannerNotification->data['previous_total']);
            $this->assertSame(6, $spvNotification->data['current_total']);
            $this->assertSame(3, $spvNotification->data['previous_total']);
            $this->assertCount(7, $firstPlannerNotification->data['daily_totals']);
            $this->assertStringContainsString('Cakupan region Region Kaltim', $firstPlannerNotification->message);
            $this->assertStringContainsString('Total harian:', $firstPlannerNotification->message);
            $this->assertDatabaseMissing('notifications', [
                'user_id' => $superadmin->id,
                'type' => KmInputTrendService::NOTIFICATION_TYPE,
            ]);

            $this->artisan('notifications:send-km-input-summary')
                ->expectsOutput('0 notifikasi ringkasan input KM berhasil dikirim.')
                ->assertSuccessful();

            SystemThreshold::query()
                ->where('key', KmInputTrendService::SUMMARY_INTERVAL_KEY)
                ->update(['value' => '3']);

            $this->travelTo('2026-08-19 08:00:00');
            $this->artisan('notifications:send-km-input-summary')
                ->expectsOutput('0 notifikasi ringkasan input KM berhasil dikirim.')
                ->assertSuccessful();

            $this->travelTo('2026-08-20 08:00:00');
            $this->artisan('notifications:send-km-input-summary')
                ->expectsOutput('3 notifikasi ringkasan input KM berhasil dikirim.')
                ->assertSuccessful();

            $this->assertSame(2, Notification::query()
                ->where('user_id', $firstPlanner->id)
                ->where('type', KmInputTrendService::NOTIFICATION_TYPE)
                ->count());
        } finally {
            $this->travelBack();
        }
    }

    /**
     * @return array{Site, Site}
     */
    private function createRegionScenario(): array
    {
        $firstRegion = Region::query()->create(['name' => 'Region Kaltim']);
        $secondRegion = Region::query()->create(['name' => 'Region Kalsel']);

        return [
            Site::query()->create(['name' => 'Site Samarinda', 'region' => 'Kaltim', 'region_id' => $firstRegion->id]),
            Site::query()->create(['name' => 'Site Banjarmasin', 'region' => 'Kalsel', 'region_id' => $secondRegion->id]),
        ];
    }

    private function createUnit(Site $site, string $plate): Unit
    {
        return Unit::withoutEvents(fn () => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]));
    }

    private function createInspection(Unit $unit, User $mechanic, string $date, int $odometer): void
    {
        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => $date,
            'odometer' => $odometer,
        ]);
    }

    private function summaryNotification(User $user): Notification
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', KmInputTrendService::NOTIFICATION_TYPE)
            ->firstOrFail();
    }
}
