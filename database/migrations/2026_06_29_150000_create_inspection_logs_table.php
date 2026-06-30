<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mechanic_id')->constrained('users')->cascadeOnDelete();
            $table->date('inspection_date');
            $table->integer('odometer');
            $table->timestamps();

            $table->unique(['unit_id', 'inspection_date']);
            $table->index(['unit_id', 'inspection_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_logs');
    }
};
