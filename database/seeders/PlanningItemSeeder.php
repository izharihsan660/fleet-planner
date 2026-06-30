<?php

namespace Database\Seeders;

use App\Models\PlanningItem;
use Illuminate\Database\Seeder;

class PlanningItemSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['name' => 'PM Check / Reguler Services', 'interval_km' => 5000, 'interval_days' => 90],
            ['name' => 'Service A', 'interval_km' => 10000, 'interval_days' => 180],
            ['name' => 'Service B', 'interval_km' => 20000, 'interval_days' => 365],
            ['name' => 'Brake Pad', 'interval_km' => 30000, 'interval_days' => 540],
            ['name' => 'Brake Shoe', 'interval_km' => 40000, 'interval_days' => 730],
            ['name' => 'Accu', 'interval_km' => 20000, 'interval_days' => 365],
            ['name' => 'Kampas Kopling Set', 'interval_km' => 60000, 'interval_days' => 1095],
            ['name' => 'Wiper Blade', 'interval_km' => 10000, 'interval_days' => 180],
            ['name' => 'Ban', 'interval_km' => 40000, 'interval_days' => 730],
            ['name' => 'Greasing', 'interval_km' => 5000, 'interval_days' => 90],
            ['name' => 'V-Belt', 'interval_km' => 30000, 'interval_days' => 540],
            ['name' => 'Sarung Jok', 'interval_km' => 50000, 'interval_days' => 1095],
            ['name' => 'Karpet Karet', 'interval_km' => 30000, 'interval_days' => 730],
            ['name' => 'Karpet Dasar', 'interval_km' => 50000, 'interval_days' => 1095],
            ['name' => 'Flushing Radiator', 'interval_km' => 40000, 'interval_days' => 730],
            ['name' => 'Flushing Steering', 'interval_km' => 40000, 'interval_days' => 730],
            ['name' => 'Flushing Injector', 'interval_km' => 30000, 'interval_days' => 540],
            ['name' => 'Flushing Rem', 'interval_km' => 40000, 'interval_days' => 730],
        ])->each(fn (array $item): PlanningItem => PlanningItem::updateOrCreate(
            ['name' => $item['name']],
            ['interval_km' => $item['interval_km'], 'interval_days' => $item['interval_days']],
        ));
    }
}