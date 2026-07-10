<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Unit;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('SEED_DEMO_DATA');
        unset($_ENV['SEED_DEMO_DATA'], $_SERVER['SEED_DEMO_DATA']);

        parent::tearDown();
    }

    public function test_database_seeder_does_not_seed_demo_data_by_default(): void
    {
        putenv('SEED_DEMO_DATA=false');
        $_ENV['SEED_DEMO_DATA'] = 'false';
        $_SERVER['SEED_DEMO_DATA'] = 'false';

        $site = Site::query()->create(['name' => 'REAL MINE SITE', 'region' => 'Papua']);
        $unit = $this->makeUnit($site, 'REAL-001');

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'REAL MINE SITE']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'current_plate' => 'REAL-001']);
        $this->assertDatabaseMissing('sites', ['name' => 'ADARO']);
    }

    public function test_demo_data_seeder_preserves_existing_non_demo_sites_and_units(): void
    {
        $site = Site::query()->create(['name' => 'REAL MINE SITE', 'region' => 'Papua']);
        $unit = $this->makeUnit($site, 'REAL-001');

        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'REAL MINE SITE']);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'current_plate' => 'REAL-001']);
        $this->assertDatabaseHas('sites', ['name' => 'ADARO']);
    }

    private function makeUnit(Site $site, string $plate): Unit
    {
        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Real Customer',
            'current_plate' => $plate,
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]);
    }
}
