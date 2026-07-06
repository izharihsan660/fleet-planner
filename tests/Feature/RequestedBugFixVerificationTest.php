<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PlanningItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestedBugFixVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_unit_generates_eighteen_unit_plannings(): void
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site A', 'region' => 'A']);

        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'DD 9500 QA',
            'type' => 'MPV',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 9500,
            'status' => 'active',
        ]);

        $this->assertSame(18, $unit->unitPlannings()->count());
        $this->assertTrue(PlanningItem::query()->where('interval_km', '>', 0)->where('interval_days', '>', 0)->count() === 18);
    }

    public function test_admin_site_is_blocked_from_daily_km_input(): void
    {
        $this->seed(PlanningItemSeeder::class);

        [$siteA, $siteB] = $this->makeSites();
        $adminSite = User::factory()->create(['role' => UserRole::AdminSite, 'site_id' => $siteA->id]);
        $unitA = $this->makeUnit($siteA, 'DD 1001 AA', 1000);
        $unitB = $this->makeUnit($siteB, 'DD 1002 BB', 2000);

        $this->actingAs($adminSite)
            ->get(route('inspections.create'))
            ->assertForbidden();

        $this->actingAs($adminSite)
            ->post(route('inspections.store'), [
                'unit_id' => $unitB->id,
                'inspection_date' => today()->toDateString(),
                'odometer' => 9500,
            ])
            ->assertForbidden();
    }

    public function test_mekanik_direct_units_url_is_blocked_with_inertia_forbidden_page(): void
    {
        $site = Site::query()->create(['name' => 'Site A', 'region' => 'A']);
        $mekanik = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mekanik)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()))
            ->withHeader('Accept', 'text/html, application/xhtml+xml')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get(route('units.index'))
            ->assertForbidden()
            ->assertJsonPath('component', 'Errors/Forbidden');
    }

    public function test_mekanik_odometer_9500_is_stored_as_9500(): void
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site A', 'region' => 'A']);
        $mekanik = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $unit = $this->makeUnit($site, 'DD 9500 OD', 9000);

        $this->actingAs($mekanik)
            ->post(route('inspections.store'), [
                'unit_id' => $unit->id,
                'inspection_date' => today()->toDateString(),
                'odometer' => '9500',
            ])
            ->assertRedirect(route('inspections.index'));

        $this->assertSame(9500, InspectionLog::query()->where('unit_id', $unit->id)->firstOrFail()->odometer);
        $this->assertSame(9500, $unit->refresh()->current_odo);
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function makeSites(): array
    {
        return [
            Site::query()->create(['name' => 'Site A', 'region' => 'A']),
            Site::query()->create(['name' => 'Site B', 'region' => 'B']),
        ];
    }

    private function makeUnit(Site $site, string $plate, int $odometer): Unit
    {
        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer',
            'current_plate' => $plate,
            'type' => 'MPV',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $odometer,
            'status' => 'active',
        ]);
    }
}
