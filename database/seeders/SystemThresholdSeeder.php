<?php

namespace Database\Seeders;

use App\Models\SystemThreshold;
use Illuminate\Database\Seeder;

class SystemThresholdSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['key' => 'warning_km', 'value' => '500', 'description' => 'KM before due date warning appears.'],
            ['key' => 'warning_days', 'value' => '7', 'description' => 'Days before due date warning appears.'],
            ['key' => 'high_usage_threshold', 'value' => '20', 'description' => 'High usage threshold percentage.'],
            ['key' => 'min_inspection_data', 'value' => '3', 'description' => 'Minimum inspection data count.'],
        ])->each(fn (array $threshold): SystemThreshold => SystemThreshold::updateOrCreate(['key' => $threshold['key']], $threshold));
    }
}
