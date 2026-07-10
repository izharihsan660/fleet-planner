<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_mechanic_can_mark_item_blocked_without_freezing_due_date(): void
    {
        [$site, $unit, $planning, $item] = $this->createWorkOrderScenario();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('work-order-items.blocked', $item), [
            'reason' => 'Menunggu approval customer.',
        ])->assertRedirect();

        $item->refresh();
        $planning->refresh();

        $this->assertSame('blocked', $item->status);
        $this->assertSame('blocked', $item->action);
        $this->assertSame('Menunggu approval customer.', $item->reason);
        $this->assertNull($item->freeze_start);
        $this->assertSame('2026-07-10', $planning->next_due_date->toDateString());
        $this->assertSame('active', $unit->refresh()->status);
    }

    public function test_regional_planner_can_mark_blocked_and_breakdown_for_region_site(): void
    {
        [$site, $unit, $planning, $item] = $this->createWorkOrderScenario();
        $region = Region::query()->create(['name' => 'Region Test']);
        $site->update(['region_id' => $region->id]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);

        $this->actingAs($planner)->post(route('work-order-items.blocked', $item), [
            'reason' => 'Menunggu approval regional.',
        ])->assertRedirect();

        $this->assertSame('blocked', $item->refresh()->status);

        $item->update(['status' => 'on_hold', 'action' => null]);

        $this->actingAs($planner)->post(route('units.breakdown', $unit), [
            'reason' => 'Unit breakdown di region.',
        ])->assertRedirect();

        $this->assertSame('breakdown', $unit->refresh()->status);
        $this->assertSame('breakdown', $item->refresh()->status);
        $this->assertNotNull($planning->refresh()->freeze_start);
    }

    public function test_breakdown_freezes_active_items_and_unfreezes_on_next_inspection(): void
    {
        CarbonImmutable::setTestNow('2026-06-30 09:00:00');
        [$site, $unit, $planning, $item] = $this->createWorkOrderScenario();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('units.breakdown', $unit), [
            'reason' => 'Engine rusak.',
        ])->assertRedirect();

        $this->assertSame('breakdown', $unit->refresh()->status);
        $this->assertSame('breakdown', $item->refresh()->status);
        $this->assertSame('2026-06-30 09:00:00', $item->freeze_start->toDateTimeString());
        $this->assertSame('2026-06-30 09:00:00', $planning->refresh()->freeze_start->toDateTimeString());

        CarbonImmutable::setTestNow('2026-07-03 10:00:00');

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => '2026-07-03',
            'odometer' => 1200,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame('active', $unit->refresh()->status);
        $this->assertSame('breakdown', $item->refresh()->status);
        $this->assertSame('2026-07-03 10:00:00', $item->freeze_end->toDateTimeString());
        $this->assertNull($planning->refresh()->freeze_start);
        $this->assertSame('2026-07-13', $planning->next_due_date->toDateString());

        CarbonImmutable::setTestNow();
    }

    public function test_breakdown_item_cannot_submit_replace_before_breakdown_inspection(): void
    {
        [$site, $unit, $planning, $item] = $this->createWorkOrderScenario();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('units.breakdown', $unit), [
            'reason' => 'Engine rusak.',
        ])->assertRedirect();

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => '2026-07-03',
            'odometer' => 1200,
        ])->assertRedirect(route('inspections.create'));

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$item->workOrder, $item]), [
                'reason' => 'Coba lanjut normal sebelum inspeksi breakdown.',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame('breakdown', $item->refresh()->status);
        $this->assertSame(5000, $planning->refresh()->next_due_km);
    }

    public function test_unit_breakdown_blocks_normal_actions_even_when_item_is_still_on_hold(): void
    {
        [$site, $unit, $planning, $item] = $this->createWorkOrderScenario();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $unit->update(['status' => 'breakdown']);

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$item->workOrder, $item]), [
                'reason' => 'Coba replace saat unit breakdown.',
            ])
            ->assertSessionHasErrors('action');

        $this->actingAs($planner)
            ->post(route('work-orders.items.postpone', [$item->workOrder, $item]), [
                'reason' => 'Coba tunda saat unit breakdown.',
                'new_due_km' => 6000,
                'new_due_date' => '2026-07-20',
            ])
            ->assertSessionHasErrors('action');

        $this->actingAs($planner)
            ->post(route('work-order-items.blocked', $item), [
                'reason' => 'Coba blokir saat unit breakdown.',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertSame(5000, $planning->refresh()->next_due_km);
    }

    public function test_breakdown_inspection_resets_selected_unit_planning_cycle(): void
    {
        CarbonImmutable::setTestNow('2026-07-04 08:00:00');
        [$site, $unit, $planning, $item] = $this->createWorkOrderScenario();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $item->update(['status' => 'breakdown', 'action' => 'breakdown', 'freeze_start' => now()]);
        $unit->update(['status' => 'breakdown']);

        $this->actingAs($mechanic)->post(route('units.breakdown-inspection', $unit), [
            'unit_planning_id' => $planning->id,
            'completed_odo' => 1500,
        ])->assertRedirect()->assertSessionHas('status', 'Inspeksi breakdown tersimpan, cycle lanjut normal.');

        $planning->refresh();

        $this->assertSame(1500, $planning->last_done_km);
        $this->assertSame('2026-07-04', $planning->last_done_date->toDateString());
        $this->assertSame(6500, $planning->next_due_km);
        $this->assertSame('2026-08-03', $planning->next_due_date->toDateString());
        $this->assertNull($planning->freeze_start);
        $this->assertSame('complete', $item->refresh()->status);
        $this->assertSame('active', $unit->refresh()->status);

        CarbonImmutable::setTestNow();
    }

    /**
     * @return array{Site, Unit, UnitPlanning, WorkOrderItem}
     */
    private function createWorkOrderScenario(): array
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'KT 1234 AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 5000, 'interval_days' => 30]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => '2026-06-10',
            'next_due_km' => 5000,
            'next_due_date' => '2026-07-10',
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'on_hold',
        ]);

        return [$site, $unit, $planning, $item];
    }
}
