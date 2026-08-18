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
            ['key' => 'km_input_summary_interval_days'],
            [
                'value' => '7',
                'description' => 'Interval hari pengiriman notifikasi ringkasan input KM.',
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_thresholds')
            ->where('key', 'km_input_summary_interval_days')
            ->delete();
    }
};
