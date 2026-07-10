<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\UnitSiteTransfer;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitSiteTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_can_submit_transfer_for_unit_in_region_but_not_outside_region(): void
    {
        [$oldRegion, $newRegion, $oldSite, $newSite, $outsideSite] = $this->siteFixture();
        $unit = $this->unit($oldSite);
        $outsideUnit = $this->unit($outsideSite, 'KT 2000 ZZ');
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $oldRegion->id, 'site_id' => null]);
        User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($planner)->post(route('units.site-transfers.store', $unit), [
            'to_site_id' => $newSite->id,
            'reason' => 'Unit pindah area kerja',
        ])->assertRedirect();

        $this->assertDatabaseHas('unit_site_transfers', [
            'unit_id' => $unit->id,
            'from_site_id' => $oldSite->id,
            'to_site_id' => $newSite->id,
            'status' => 'pending',
            'requested_by' => $planner->id,
        ]);
        $this->assertSame($oldSite->id, $unit->refresh()->site_id);
        $this->assertDatabaseHas('notifications', ['type' => 'unit_site_transfer_requested']);

        $this->actingAs($planner)->post(route('units.site-transfers.store', $outsideUnit), [
            'to_site_id' => $oldSite->id,
        ])->assertForbidden();
    }

    public function test_approve_moves_unit_and_active_work_order_follows_new_region_scope(): void
    {
        [$oldRegion, $newRegion, $oldSite, $newSite] = $this->siteFixture();
        $unit = $this->unit($oldSite);
        $plannerOld = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $oldRegion->id, 'site_id' => null]);
        $plannerNew = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $newRegion->id, 'site_id' => null]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $workOrder = $this->activeWorkOrder($unit, $oldSite);
        $transfer = UnitSiteTransfer::query()->create([
            'unit_id' => $unit->id,
            'from_site_id' => $oldSite->id,
            'to_site_id' => $newSite->id,
            'requested_by' => $plannerOld->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($spv)->post(route('unit-site-transfers.approve', $transfer), [
            'decision_reason' => 'Disetujui',
        ])->assertRedirect();

        $this->assertSame($newSite->id, $unit->refresh()->site_id);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'open']);
        $this->assertSame($oldSite->id, $workOrder->refresh()->site_id);
        $this->assertDatabaseHas('unit_site_transfers', ['id' => $transfer->id, 'status' => 'approved', 'approved_by' => $spv->id]);

        $this->actingAs($plannerOld)->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.open.data', fn ($items): bool => collect($items)->pluck('id')->doesntContain($workOrder->id))
        );
        $this->actingAs($plannerNew)->get(route('work-orders.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.open.data', fn ($items): bool => collect($items)->pluck('id')->contains($workOrder->id))
        );

        $this->assertDatabaseHas('notifications', ['user_id' => $plannerOld->id, 'type' => 'unit_site_transfer_approved_old_region']);
        $this->assertDatabaseHas('notifications', ['user_id' => $plannerNew->id, 'type' => 'unit_site_transfer_approved_new_region']);
    }

    public function test_reject_does_not_change_unit_site(): void
    {
        [$oldRegion, , $oldSite, $newSite] = $this->siteFixture();
        $unit = $this->unit($oldSite);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $oldRegion->id, 'site_id' => null]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $transfer = UnitSiteTransfer::query()->create([
            'unit_id' => $unit->id,
            'from_site_id' => $oldSite->id,
            'to_site_id' => $newSite->id,
            'requested_by' => $planner->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $this->actingAs($spv)->post(route('unit-site-transfers.reject', $transfer), [
            'decision_reason' => 'Belum perlu',
        ])->assertRedirect();

        $this->assertSame($oldSite->id, $unit->refresh()->site_id);
        $this->assertDatabaseHas('unit_site_transfers', [
            'id' => $transfer->id,
            'status' => 'rejected',
            'approved_by' => $spv->id,
            'decision_reason' => 'Belum perlu',
        ]);
    }

    /** @return array{Region, Region, Site, Site, Site} */
    private function siteFixture(): array
    {
        $oldRegion = Region::query()->create(['name' => 'Kalimantan']);
        $newRegion = Region::query()->create(['name' => 'Sulawesi']);
        $oldSite = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur', 'region_id' => $oldRegion->id]);
        $newSite = Site::query()->create(['name' => 'MKS', 'region' => 'Sulawesi Selatan', 'region_id' => $newRegion->id]);
        $outsideSite = Site::query()->create(['name' => 'KDI', 'region' => 'Sulawesi Tenggara', 'region_id' => $newRegion->id]);

        return [$oldRegion, $newRegion, $oldSite, $newSite, $outsideSite];
    }

    private function unit(Site $site, string $plate = 'KT 1000 AA'): Unit
    {
        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT Test',
            'current_plate' => $plate,
            'type' => 'Toyota Hilux',
            'brand' => 'Toyota',
            'vehicle_category' => 'pickup_suv',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]);
    }

    private function activeWorkOrder(Unit $unit, Site $site): WorkOrder
    {
        $planningItem = PlanningItem::query()->create(['name' => 'Service A', 'interval_km' => 10000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'next_due_km' => 10000,
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $planning->id, 'planning_item_id' => $planningItem->id, 'status' => 'on_hold']);

        return $workOrder;
    }
}
