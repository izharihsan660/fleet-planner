<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\HighUsageFlag;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\MaintenanceTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitPlanningExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_excluded_planning_is_hidden_from_operational_and_analytical_surfaces(): void
    {
        [$admin, $unit, $planning] = $this->scenario(isExcluded: true);

        $this->assertSame([], app(MaintenanceTriggerService::class)->checkAndTrigger($unit->refresh()));
        $this->assertDatabaseCount('work_order_items', 0);

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
        ]);
        HighUsageFlag::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planning->planning_item_id,
            'unit_planning_id' => $planning->id,
            'avg_km_per_day' => 100,
            'estimated_due_days' => 3,
            'flagged_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 0)
                ->has('boardColumns.in_progress.data', 0)
                ->has('boardColumns.upcoming.data', 0)
                ->has('boardColumns.preparation.data', 0));

        $this->actingAs($admin)->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items', 0));

        $this->actingAs($admin)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_items', 0)
                ->missing('summary.total_wo')
                ->has('byItem.data', 0)
                ->has('byUnit.data', 0));

        $this->actingAs($admin)->get(route('projections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('projection.by_unit.data', 0)
                ->has('projection.by_item.data', 0)
                ->has('projection.by_part.data', 0)
                ->has('calendar.items', 0));

        $this->actingAs($admin)->get(route('high-usage.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('flags.data', 0));
    }

    public function test_superadmin_can_exclude_and_include_planning_from_unit_history(): void
    {
        [$admin, $unit, $planning] = $this->scenario();

        $this->actingAs($admin)
            ->patch(route('units.plannings.exclusion.update', [$unit, $planning]), [
                'is_excluded' => true,
                'excluded_reason' => 'METIC',
            ])
            ->assertRedirect();

        $planning->refresh();

        $this->assertTrue($planning->is_excluded);
        $this->assertSame('METIC', $planning->excluded_reason);
        $this->assertNull($planning->next_due_km);
        $this->assertNull($planning->next_due_date);

        $this->actingAs($admin)->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.can_manage_planning_exclusions', true)
                ->where('history.planning_items.0.is_excluded', true)
                ->where('history.planning_items.0.excluded_reason', 'METIC'));

        $this->actingAs($admin)
            ->patch(route('units.plannings.exclusion.update', [$unit, $planning]), [
                'is_excluded' => false,
                'excluded_reason' => null,
            ])
            ->assertRedirect();

        $planning->refresh();

        $this->assertFalse($planning->is_excluded);
        $this->assertNull($planning->excluded_reason);
        $this->assertSame(6000, $planning->next_due_km);
        $this->assertSame(today()->addDays(30)->toDateString(), $planning->next_due_date?->toDateString());
    }

    public function test_normal_planning_still_generates_maintenance_task(): void
    {
        [, $unit, $planning] = $this->scenario();

        $createdItems = app(MaintenanceTriggerService::class)->checkAndTrigger($unit->refresh());

        $this->assertCount(1, $createdItems);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
        ]);
    }

    /**
     * @return array{0: User, 1: Unit, 2: UnitPlanning}
     */
    private function scenario(bool $isExcluded = false): array
    {
        $site = Site::query()->create(['name' => 'Site Exclusion', 'region' => 'Region Test']);
        $admin = User::factory()->create(['role' => UserRole::Superadmin]);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'KT 1234 EX',
            'type' => 'Automatic',
            'brand' => 'Toyota',
            'vehicle_category' => 'mpv',
            'year' => 2024,
            'current_odo' => 5000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]));
        $planningItem = PlanningItem::query()->create([
            'name' => 'Kampas Kopling Set',
            'interval_km' => 5000,
            'interval_days' => 30,
        ]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => today()->toDateString(),
            'next_due_km' => $isExcluded ? null : 5000,
            'next_due_date' => $isExcluded ? null : today()->toDateString(),
            'is_excluded' => $isExcluded,
            'excluded_reason' => $isExcluded ? 'METIC' : null,
        ]);

        return [$admin, $unit, $planning];
    }
}
