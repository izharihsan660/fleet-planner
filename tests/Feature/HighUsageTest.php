<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\HighUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HighUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_detection_creates_high_usage_flag_when_average_usage_moves_due_date_forward(): void
    {
        [$unit, $unitPlanning] = $this->createHighUsageScenario();

        $flags = app(HighUsageService::class)->detect($unit->refresh());

        $this->assertCount(1, $flags);
        $this->assertDatabaseHas('high_usage_flags', [
            'unit_id' => $unit->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $unitPlanning->planning_item_id,
            'estimated_due_days' => 6,
            'resolved_at' => null,
        ]);
    }

    public function test_completed_history_does_not_permanently_block_future_high_usage_detection(): void
    {
        [$unit, $unitPlanning] = $this->createHighUsageScenario();
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'trigger_type' => 'normal', 'status' => 'complete']);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $unitPlanning->planning_item_id,
            'status' => 'complete',
            'completed_odo' => 1200,
            'completed_date' => now()->subDays(10)->toDateString(),
        ]);

        $flags = app(HighUsageService::class)->detect($unit->refresh());

        $this->assertCount(1, $flags);
        $this->assertDatabaseHas('high_usage_flags', [
            'unit_id' => $unit->id,
            'unit_planning_id' => $unitPlanning->id,
            'resolved_at' => null,
        ]);
    }

    public function test_planner_area_can_trigger_high_usage_work_order_item(): void
    {
        [$unit, $unitPlanning] = $this->createHighUsageScenario();
        $flag = app(HighUsageService::class)->detect($unit->refresh())[0];
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $unit->site_id]);

        $this->actingAs($admin)->post(route('high-usage.action', $flag), [
            'action' => 'triggered',
        ])->assertRedirect();

        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $unitPlanning->planning_item_id,
            'status' => 'on_hold',
            'triggered_by_high_usage' => true,
            'submitted_by' => $admin->id,
        ]);
        $this->assertNotNull($flag->refresh()->resolved_at);
        $this->assertSame('triggered', $flag->action_taken);
    }

    public function test_rejected_item_does_not_block_high_usage_work_order_item(): void
    {
        [$unit, $unitPlanning] = $this->createHighUsageScenario();
        $flag = app(HighUsageService::class)->detect($unit->refresh())[0];
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'trigger_type' => 'high_usage', 'status' => 'in_progress']);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $unitPlanning->planning_item_id,
            'status' => 'rejected',
        ]);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $unit->site_id]);

        $this->actingAs($admin)->post(route('high-usage.action', $flag), [
            'action' => 'triggered',
        ])->assertRedirect();

        $this->assertSame(2, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->count());
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $unitPlanning->id,
            'status' => 'on_hold',
            'triggered_by_high_usage' => true,
        ]);
    }

    public function test_planner_area_can_defer_and_submit_new_schedule_in_second_window(): void
    {
        [$unit, $unitPlanning] = $this->createHighUsageScenario();
        $flag = app(HighUsageService::class)->detect($unit->refresh())[0];
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $unit->site_id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $availableDate = '2026-08-10';
        $newDueDate = '2026-08-20';

        $this->actingAs($admin)->post(route('high-usage.action', $flag), [
            'action' => 'deferred',
        ])->assertRedirect();

        $this->assertSame('deferred', $flag->refresh()->action_taken);
        $this->assertNull($flag->resolved_at);

        $this->actingAs($admin)->post(route('high-usage.schedule', $flag), [
            'available_date' => $availableDate,
            'new_due_km' => 2400,
            'new_due_date' => $newDueDate,
        ])->assertRedirect();

        $item = WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->firstOrFail();

        $this->assertSame($availableDate, $item->available_date->toDateString());
        $this->assertSame(2400, $item->new_due_km);
        $this->assertSame($newDueDate, $item->new_due_date->toDateString());
        $this->assertSame('postpone', $item->status);
        $this->assertTrue($item->triggered_by_high_usage);
        $this->assertNotNull($flag->refresh()->resolved_at);

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame(2400, $unitPlanning->refresh()->next_due_km);
        $this->assertSame($newDueDate, $unitPlanning->next_due_date->toDateString());
        $this->assertSame($availableDate, $unitPlanning->last_done_date->toDateString());
    }

    public function test_high_usage_second_window_schedule_requires_clear_date_and_km_values(): void
    {
        [$unit] = $this->createHighUsageScenario();
        $flag = app(HighUsageService::class)->detect($unit->refresh())[0];
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $unit->site_id]);

        $this->actingAs($admin)
            ->post(route('high-usage.schedule', $flag), [
                'available_date' => '',
                'new_due_km' => '',
                'new_due_date' => '',
            ])
            ->assertSessionHasErrors(['available_date', 'new_due_km', 'new_due_date']);

        $this->assertSame(0, WorkOrderItem::query()->count());
    }

    public function test_planner_area_only_sees_flags_for_own_site(): void
    {
        [$unit] = $this->createHighUsageScenario();
        $ownFlag = app(HighUsageService::class)->detect($unit->refresh())[0];
        [$otherUnit] = $this->createHighUsageScenario('Other Site');
        $otherFlag = app(HighUsageService::class)->detect($otherUnit->refresh())[0];
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $unit->site_id]);

        $this->withoutVite();

        $this->actingAs($admin)->get(route('high-usage.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('HighUsage/Index')
                ->has('flags.data', 1)
                ->where('flags.data.0.id', $ownFlag->id)
            );

        $this->assertDatabaseHas('high_usage_flags', ['id' => $otherFlag->id]);
    }

    /**
     * @return array{0: Unit, 1: UnitPlanning}
     */
    private function createHighUsageScenario(string $siteName = 'Site Test'): array
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '3']);
        SystemThreshold::query()->updateOrCreate(['key' => 'high_usage_threshold'], ['value' => '20']);

        $site = Site::query()->create(['name' => $siteName, 'region' => 'Region Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'KT '.fake()->unique()->numberBetween(1000, 9999).' AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1800,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service 10K',
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(20)->toDateString(),
            'next_due_km' => 4200,
            'next_due_date' => now()->addDays(20)->toDateString(),
        ]);

        collect([
            [now()->subDays(2), 1000],
            [now()->subDay(), 1400],
            [now(), 1800],
        ])->each(fn (array $log): InspectionLog => InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id])->id,
            'inspection_date' => $log[0]->toDateString(),
            'odometer' => $log[1],
        ]));

        return [$unit, $unitPlanning];
    }
}
