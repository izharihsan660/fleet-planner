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
        Schema::create('manual_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planning_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_planning_id')->nullable()->constrained()->nullOnDelete();
            $table->date('target_date');
            $table->enum('reason', ['aus', 'rusak', 'temuan_mekanik', 'keluhan_driver', 'lainnya']);
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'fulfilled', 'cancelled'])->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['unit_id', 'planning_item_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_projections');
    }
};
