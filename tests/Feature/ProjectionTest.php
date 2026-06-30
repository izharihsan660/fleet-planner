<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\ProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_projection_service_groups_due_items_and_warnings(): void
    {
        [$unit, $planningItem] = $this->createProjectionScenario();

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame(1, $result['period_months']);
        $this->assertCount(1, $result['by_unit']);
        $this->assertSame($unit->current_plate, $result['by_unit'][0]['plate_number']);
        $this->assertSame($planningItem->name, $result['by_item'][0]['planning_item_name']);
        $this->assertSame($planningItem->name, $result['by_part'][0]['planning_item_name']);
        $this->assertSame(1, $result['by_part'][0]['total_estimated_quantity']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_authorized_roles_can_access_projection_page(): void
    {
        [$unit] = $this->createProjectionScenario();

        foreach ([UserRole::Superadmin, UserRole::PlannerHo, UserRole::AdminSite, UserRole::SpvOps, UserRole::Logistik] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => $role === UserRole::AdminSite ? $unit->site_id : null,
            ]);

            $this->actingAs($user)->get(route('projections.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Projections/Index')
                    ->has('projection.by_unit')
                    ->where('filters.months', 1)
                );
        }
    }

    public function test_mechanic_cannot_access_projection_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Mekanik]);

        $this->actingAs($user)->get(route('projections.index'))->assertForbidden();
    }

    public function test_admin_site_is_forced_to_own_site_filter(): void
    {
        [$ownUnit] = $this->createProjectionScenario('Own Site', 'KT 8404 YR');
        [$otherUnit] = $this->createProjectionScenario('Other Site', 'KT 8620 YR');
        $admin = User::factory()->create(['role' => UserRole::AdminSite, 'site_id' => $ownUnit->site_id]);

        $this->actingAs($admin)->get(route('projections.index', ['site_id' => $otherUnit->site_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site_id', $ownUnit->site_id)
                ->where('projection.by_unit.0.unit_id', $ownUnit->id)
            );
    }

    /**
     * @return array{0: Unit, 1: PlanningItem}
     */
    private function createProjectionScenario(string $siteName = 'Site Test', string $plateNumber = 'KT 8404 YR'): array
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '4']);

        $site = Site::query()->create(['name' => $siteName, 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => $plateNumber,
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1800,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Ban 265/70 R17 '.$plateNumber,
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(20)->toDateString(),
            'next_due_km' => 4200,
            'next_due_date' => now()->addDays(20)->toDateString(),
        ]);

        collect([
            [now()->subDays(2), 1000],
            [now()->subDay(), 1400],
            [now(), 1800],
        ])->each(fn (array $log): InspectionLog => InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => $log[0]->toDateString(),
            'odometer' => $log[1],
        ]));

        return [$unit, $planningItem];
    }
}
