<?php

namespace Database\Seeders;

use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
use Illuminate\Database\Seeder;

class PlanningItemSeeder extends Seeder
{
    public function run(): void
    {
        PlanningItem::query()->where('name', 'Ban')->delete();

        collect([
            // interval_days kosong dari CSV memakai nilai sementara, perlu dikonfirmasi PT NAJ.
            // interval_km adalah estimasi awal standar bengkel, perlu konfirmasi PT NAJ.
            ['name' => 'PM Check / Reguler Services', 'interval_km' => 5000, 'interval_days' => 90],
            ['name' => 'Service A', 'interval_km' => 10000, 'interval_days' => 180],
            ['name' => 'Service B', 'interval_km' => 20000, 'interval_days' => 180],
            ['name' => 'Brake Pad', 'interval_km' => 40000, 'interval_days' => 180],
            ['name' => 'Brake Shoe', 'interval_km' => 50000, 'interval_days' => 270],
            ['name' => 'Accu', 'interval_km' => 20000, 'interval_days' => 365],
            ['name' => 'Kampas Kopling Set', 'interval_km' => 60000, 'interval_days' => 365],
            ['name' => 'Wiper Blade', 'interval_km' => 10000, 'interval_days' => 365],
            ['name' => 'Ban Depan', 'interval_km' => 40000, 'interval_days' => 270],
            ['name' => 'Ban Belakang', 'interval_km' => 40000, 'interval_days' => 270],
            ['name' => 'Ban Serep', 'interval_km' => 60000, 'interval_days' => 270],
            ['name' => 'Greasing', 'interval_km' => 5000, 'interval_days' => 92],
            ['name' => 'V-Belt', 'interval_km' => 30000, 'interval_days' => 365],
            ['name' => 'Sarung Jok', 'interval_km' => 50000, 'interval_days' => 365],
            ['name' => 'Karpet Karet', 'interval_km' => 30000, 'interval_days' => 365],
            ['name' => 'Karpet Dasar', 'interval_km' => 50000, 'interval_days' => 365],
            ['name' => 'Flushing Radiator', 'interval_km' => 40000, 'interval_days' => 180],
            ['name' => 'Flushing Steering', 'interval_km' => 40000, 'interval_days' => 180],
            ['name' => 'Flushing Injector', 'interval_km' => 30000, 'interval_days' => 180],
            ['name' => 'Flushing Rem', 'interval_km' => 40000, 'interval_days' => 180],
        ])->each(fn (array $item): PlanningItem => PlanningItem::updateOrCreate(
            ['name' => $item['name']],
            ['interval_km' => $item['interval_km'], 'interval_days' => $item['interval_days']],
        ));

        $serviceA = PlanningItem::query()->where('name', 'Service A')->firstOrFail();

        PlanningItemOverride::query()->updateOrCreate(
            ['planning_item_id' => $serviceA->id, 'vehicle_category' => 'truk_ringan'],
            ['interval_km' => 10000, 'interval_days' => 96],
        );
    }
}
