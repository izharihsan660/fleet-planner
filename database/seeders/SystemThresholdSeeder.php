<?php

namespace Database\Seeders;

use App\Models\SystemThreshold;
use Illuminate\Database\Seeder;

class SystemThresholdSeeder extends Seeder
{
    public function run(): void
    {
        $warningKm = 500;
        $warningDays = 7;

        collect([
            ['key' => 'warning_km', 'value' => (string) $warningKm, 'description' => 'KM before due date warning appears.'],
            ['key' => 'warning_days', 'value' => (string) $warningDays, 'description' => 'Days before due date warning appears.'],
            ['key' => 'ancang_ancang_km', 'value' => (string) ($warningKm * 2), 'description' => 'KM before due for Ancang-ancang preview.'],
            ['key' => 'ancang_ancang_days', 'value' => (string) ($warningDays * 2), 'description' => 'Days before due for Ancang-ancang preview.'],
            ['key' => 'upcoming_km', 'value' => (string) ($warningKm * 4), 'description' => 'KM before due for Upcoming preview.'],
            ['key' => 'upcoming_days', 'value' => (string) ($warningDays * 4), 'description' => 'Days before due for Upcoming preview.'],
            ['key' => 'high_usage_threshold', 'value' => '20', 'description' => 'High usage threshold percentage.'],
            ['key' => 'min_inspection_data', 'value' => '3', 'description' => 'Minimum inspection data count.'],
            ['key' => 'rolling_window_days', 'value' => '30', 'description' => 'Rolling window days for maintenance projections average KM calculation.'],
        ])->each(fn (array $threshold): SystemThreshold => SystemThreshold::updateOrCreate(['key' => $threshold['key']], $threshold));
    }
}
