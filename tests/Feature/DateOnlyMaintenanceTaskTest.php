<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Services\MaintenanceTriggerService;
use App\Services\WorkOrderProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DateOnlyMaintenanceTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_warning_creates_task_when_last_done_date_exists_and_km_zero(): void
    {
        [$unit, $planning] = $this->planning(today()->addDays(3)->toDateString(), today()->subMonth()->toDateString());

        $items = app(MaintenanceTriggerService::class)->checkAndTrigger($unit);

        $this->assertCount(1, $items);
        $this->assertSame('on_hold', $items[0]->status);
        $this->assertTrue($planning->isDueByDate());
        $this->assertFalse($planning->isDueByKm());
    }

    public function test_manual_date_without_last_done_date_creates_task_and_becomes_overdue(): void
    {
        [$unit, $planning] = $this->planning(today()->subDay()->toDateString(), null);
        $planning->update(['due_manually_set' => true]);
        $items = app(MaintenanceTriggerService::class)->checkAndTrigger($unit);

        $this->assertCount(1, $items);
        $this->artisan('maintenance:check-overdue')->assertSuccessful();
        $this->assertSame('overdue', $items[0]->refresh()->status);
        $this->assertNull($planning->refresh()->last_done_date);
    }

    public function test_zero_km_without_date_never_triggers_even_if_km_due_is_set(): void
    {
        [$unit, $planning] = $this->planning(null, null);
        $planning->update(['next_due_km' => 1]);

        $this->assertFalse($planning->isDueEligible());
        $this->assertSame([], app(MaintenanceTriggerService::class)->checkAndTrigger($unit));
        $this->assertSame(0, WorkOrderItem::query()->count());
    }

    public function test_date_task_stays_visible_in_board_work_list_and_approval_then_completes_with_km(): void
    {
        [$unit, $planning] = $this->planning(today()->subDay()->toDateString(), null);
        $planning->update(['due_manually_set' => true]);
        $item = app(MaintenanceTriggerService::class)->triggerPlanning($planning, notify: false);
        $this->assertNotNull($item);
        $workOrder = $item->workOrder;
        $this->artisan('wo:cleanup-null-baseline-items --execute')->assertSuccessful();
        $this->assertModelExists($item);
        $admin = User::factory()->create(['role' => UserRole::Superadmin]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $unit->site_id]);

        $this->actingAs($admin)->get(route('work-orders.index', ['include_incomplete_baseline' => 0]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.open.data.0.id', $item->id)
            ->where('boardColumns.open.data.0.baseline_missing', false)
            ->where('boardColumns.open.data.0.due_date', today()->subDay()->toDateString()));
        $this->actingAs($admin)->get(route('work-list.index', ['include_incomplete_baseline' => 0]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.id', $item->id)
            ->where('items.0.baseline_missing', false)
            ->has('baselineItems', 0));

        $this->actingAs($admin)->post(route('work-list.store'), ['groups' => [[
            'site_id' => $unit->site_id,
            'action' => 'replace',
            'item_ids' => [$item->id],
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
        ]]])->assertRedirect(route('work-list.index'));
        $this->assertSame('replace', $item->refresh()->status);
        $this->actingAs($spv)->get(route('approval-queue.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.id', $item->id)
                ->where('items.0.baseline_missing', false)
                ->where('items.0.due_date', today()->subDay()->toDateString()));
        $this->actingAs($spv)->post(route('approval-queue.store'), ['decision' => 'approve', 'item_ids' => [$item->id]])
            ->assertRedirect(route('approval-queue.index'));
        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertTrue(app(WorkOrderProgressService::class)->isDirectCompletionReady(collect([$item->load('unitPlanning')])));
        $this->actingAs($mechanic)->get(route('mechanic.tasks'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $item->id));

        $this->actingAs($admin)->post(route('work-orders.items.complete', [$workOrder, $item]), [
            'completed_odo' => 0, 'completed_date' => today()->toDateString(),
        ])->assertSessionHasErrors('completed_odo');
        $this->actingAs($admin)->post(route('work-orders.items.complete', [$workOrder, $item]), [
            'completed_odo' => 1100, 'completed_date' => today()->toDateString(),
        ])->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('complete', $item->refresh()->status);
        $this->assertSame(1100, $planning->refresh()->last_done_km);
        $this->assertSame(2100, $planning->next_due_km);
        $this->assertFalse($planning->due_manually_set);
    }

    public function test_date_only_task_can_be_postponed_without_inventing_a_km_due(): void
    {
        [, $planning] = $this->planning(today()->subDay()->toDateString(), null);
        $item = app(MaintenanceTriggerService::class)->triggerPlanning($planning, notify: false);
        $admin = User::factory()->create(['role' => UserRole::Superadmin]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($admin)->post(route('work-orders.items.postpone', [$item->workOrder, $item]), [
            'reason' => 'Perlu dijadwalkan ulang',
            'new_due_km' => '',
            'new_due_date' => today()->addDays(10)->toDateString(),
        ])->assertRedirect(route('work-orders.show', $item->workOrder));
        $this->assertNull($item->refresh()->new_due_km);

        $this->actingAs($spv)->post(route('approval-queue.store'), [
            'decision' => 'approve', 'item_ids' => [$item->id],
        ])->assertRedirect(route('approval-queue.index'));
        $this->assertSame('postponed', $item->refresh()->status);
        $this->assertNull($planning->refresh()->next_due_km);
        $this->assertTrue($planning->due_manually_set);
    }

    private function planning(?string $nextDueDate, ?string $lastDoneDate): array
    {
        $region = Region::query()->create(['name' => 'Region Date']);
        $site = Site::query()->create(['name' => 'Site Date', 'region' => 'Region Date', 'region_id' => $region->id]);
        $unit = Unit::query()->create(['site_id' => $site->id, 'customer' => 'A', 'current_plate' => 'KT 4321 DATE',
            'type' => 'Pickup', 'brand' => 'Toyota', 'year' => 2024, 'current_odo' => 1000,
            'status' => 'active', 'has_odometer_reading' => true]);
        $maintenanceItem = PlanningItem::query()->create(['name' => 'Date Service', 'interval_km' => 1000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create(['unit_id' => $unit->id, 'planning_item_id' => $maintenanceItem->id,
            'last_done_km' => 0, 'last_done_date' => $lastDoneDate, 'next_due_km' => null,
            'next_due_date' => $nextDueDate]);

        return [$unit, $planning];
    }
}
