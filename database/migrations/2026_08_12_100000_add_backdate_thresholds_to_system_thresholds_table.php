<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $thresholds = [
        [
            'key' => 'backdate_self_service_days',
            'value' => '30',
            'description' => 'Batas hari mundur tanggal selesai yang boleh diisi sendiri dengan catatan singkat.',
        ],
        [
            'key' => 'backdate_max_days',
            'value' => '90',
            'description' => 'Batas maksimum hari mundur tanggal selesai lewat form biasa. Di atas ini hanya Superadmin lewat Koreksi Tanggal.',
        ],
    ];

    public function up(): void
    {
        foreach ($this->thresholds as $threshold) {
            DB::table('system_thresholds')->updateOrInsert(
                ['key' => $threshold['key']],
                [
                    'value' => $threshold['value'],
                    'description' => $threshold['description'],
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('system_thresholds')
            ->whereIn('key', array_column($this->thresholds, 'key'))
            ->delete();
    }
};
