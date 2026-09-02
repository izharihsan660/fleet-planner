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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaintenanceTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_km_input_creates_normal_work_order_item_when_threshold_is_reached(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 1000, 'interval_days' => 90]);
        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => 2000,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1500,
        ])->assertRedirect(route('inspections.create'));

        $workOrder = WorkOrder::query()->firstOrFail();

        $this->assertSame($unit->id, $workOrder->unit_id);
        $this->assertSame($site->id, $workOrder->site_id);
        $this->assertSame('normal', $workOrder->trigger_type);
        $this->assertSame('open', $workOrder->status);
        $this->assertSame(1, WorkOrderItem::query()->where('work_order_id', $workOrder->id)->count());
        $this->assertSame('on_hold', WorkOrderItem::query()->value('status'));

        $this->actingAs($mechanic)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/Index')
                ->where('boardColumns.open.data.0.id', WorkOrderItem::query()->value('id'))
                ->where('boardColumns.open.data.0.status', 'on_hold')
                ->where('boardColumns.open.data.0.unit_plate', $unit->current_plate)
            );
    }

    public function test_daily_km_input_skips_due_items_with_zero_baseline_km_even_when_date_exists(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Mixed Baseline', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $unit->update(['has_odometer_reading' => true]);
        $validPlanningItem = PlanningItem::query()->create(['name' => 'Baseline Lengkap', 'interval_km' => 1000, 'interval_days' => 90]);
        $missingPlanningItem = PlanningItem::query()->create(['name' => 'Baseline Kosong', 'interval_km' => 1000, 'interval_days' => 90]);
        $validPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $validPlanningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => 2000,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
        $missingPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $missingPlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => 2000,
            'next_due_date' => now()->subDays(83)->toDateString(),
        ]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1500,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $validPlanning->id)->count());
        $this->assertSame(0, WorkOrderItem::query()->where('unit_planning_id', $missingPlanning->id)->count());
    }

    public function test_overdue_scheduler_evaluates_each_item_baseline_independently(): void
    {
        $site = Site::query()->create(['name' => 'Site Scheduler', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 5000));
        $validPlanningItem = PlanningItem::query()->create(['name' => 'Valid Scheduler', 'interval_km' => 1000, 'interval_days' => 30]);
        $missingPlanningItem = PlanningItem::query()->create(['name' => 'Missing Scheduler', 'interval_km' => 1000, 'interval_days' => 30]);
        $validPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $validPlanningItem->id,
            'last_done_km' => 3000,
            'last_done_date' => null,
            'next_due_km' => 4000,
            'next_due_date' => null,
        ]);
        $missingPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $missingPlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => 1000,
            'next_due_date' => today()->subDays(83)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $validItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $validPlanning->id, 'planning_item_id' => $validPlanningItem->id, 'status' => 'on_hold']);
        $missingItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $missingPlanning->id, 'planning_item_id' => $missingPlanningItem->id, 'status' => 'on_hold']);

        $this->assertTrue($missingPlanning->isBaselineMissing());
        $this->assertFalse(WorkOrderItem::query()->whereKey($missingItem)->withBaseline()->exists());

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('overdue', $validItem->refresh()->status);
        $this->assertSame('on_hold', $missingItem->refresh()->status);
    }

    public function test_trigger_reuses_open_work_order_and_does_not_duplicate_active_item(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $firstPlanning = $this->createPlanning($unit, 'Ganti Oli', 2000);
        $secondPlanning = $this->createPlanning($unit, 'Filter Solar', 1900);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 1500,
            'previous_odo' => 1000,
        ]);
        $unit->update(['current_odo' => 1500]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1600,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(2, WorkOrderItem::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $firstPlanning->id)->count());
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $secondPlanning->id)->count());
    }

    public function test_trigger_merges_new_item_into_work_order_that_is_already_in_progress(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Merge', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $workingPlanning = $this->createPlanning($unit, 'Ganti Oli', 2000);
        $newlyDuePlanning = $this->createPlanning($unit, 'Filter Solar', 1900);

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
            'approved_at' => now(),
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $workingPlanning->id,
            'planning_item_id' => $workingPlanning->planning_item_id,
            'status' => 'in_progress',
            'scheduled_date' => today()->toDateString(),
        ]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1600,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertDatabaseHas('work_order_items', [
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $newlyDuePlanning->id,
            'status' => 'on_hold',
        ]);
        $this->assertSame('in_progress', $workOrder->refresh()->status);
    }

    public function test_trigger_creates_new_work_order_when_previous_one_is_already_finished(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Fresh', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $donePlanning = $this->createPlanning($unit, 'Ganti Oli', 900);
        $newlyDuePlanning = $this->createPlanning($unit, 'Filter Solar', 1900);

        $completedWorkOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'complete',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $completedWorkOrder->id,
            'unit_planning_id' => $donePlanning->id,
            'planning_item_id' => $donePlanning->planning_item_id,
            'status' => 'complete',
        ]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1600,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(2, WorkOrder::query()->count());
        $this->assertDatabaseHas('work_order_items', [
            'work_order_id' => WorkOrder::query()->latest('id')->value('id'),
            'unit_planning_id' => $newlyDuePlanning->id,
            'status' => 'on_hold',
        ]);
    }

    public function test_spv_ho_can_approve_work_order_and_complete_item_updates_planning(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 3000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(120)->toDateString(),
            'next_due_km' => 3000,
            'next_due_date' => now()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $spvOps = User::factory()->create(['role' => UserRole::SpvHo, 'site_id' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        // Sudah diajukan planner lengkap dengan penanggung jawab dan jadwalnya.
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'replace',
            'action' => 'replace',
            'scheduled_date' => today()->toDateString(),
        ]);
        $workOrder->update(['assigned_mechanic_id' => $mechanic->id]);

        $this->actingAs($spvOps)->post(route('work-orders.approve', $workOrder))->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $item->refresh()->status);

        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $item]), [
            'completed_odo' => 3200,
            'completed_date' => now()->toDateString(),
        ])->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $item->refresh()->status);
        $this->assertSame('complete', $workOrder->refresh()->status);
        $this->assertSame(3200, $unitPlanning->refresh()->last_done_km);
        $this->assertSame(8200, $unitPlanning->next_due_km);
        $this->assertSame(now()->addDays(90)->toDateString(), $unitPlanning->next_due_date->toDateString());
    }

    public function test_daily_km_input_preserves_approved_postponed_due_km(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 1000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => 2000,
            'next_due_date' => now()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'postpone',
            'action' => 'postpone',
            'previous_due_km' => 2000,
            'previous_due_date' => now()->toDateString(),
            'new_due_km' => 5000,
            'new_due_date' => now()->addDays(30)->toDateString(),
        ]);
        $spvHo = User::factory()->create(['role' => UserRole::SpvHo, 'site_id' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($spvHo)->post(route('work-orders.approve', $workOrder))->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('postponed', $item->refresh()->status);
        $this->assertSame(5000, $unitPlanning->refresh()->next_due_km);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->addDay()->toDateString(),
            'odometer' => 1600,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(5000, $unitPlanning->refresh()->next_due_km);
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->count());
    }

    public function test_rejected_item_does_not_block_future_maintenance_trigger(): void
    {
        $this->seedThresholds();

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 1000));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 1000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => 2000,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
        $rejectedWorkOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'in_progress']);
        WorkOrderItem::query()->create([
            'work_order_id' => $rejectedWorkOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'rejected',
        ]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 1600,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(2, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->count());
        $this->assertSame(1, WorkOrderItem::query()->where('unit_planning_id', $unitPlanning->id)->where('status', 'on_hold')->count());
    }

    private function seedThresholds(): void
    {
        SystemThreshold::query()->create(['key' => 'warning_km', 'value' => '500', 'description' => 'Warning KM']);
        SystemThreshold::query()->create(['key' => 'warning_days', 'value' => '7', 'description' => 'Warning days']);
        SystemThreshold::query()->create(['key' => 'min_inspection_data', 'value' => '1', 'description' => 'Minimum data']);
    }

    private function createPlanning(Unit $unit, string $name, int $nextDueKm): UnitPlanning
    {
        $planningItem = PlanningItem::query()->create(['name' => $name, 'interval_km' => $nextDueKm - 1, 'interval_days' => 90]);

        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1,
            'last_done_date' => now()->subDays(60)->toDateString(),
            'next_due_km' => $nextDueKm,
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId, int $currentOdo): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'Customer A',
            'current_plate' => 'KT '.fake()->unique()->numberBetween(1000, 9999).' AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ];
    }
}
