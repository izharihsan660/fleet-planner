<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Waktu pengajuan item disimpan sendiri, tidak lagi menumpang timestamp bawaan.
 *
 * Antrian Approval sebelumnya menampilkan created_at sebagai "Tanggal Submit"
 * dan menghitung "Lama Menunggu" dari updated_at. Keduanya proksi yang bocor:
 * created_at adalah tanggal sistem membuat baris item (untuk item hasil
 * interval bisa berminggu-minggu sebelum planner mengajukan), sedangkan
 * updated_at ikut bergeser tiap kali item diubah setelah submit. Kolom
 * submitted_at mencatat waktu pengajuan yang sebenarnya, berpasangan dengan
 * submitted_by yang sudah ada.
 *
 * Data lama di-backfill dari updated_at — hanya perkiraan, tapi lebih dekat ke
 * waktu submit daripada created_at dan satu-satunya jejak yang tersisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
        });

        DB::table('work_order_items')
            ->whereNotNull('submitted_by')
            ->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->dropColumn('submitted_at');
        });
    }
};
