<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualFindingWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_finding_far_from_due_flows_through_approval_complete_and_resets_next_due(): void
    {
        [$region, $site, $unit, $planningItem, $planning] = $this->maintenanceContext();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id, 'region_id' => null]);

        $this->actingAs($planner)
            ->post(route('units.manual-findings.store', $unit), [
                'planning_item_ids' => [$planningItem->id],
                'reason' => 'Brake Pad ditemukan sudah tipis meski belum due.',
            ])
            ->assertRedirect(route('work-orders.index'));

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();
        $item = $workOrder->items()->firstOrFail();

        $this->assertSame('manual', $workOrder->trigger_type);
        $this->assertSame('open', $workOrder->status);
        $this->assertSame('replace', $item->status);
        $this->assertSame('replace', $item->action);
        $this->assertSame($planner->id, $item->submitted_by);
        $this->assertSame(120000, $planning->refresh()->next_due_km);

        $this->actingAs($spv)->post(route('work-orders.approve', $workOrder))->assertRedirect(route('work-orders.show', $workOrder));
        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $item->refresh()->status);

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 60250,
                'completed_date' => now()->toDateString(),
                'notes' => 'Selesai diganti dari temuan manual.',
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $item->refresh()->status);
        $this->assertSame('complete', $workOrder->refresh()->status);
        $this->assertSame(70250, $planning->refresh()->next_due_km);
        $this->assertSame(now()->addDays(30)->toDateString(), $planning->next_due_date->toDateString());
    }

    public function test_manual_finding_rbac_follows_region_and_site_scope(): void
    {
        [$region, $site, $unit, $planningItem] = $this->maintenanceContext();
        $otherRegion = Region::query()->create(['name' => 'Sulawesi']);
        $otherSite = Site::query()->create(['name' => 'MAKASSAR', 'region' => 'Sulawesi Selatan', 'region_id' => $otherRegion->id]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);
        $otherPlanner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $otherRegion->id, 'site_id' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id, 'region_id' => null]);
        $otherMechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $otherSite->id, 'region_id' => null]);
        $payload = ['planning_item_ids' => [$planningItem->id], 'reason' => 'Temuan lapangan.'];

        $this->actingAs($planner)->post(route('units.manual-findings.store', $unit), $payload)->assertRedirect();
        $this->actingAs($mechanic)->post(route('units.manual-findings.store', $unit), $payload)->assertRedirect();
        $this->actingAs($otherPlanner)->post(route('units.manual-findings.store', $unit), $payload)->assertForbidden();
        $this->actingAs($otherMechanic)->post(route('units.manual-findings.store', $unit), $payload)->assertForbidden();
    }

    public function test_complete_notification_goes_to_correct_planner_area_once_per_work_order(): void
    {
        [$region, $site, $unit, $firstItem, $firstPlanning] = $this->maintenanceContext();
        $secondItem = PlanningItem::query()->create(['name' => 'Filter Oli', 'interval_km' => 5000, 'interval_days' => 15]);
        UnitPlanning::query()->create(['unit_id' => $unit->id, 'planning_item_id' => $secondItem->id, 'last_done_km' => 50000, 'last_done_date' => now()->subMonths(2)->toDateString(), 'next_due_km' => 120000, 'next_due_date' => now()->addMonths(6)->toDateString()]);
        $otherRegion = Region::query()->create(['name' => 'Sulawesi']);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);
        $otherPlanner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $otherRegion->id, 'site_id' => null]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id, 'region_id' => null]);

        $this->actingAs($planner)->post(route('units.manual-findings.store', $unit), [
            'planning_item_ids' => [$firstItem->id, $secondItem->id],
            'reason' => 'Dua item ditemukan perlu diganti.',
        ]);

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();
        $this->actingAs($spv)->post(route('work-orders.approve', $workOrder));

        foreach ($workOrder->items()->get() as $item) {
            $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 61000,
                'completed_date' => now()->toDateString(),
            ]);
        }

        $this->assertDatabaseHas(Notification::class, [
            'user_id' => $planner->id,
            'type' => 'work_order_item_completed',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $planner->id)->where('type', 'work_order_item_completed')->count());
        $this->assertDatabaseMissing(Notification::class, [
            'user_id' => $otherPlanner->id,
            'type' => 'work_order_item_completed',
        ]);
    }

    public function test_manual_finding_badge_and_form_are_visible_on_work_order_detail(): void
    {
        [$region, , $unit, $planningItem] = $this->maintenanceContext();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);

        $this->actingAs($planner)->post(route('units.manual-findings.store', $unit), [
            'planning_item_ids' => [$planningItem->id],
            'reason' => 'Temuan untuk cek badge.',
        ]);

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

        $this->actingAs($planner)
            ->get(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrder.data.trigger_type', 'manual')
                ->has('planningItems', 1)
            );
    }

    public function test_manual_finding_can_include_mechanic_and_schedule_before_approval(): void
    {
        [$region, $site, $unit, $planningItem] = $this->maintenanceContext();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $scheduledDate = today()->addDays(2)->toDateString();

        $this->actingAs($planner)->post(route('units.manual-findings.store', $unit), [
            'planning_item_ids' => [$planningItem->id],
            'reason' => 'Temuan butuh replace fisik.',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => $scheduledDate,
        ])->assertRedirect(route('work-orders.index'));

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->latest('id')->firstOrFail();

        $this->assertSame($mechanic->id, $workOrder->assigned_mechanic_id);
        $this->assertSame($scheduledDate, $workOrder->scheduled_date->toDateString());

        $this->actingAs($spv)->post(route('work-orders.approve', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame($mechanic->id, $workOrder->assigned_mechanic_id);
        $this->assertSame('in_progress', $workOrder->items()->firstOrFail()->status);
    }

    /**
     * @return array{Region, Site, Unit, PlanningItem, UnitPlanning}
     */
    private function maintenanceContext(): array
    {
        $region = Region::query()->create(['name' => 'Kalimantan']);
        $site = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur', 'region_id' => $region->id]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT NAJ',
            'current_plate' => 'KT 8404 YR',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'vehicle_category' => 'pickup_suv',
            'year' => 2024,
            'current_odo' => 60000,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Brake Pad', 'interval_km' => 10000, 'interval_days' => 30]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 50000,
            'last_done_date' => now()->subMonths(2)->toDateString(),
            'next_due_km' => 120000,
            'next_due_date' => now()->addMonths(6)->toDateString(),
        ]);

        return [$region, $site, $unit, $planningItem, $planning];
    }
}
