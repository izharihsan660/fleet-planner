<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Database\Seeders\PlanningItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitPlanningGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_plannings_are_generated_when_unit_is_created(): void
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site A', 'region' => 'Makassar']);

        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'DD 1001 AA',
            'type' => 'MPV',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 9500,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);

        $this->assertSame(20, $unit->unitPlannings()->count());

        $planningItem = PlanningItem::query()->where('name', 'PM Check / Reguler Services')->firstOrFail();

        $unitPlanning = $unit->unitPlannings()
            ->where('planning_item_id', $planningItem->id)
            ->firstOrFail();

        $this->assertSame(9500, $unitPlanning->last_done_km);
        $this->assertSame(today()->toDateString(), $unitPlanning->last_done_date->toDateString());
        $this->assertSame(14500, $unitPlanning->next_due_km);
        $this->assertSame(today()->addDays(90)->toDateString(), $unitPlanning->next_due_date->toDateString());
    }

    public function test_unit_without_odometer_reading_has_only_date_due_baselines(): void
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site Missing Odo', 'region' => 'Makassar']);

        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Missing Odo',
            'current_plate' => 'DD 1002 AB',
            'type' => 'MPV',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 0,
            'has_odometer_reading' => false,
            'status' => 'active',
        ]);

        $this->assertSame(20, $unit->unitPlannings()->count());
        $this->assertSame(0, $unit->unitPlannings()->whereNotNull('next_due_km')->count());
        $this->assertSame(20, $unit->unitPlannings()->whereNotNull('next_due_date')->count());
    }

    public function test_backfill_command_creates_only_missing_unit_plannings(): void
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site B', 'region' => 'Kendari']);

        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer B',
            'current_plate' => 'DD 2002 BB',
            'type' => 'Pickup',
            'brand' => 'Mitsubishi',
            'year' => 2023,
            'current_odo' => 12000,
            'status' => 'active',
        ]);

        UnitPlanning::query()->where('unit_id', $unit->id)->delete();

        $this->artisan('maintenance:backfill-unit-plannings')
            ->expectsOutput('Created 20 unit planning rows.')
            ->assertSuccessful();

        $this->assertSame(20, $unit->unitPlannings()->count());

        $this->artisan('maintenance:backfill-unit-plannings')
            ->expectsOutput('Created 0 unit planning rows.')
            ->assertSuccessful();
    }
}
