<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->boolean('is_backdated')->default(false)->after('completed_date');
            $table->unsignedInteger('backdated_days')->nullable()->after('is_backdated');
            $table->foreignId('backdate_override_by')->nullable()->after('backdated_days')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backdate_override_by');
            $table->dropColumn(['is_backdated', 'backdated_days']);
        });
    }
};
