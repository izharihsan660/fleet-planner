<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use Database\Seeders\PlanningItemSeeder;
use Database\Seeders\SystemThresholdSeeder;
use Database\Seeders\UnitPlanningSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InspectionInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_planning_seeder_creates_records_for_each_unit_and_planning_item(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        Unit::query()->create($this->unitPayload($site->id));

        $this->seed([PlanningItemSeeder::class, UnitPlanningSeeder::class]);

        $this->assertSame(18, PlanningItem::query()->count());
        $this->assertSame(18, UnitPlanning::query()->count());
    }

    public function test_mechanic_can_record_daily_odometer_and_updates_unit_average(): void
    {
        $this->seed([SystemThresholdSeeder::class, PlanningItemSeeder::class]);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $this->seed(UnitPlanningSeeder::class);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->subDays(2)->toDateString(),
            'odometer' => 150,
        ])->assertRedirect(route('inspections.index'));

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 250,
        ])->assertRedirect(route('inspections.index'));

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 350,
        ])->assertRedirect(route('inspections.index'));

        $unit->refresh();

        $this->assertSame(350, $unit->current_odo);
        $this->assertSame(100, $unit->avg_km_per_day);
        $this->assertSame(3, InspectionLog::query()->where('unit_id', $unit->id)->count());
        $this->assertSame(18, $unit->unitPlannings()->count());
    }

    public function test_same_day_input_keeps_only_largest_odometer(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 200,
        ])->assertRedirect(route('inspections.index'));

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 250,
        ])->assertRedirect(route('inspections.index'));

        $this->assertSame(1, InspectionLog::query()->where('unit_id', $unit->id)->count());
        $this->assertSame(250, InspectionLog::query()->where('unit_id', $unit->id)->value('odometer'));
        $this->assertSame(250, $unit->refresh()->current_odo);
    }

    public function test_mechanic_cannot_record_unit_from_other_site(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $otherSite = Site::query()->create(['name' => 'Site Other', 'region' => 'Region Other']);
        $unit = Unit::query()->create($this->unitPayload($otherSite->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 200,
        ])->assertForbidden();
    }

    public function test_inspection_index_returns_resource_collection_props(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 100,
        ]);

        $this->actingAs($mechanic)
            ->get(route('inspections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Index')
                ->has('inspectionLogs.data', 1)
                ->has('units.data', 1)
            );
    }

    public function test_inspection_create_returns_units_resource_collection_prop(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)
            ->get(route('inspections.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Create')
                ->has('units.data', 1)
                ->has('today')
                ->has('minimumInspectionData')
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId, int $currentOdo = 0): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'Customer A',
            'current_plate' => 'KT '.fake()->unique()->numberBetween(1000, 9999).' AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ];
    }
}
