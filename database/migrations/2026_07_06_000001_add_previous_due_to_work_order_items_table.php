<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->integer('previous_due_km')->nullable()->after('notes');
            $table->date('previous_due_date')->nullable()->after('previous_due_km');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->dropColumn(['previous_due_km', 'previous_due_date']);
        });
    }
};
