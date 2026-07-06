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
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('assigned_mechanic_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->date('scheduled_date')->nullable()->after('assigned_mechanic_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_mechanic_id');
            $table->dropColumn('scheduled_date');
        });
    }
};
