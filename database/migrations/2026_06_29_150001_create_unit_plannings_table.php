<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_plannings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planning_item_id')->constrained()->cascadeOnDelete();
            $table->integer('last_done_km')->default(0);
            $table->date('last_done_date')->nullable();
            $table->integer('next_due_km')->nullable();
            $table->date('next_due_date')->nullable();
            $table->timestamps();

            $table->unique(['unit_id', 'planning_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_plannings');
    }
};
