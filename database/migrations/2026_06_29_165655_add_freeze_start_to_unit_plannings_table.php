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
        Schema::table('unit_plannings', function (Blueprint $table) {
            $table->timestamp('freeze_start')->nullable()->after('next_due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit_plannings', function (Blueprint $table) {
            $table->dropColumn('freeze_start');
        });
    }
};
