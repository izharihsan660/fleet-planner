<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleUserSeeder::class,
            PlanningItemSeeder::class,
            SystemThresholdSeeder::class,
            UnitPlanningSeeder::class,
            DemoDataSeeder::class,
        ]);

        if ((bool) env('SEED_DEMO_DATA', false)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
