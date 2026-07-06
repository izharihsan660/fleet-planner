<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_superadmin_can_view_report_sections(): void
    {
        $this->createReportScenario();
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->has('summary')
                ->has('woSummary')
                ->has('byItem')
                ->has('byUnit')
                ->has('overdueByArea')
                ->where('permissions.can_view_by_item', true)
            );
    }

    public function test_logistik_only_sees_item_report(): void
    {
        $this->createReportScenario();
        $user = User::factory()->create(['role' => UserRole::Logistik]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_view_wo_summary', false)
                ->where('permissions.can_view_by_item', true)
                ->where('permissions.can_view_by_unit', false)
                ->where('permissions.default_tab', 'item')
            );
    }

    public function test_report_page_access_matches_role_tabs(): void
    {
        [$site] = $this->createReportScenario();

        $expectations = [
            UserRole::Superadmin->value => [true, true, true, true],
            UserRole::PlannerHo->value => [true, true, true, true],
            UserRole::SpvOps->value => [true, true, true, true],
            UserRole::AdminSite->value => [true, true, true, true],
            UserRole::Mekanik->value => [true, true, true, true],
            UserRole::Logistik->value => [false, true, false, false],
        ];

        foreach ($expectations as $role => [$woSummary, $byItem, $byUnit, $overdue]) {
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => in_array($role, [UserRole::AdminSite->value, UserRole::Mekanik->value], true) ? $site->id : null,
            ]);

            $this->actingAs($user)->get(route('reports.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('permissions.can_view_wo_summary', $woSummary)
                    ->where('permissions.can_view_by_item', $byItem)
                    ->where('permissions.can_view_by_unit', $byUnit)
                    ->where('permissions.can_view_overdue', $overdue)
                );
        }
    }

    public function test_unit_history_contains_replacements_plate_blocked_and_postpone(): void
    {
        [$site, $unit] = $this->createReportScenario();
        $unit->plateHistories()->create([
            'plate_number' => 'KT 1001 AA',
            'active_from' => now()->subYear()->toDateString(),
        ]);
        $user = User::factory()->create(['role' => UserRole::AdminSite, 'site_id' => $site->id]);

        $this->actingAs($user)->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Units/History')
                ->where('history.unit.id', $unit->id)
                ->has('history.replacements', 1)
                ->has('history.plate_histories', 2)
                ->has('history.blocked_breakdowns', 1)
                ->has('history.postpones', 1)
            );
    }

    public function test_check_overdue_command_marks_items_and_notifies_roles(): void
    {
        $this->createReportScenario();
        User::factory()->create(['role' => UserRole::SpvOps]);
        User::factory()->create(['role' => UserRole::PlannerHo]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(1, WorkOrderItem::query()->where('status', 'overdue')->count());
        $this->assertSame(2, Notification::query()->where('type', 'maintenance_overdue')->count());
    }

    public function test_check_overdue_command_notifies_existing_overdue_items(): void
    {
        $this->createReportScenario();
        User::factory()->create(['role' => UserRole::SpvOps]);
        User::factory()->create(['role' => UserRole::PlannerHo]);

        $item = WorkOrderItem::query()->firstOrFail();
        $item->update(['status' => 'overdue']);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(2, Notification::query()
            ->where('type', 'maintenance_overdue')
            ->where('data->work_order_item_id', $item->id)
            ->count());
    }

    /**
     * @return array{0: Site, 1: Unit}
     */
    private function createReportScenario(): array
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'KT 1234 AA',
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2023,
            'current_odo' => 6000,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(120)->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => now()->subDay()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'in_progress']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'replace',
            'status' => 'complete',
            'completed_odo' => 5000,
            'completed_date' => now()->subDay()->toDateString(),
            'submitted_by' => $mechanic->id,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'on_hold',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'blocked',
            'status' => 'blocked',
            'reason' => 'Menunggu part',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'postpone',
            'status' => 'postponed',
            'reason' => 'Unit masih operasi',
        ]);

        return [$site, $unit];
    }
}
