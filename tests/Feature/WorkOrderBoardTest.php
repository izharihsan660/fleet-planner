<?php

namespace Tests\Feature;

use App\Enums\UserRole;
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

class WorkOrderBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_splits_upcoming_and_preparation_from_live_unit_plannings(): void
    {
        $admin = $this->adminSite();
        $unit = $this->unit($admin->site_id, 10000, 100);
        $upcomingPlanning = $this->planning($unit, 'Service 20 hari', 20000, today()->addDays(20)->toDateString());
        $preparationPlanning = $this->planning($unit, 'Service 10 hari', 20000, today()->addDays(10)->toDateString());
        $onHoldThresholdPlanning = $this->planning($unit, 'Service 6 hari', 20000, today()->addDays(6)->toDateString());
        $outsidePlanning = $this->planning($unit, 'Service 40 hari', 20000, today()->addDays(40)->toDateString());

        $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/Index')
                ->has('upcomingItems', 1)
                ->where('upcomingItems.0.id', $upcomingPlanning->id)
                ->has('preparationItems', 1)
                ->where('preparationItems.0.id', $preparationPlanning->id)
            );

        $this->assertDatabaseMissing('work_order_items', ['unit_planning_id' => $onHoldThresholdPlanning->id]);
        $this->assertDatabaseMissing('work_order_items', ['unit_planning_id' => $outsidePlanning->id]);
    }

    public function test_admin_site_request_from_preview_card_waits_for_spv_approval(): void
    {
        $admin = $this->adminSite();
        $spv = User::factory()->create(['role' => UserRole::SpvOps]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Engine Oil', 12000, today()->addDays(6)->toDateString());

        $this->actingAs($admin)
            ->post(route('unit-plannings.create-work-order', $planning))
            ->assertRedirect();

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->assertSame('open', $workOrder->status);
        $this->assertDatabaseHas('work_orders', [
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'submitted_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'pending_create',
            'action' => 'create_task',
        ]);

        $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workOrders.data', 1)
                ->where('workOrders.data.0.id', $workOrder->id)
                ->where('workOrders.data.0.sub_status.label', 'Menunggu Approval')
            );

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('open', $workOrder->refresh()->status);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'status' => 'on_hold',
            'approved_by' => $spv->id,
        ]);
    }

    public function test_spv_can_reject_preview_task_request_and_preview_returns(): void
    {
        $admin = $this->adminSite();
        $spv = User::factory()->create(['role' => UserRole::SpvOps]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Air Filter', 12000, today()->addDays(10)->toDateString());

        $this->actingAs($admin)
            ->post(route('unit-plannings.create-work-order', $planning))
            ->assertRedirect();

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->actingAs($spv)
            ->post(route('work-orders.reject', $workOrder))
            ->assertRedirect(route('work-orders.index'));

        $this->assertSame('cancelled', $workOrder->refresh()->status);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'status' => 'rejected',
            'approved_by' => $spv->id,
        ]);

        $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('preparationItems', 1)
                ->where('preparationItems.0.id', $planning->id)
                ->where('preparationItems.0.approval_status', 'rejected')
            );
    }

    public function test_admin_site_can_assign_same_site_mechanic_to_approved_in_progress_work_order(): void
    {
        $admin = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $admin->site_id]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Brake', 12000, today()->addDays(5)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'submitted_by' => $admin->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'in_progress',
            'action' => 'replace',
        ]);

        $this->actingAs($admin)
            ->post(route('work-orders.assign-mechanic', $workOrder), [
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame($mechanic->id, $workOrder->refresh()->assigned_mechanic_id);
        $this->assertSame(today()->addDay()->toDateString(), $workOrder->scheduled_date->toDateString());
    }

    public function test_assign_mechanic_rejects_past_date(): void
    {
        $admin = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $admin->site_id]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'approved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('work-orders.index'))
            ->post(route('work-orders.assign-mechanic', $workOrder), [
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('scheduled_date');
    }

    private function adminSite(): User
    {
        $site = Site::query()->create(['name' => 'Site A', 'region' => 'East']);

        SystemThreshold::query()->updateOrCreate(['key' => 'warning_days'], ['value' => '7']);
        SystemThreshold::query()->updateOrCreate(['key' => 'warning_km'], ['value' => '1000']);
        SystemThreshold::query()->updateOrCreate(['key' => 'ancang_ancang_days'], ['value' => '14']);
        SystemThreshold::query()->updateOrCreate(['key' => 'ancang_ancang_km'], ['value' => '2000']);
        SystemThreshold::query()->updateOrCreate(['key' => 'upcoming_days'], ['value' => '28']);
        SystemThreshold::query()->updateOrCreate(['key' => 'upcoming_km'], ['value' => '4000']);

        return User::factory()->create(['role' => UserRole::AdminSite, 'site_id' => $site->id]);
    }

    private function unit(int $siteId, int $currentOdo, int $avgKmPerDay): Unit
    {
        return Unit::query()->create([
            'site_id' => $siteId,
            'customer' => 'Customer',
            'current_plate' => 'KT 1234 AA',
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'avg_km_per_day' => $avgKmPerDay,
            'status' => 'active',
        ]);
    }

    private function planning(Unit $unit, string $name, int $nextDueKm, string $nextDueDate): UnitPlanning
    {
        $item = PlanningItem::query()->create([
            'name' => $name,
            'interval_km' => 10000,
            'interval_days' => 180,
        ]);

        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $item->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subDays(180)->toDateString(),
            'next_due_km' => $nextDueKm,
            'next_due_date' => $nextDueDate,
        ]);
    }
}
