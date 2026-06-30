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
        Schema::create('high_usage_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planning_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_planning_id')->constrained()->cascadeOnDelete();
            $table->decimal('avg_km_per_day', 8, 2);
            $table->integer('estimated_due_days');
            $table->timestamp('flagged_at');
            $table->string('action_taken')->nullable();
            $table->timestamp('action_taken_at')->nullable();
            $table->foreignId('action_taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'resolved_at']);
            $table->index(['unit_planning_id', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('high_usage_flags');
    }
};
