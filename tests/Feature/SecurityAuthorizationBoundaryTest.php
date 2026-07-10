<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VehicleCategory;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_area_cannot_edit_unit_from_another_region(): void
    {
        [$kalimantan, $sulawesi] = $this->makeRegions();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $kalimantan->id]);
        $sulawesiSite = $this->makeSite('Sulawesi Site', $sulawesi);
        $unit = $this->makeUnit($sulawesiSite, 'DP 1001 QA');

        $this->actingAs($planner)
            ->put(route('units.update', $unit), [
                'site_id' => $sulawesiSite->id,
                'customer' => $unit->customer,
                'current_plate' => $unit->current_plate,
                'type' => $unit->type,
                'brand' => $unit->brand,
                'vehicle_category' => VehicleCategory::TrukRingan->value,
                'year' => $unit->year,
                'current_odo' => $unit->current_odo,
                'status' => $unit->status,
            ])
            ->assertForbidden();
    }

    public function test_mechanic_cannot_access_master_data_or_approval_queue(): void
    {
        $site = $this->makeSite('Mechanic Site', $this->makeRegion('Kalimantan'));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->get(route('units.index'))->assertForbidden();
        $this->actingAs($mechanic)->get(route('approval-queue.index'))->assertForbidden();
        $this->actingAs($mechanic)->post(route('approval-queue.store'), [
            'decision' => 'approve',
            'item_ids' => [1],
        ])->assertForbidden();
    }

    public function test_planner_area_cannot_approve_work_order_item_from_another_region(): void
    {
        [$kalimantan, $sulawesi] = $this->makeRegions();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $kalimantan->id]);
        $sulawesiSite = $this->makeSite('Sulawesi Approval Site', $sulawesi);
        $unit = $this->makeUnit($sulawesiSite, 'DP 2002 QA');
        $item = $this->makeApprovalItem($unit, $planner);

        $this->actingAs($planner)
            ->post(route('approval-queue.store'), [
                'decision' => 'approve',
                'item_ids' => [$item->id],
            ])
            ->assertForbidden();

        $this->assertSame('replace', $item->refresh()->status);
    }

    /**
     * @return array{0: Region, 1: Region}
     */
    private function makeRegions(): array
    {
        return [
            $this->makeRegion('Kalimantan'),
            $this->makeRegion('Sulawesi'),
        ];
    }

    private function makeRegion(string $name): Region
    {
        return Region::query()->create(['name' => $name]);
    }

    private function makeSite(string $name, Region $region): Site
    {
        return Site::query()->create([
            'name' => $name,
            'region' => $region->name,
            'region_id' => $region->id,
        ]);
    }

    private function makeUnit(Site $site, string $plate): Unit
    {
        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'vehicle_category' => VehicleCategory::TrukRingan->value,
            'year' => 2024,
            'current_odo' => 75000,
            'status' => 'active',
        ]);
    }

    private function makeApprovalItem(Unit $unit, User $submittedBy): WorkOrderItem
    {
        $planningItem = PlanningItem::query()->create([
            'name' => 'Security Audit Item',
            'interval_km' => 10000,
            'interval_days' => 90,
        ]);

        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 65000,
            'last_done_date' => today()->subDays(90)->toDateString(),
            'next_due_km' => 75000,
            'next_due_date' => today()->toDateString(),
        ]);

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
            'submitted_by' => $submittedBy->id,
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'replace',
            'status' => 'replace',
            'reason' => 'Security audit approval item',
            'submitted_by' => $submittedBy->id,
        ]);
    }
}
