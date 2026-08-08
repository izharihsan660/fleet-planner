<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->integer('baseline_last_done_km')->nullable()->after('notes');
            $table->date('baseline_last_done_date')->nullable()->after('baseline_last_done_km');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropColumn(['baseline_last_done_km', 'baseline_last_done_date']);
        });
    }
};
