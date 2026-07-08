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
use Database\Seeders\PlanningItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderActionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_area_submits_replace_spv_approves_and_spv_ho_is_notified(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $spv_ho = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);

        $this->actingAs($admin)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), ['reason' => 'Ganti part sesuai jadwal'])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('replace', $item->refresh()->status);
        $this->assertSame('replace', $item->action);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertDatabaseHas(Notification::class, [
            'user_id' => $spv_ho->id,
            'type' => 'work_order_approved',
        ]);
    }

    public function test_warranty_replace_approval_does_not_notify_spv_ho(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(40000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $spv_ho = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);

        $this->actingAs($admin)->post(route('work-orders.items.replace', [$workOrder, $item]));
        $this->actingAs($spv)->post(route('work-orders.approve', $workOrder));

        $this->assertDatabaseMissing(Notification::class, [
            'user_id' => $spv_ho->id,
            'type' => 'work_order_approved',
        ]);
    }

    public function test_planner_area_submits_postpone_and_spv_approval_moves_due_schedule(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);
        $requestedDueDate = '2026-08-15';

        $this->actingAs($admin)
            ->post(route('work-orders.items.postpone', [$workOrder, $item]), [
                'reason' => 'Unit belum bisa masuk workshop',
                'new_due_km' => 88000,
                'new_due_date' => $requestedDueDate,
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('postpone', $item->refresh()->status);
        $this->assertSame(88000, $item->new_due_km);
        $this->assertSame($requestedDueDate, $item->new_due_date->toDateString());

        $this->actingAs($admin)
            ->get(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrder.data.items.0.new_due_date', $requestedDueDate)
                ->where('workOrder.data.items.0.effective_due_date', $requestedDueDate)
            );

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('complete', $workOrder->refresh()->status);
        $this->assertSame('postponed', $item->refresh()->status);
        $this->assertSame(88000, $planning->refresh()->next_due_km);
        $this->assertSame($requestedDueDate, $planning->next_due_date->toDateString());

        $this->actingAs($admin)
            ->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('history.postpones.data.0.new_due_date', $requestedDueDate)
            );
    }

    public function test_spv_cannot_approve_work_order_without_submitted_action(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertStatus(422);

        $this->assertSame('open', $workOrder->refresh()->status);
        $this->assertSame('on_hold', $item->refresh()->status);
    }

    public function test_blocked_item_can_be_resolved_to_on_hold(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin, 'blocked');

        $this->actingAs($admin)
            ->post(route('work-order-items.resolve-blocked', $item))
            ->assertRedirect();

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertSame('open', $workOrder->refresh()->status);
    }

    public function test_planner_area_cannot_open_or_store_daily_km_input(): void
    {
        [$site, $unit] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($admin)->get(route('inspections.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => today()->toDateString(),
            'odometer' => 76000,
        ])->assertForbidden();
    }

    /**
     * @return array{0: Site, 1: Unit, 2: UnitPlanning}
     */
    private function makePlanningContext(int $currentOdo): array
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'DD 1234 QA',
            'type' => 'Operasional',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ]);

        $planningItem = PlanningItem::query()->where('name', 'PM Check / Reguler Services')->firstOrFail();
        $planning = UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
            [
                'last_done_km' => $currentOdo - $planningItem->interval_km,
                'last_done_date' => today()->subDays($planningItem->interval_days)->toDateString(),
                'next_due_km' => $currentOdo,
                'next_due_date' => today()->toDateString(),
            ],
        );

        return [$site, $unit->refresh(), $planning->refresh()];
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderItem}
     */
    private function makeWorkOrder(Unit $unit, UnitPlanning $planning, User $actor, string $status = 'on_hold'): array
    {
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
            'submitted_by' => $actor->id,
        ]);

        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => $status,
            'action' => $status === 'blocked' ? 'blocked' : null,
            'submitted_by' => $actor->id,
        ]);

        return [$workOrder, $item];
    }
}
