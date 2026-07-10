<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('system_thresholds')->updateOrInsert(
            ['key' => 'rolling_window_days'],
            [
                'value' => '30',
                'description' => 'Rolling window days for maintenance projections average KM calculation.',
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_thresholds')->where('key', 'rolling_window_days')->delete();
    }
};
