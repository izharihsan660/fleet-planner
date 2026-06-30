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
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_planning_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planning_item_id')->constrained()->cascadeOnDelete();
            $table->string('action')->nullable();
            $table->string('status')->default('on_hold');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->integer('new_due_km')->nullable();
            $table->date('new_due_date')->nullable();
            $table->timestamp('freeze_start')->nullable();
            $table->timestamp('freeze_end')->nullable();
            $table->integer('completed_odo')->nullable();
            $table->date('completed_date')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['unit_planning_id', 'status']);
            $table->index(['planning_item_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
    }
};
