<?php

namespace Database\Seeders;

use App\Models\PlanningItem;
use Illuminate\Database\Seeder;

class PlanningItemSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'PM Check / Reguler Services',
            'Service A',
            'Service B',
            'Brake Pad',
            'Brake Shoe',
            'Accu',
            'Kampas Kopling Set',
            'Wiper Blade',
            'Ban',
            'Greasing',
            'V-Belt',
            'Sarung Jok',
            'Karpet Karet',
            'Karpet Dasar',
            'Flushing Radiator',
            'Flushing Steering',
            'Flushing Injector',
            'Flushing Rem',
        ])->each(fn (string $name): PlanningItem => PlanningItem::updateOrCreate(['name' => $name], ['interval_km' => 0, 'interval_days' => 0]));
    }
}
