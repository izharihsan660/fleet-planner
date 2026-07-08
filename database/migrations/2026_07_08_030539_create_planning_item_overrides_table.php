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
        Schema::create('planning_item_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_item_id')->constrained()->cascadeOnDelete();
            $table->string('vehicle_category');
            $table->integer('interval_km')->nullable();
            $table->integer('interval_days')->nullable();
            $table->timestamps();

            $table->unique(['planning_item_id', 'vehicle_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_item_overrides');
    }
};
