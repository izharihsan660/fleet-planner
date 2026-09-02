<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal pengerjaan pindah dari WO ke item.
 *
 * Mekanik tetap di WO sebagai penanggung jawab satu unit, tapi tanggalnya per
 * item supaya satu unit bisa dijadwalkan bertahap — misalnya Ban Depan tanggal
 * 1, Service A tanggal 2, Ban Serep tanggal 3. Sebelumnya menjadwalkan item
 * kedua menimpa jadwal item pertama karena keduanya menumpang satu kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('planned_date');
            $table->index(['status', 'scheduled_date'], 'work_order_items_status_scheduled_index');
        });

        DB::table('work_order_items')->update([
            'scheduled_date' => DB::raw('date((select scheduled_date from work_orders where work_orders.id = work_order_items.work_order_id))'),
        ]);

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('assigned_mechanic_id');
        });

        DB::table('work_orders')->update([
            'scheduled_date' => DB::raw('date((select min(scheduled_date) from work_order_items where work_order_items.work_order_id = work_orders.id))'),
        ]);

        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropIndex('work_order_items_status_scheduled_index');
            $table->dropColumn('scheduled_date');
        });
    }
};
