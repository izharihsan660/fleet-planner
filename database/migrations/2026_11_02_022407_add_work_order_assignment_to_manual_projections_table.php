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
        Schema::table('manual_projections', function (Blueprint $table) {
            $table->foreignId('work_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_mechanic_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_projections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_item_id');
            $table->dropConstrainedForeignId('assigned_mechanic_id');
        });
    }
};
