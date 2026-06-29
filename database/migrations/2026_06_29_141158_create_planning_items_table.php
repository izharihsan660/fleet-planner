<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('interval_km');
            $table->integer('interval_days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_items');
    }
};
