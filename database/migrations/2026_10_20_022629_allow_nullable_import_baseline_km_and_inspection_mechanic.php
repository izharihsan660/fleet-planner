<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_logs', function (Blueprint $table): void {
            $table->dropForeign(['mechanic_id']);
        });
        Schema::table('inspection_logs', function (Blueprint $table): void {
            $table->foreignId('mechanic_id')->nullable()->change();
            $table->foreign('mechanic_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('inspection_logs')->whereNull('mechanic_id')->exists()) {
            throw new RuntimeException('Tidak dapat mengembalikan mechanic_id ke NOT NULL selama data import tanpa mekanik masih ada.');
        }

        Schema::table('inspection_logs', function (Blueprint $table): void {
            $table->dropForeign(['mechanic_id']);
        });
        Schema::table('inspection_logs', function (Blueprint $table): void {
            $table->foreignId('mechanic_id')->change();
            $table->foreign('mechanic_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
