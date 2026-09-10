<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai jadwal yang ditetapkan orang, bukan dihitung dari interval.
 *
 * Postpone dan High Usage Window 2 menghasilkan due yang sengaja berbeda dari
 * last_done + interval, dan keduanya sudah melewati approval SPV. Tanpa penanda
 * ini, setiap perhitungan ulang interval — baik dari Master Data maupun dari
 * input KM harian — menyetel balik angkanya seolah approval itu tidak pernah
 * ada. Penanda direset begitu siklusnya dimulai lagi dari data terukur:
 * pekerjaan selesai, baseline diisi, atau inspeksi breakdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_plannings', function (Blueprint $table) {
            $table->boolean('due_manually_set')->default(false)->after('is_estimated');
        });
    }

    public function down(): void
    {
        Schema::table('unit_plannings', function (Blueprint $table) {
            $table->dropColumn('due_manually_set');
        });
    }
};
