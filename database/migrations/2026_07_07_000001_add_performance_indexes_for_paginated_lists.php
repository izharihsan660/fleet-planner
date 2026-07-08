<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_logs', function (Blueprint $table): void {
            $table->index(['inspection_date', 'id'], 'inspection_logs_inspection_date_id_index');
            $table->index(['mechanic_id', 'inspection_date'], 'inspection_logs_mechanic_date_index');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'work_orders_status_created_at_index');
            $table->index(['assigned_mechanic_id', 'status'], 'work_orders_assignee_status_index');
        });

        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->index(['work_order_id', 'status'], 'work_order_items_work_order_status_index');
            $table->index(['submitted_by', 'status'], 'work_order_items_submitted_status_index');
            $table->index(['triggered_by_high_usage', 'status'], 'work_order_items_high_usage_status_index');
        });

        Schema::table('unit_plannings', function (Blueprint $table): void {
            $table->index(['next_due_date', 'id'], 'unit_plannings_next_due_date_id_index');
            $table->index(['planning_item_id', 'next_due_date'], 'unit_plannings_item_due_date_index');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'notifications_user_created_at_index');
            $table->index(['user_id', 'read_at', 'created_at'], 'notifications_user_read_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_logs', function (Blueprint $table): void {
            $table->dropIndex('inspection_logs_inspection_date_id_index');
            $table->dropIndex('inspection_logs_mechanic_date_index');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropIndex('work_orders_status_created_at_index');
            $table->dropIndex('work_orders_assignee_status_index');
        });

        Schema::table('work_order_items', function (Blueprint $table): void {
            $table->dropIndex('work_order_items_work_order_status_index');
            $table->dropIndex('work_order_items_submitted_status_index');
            $table->dropIndex('work_order_items_high_usage_status_index');
        });

        Schema::table('unit_plannings', function (Blueprint $table): void {
            $table->dropIndex('unit_plannings_next_due_date_id_index');
            $table->dropIndex('unit_plannings_item_due_date_index');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_user_created_at_index');
            $table->dropIndex('notifications_user_read_created_index');
        });
    }
};
