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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_area_can_access_all_sites_in_own_region_only(): void
    {
        [$kalimantan, $sulawesi, $bpn, $smd, $makassar] = $this->regionScenario();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $kalimantan->id, 'site_id' => null]);

        $bpnUnit = $this->unit($bpn, 'KT 1001 AA');
        $smdUnit = $this->unit($smd, 'KT 1002 BB');
        $makassarUnit = $this->unit($makassar, 'DD 1003 CC');

        $bpnWorkOrder = $this->workOrder($bpnUnit, $planner);
        $smdWorkOrder = $this->workOrder($smdUnit, $planner);
        $makassarWorkOrder = $this->workOrder($makassarUnit, $planner);

        $this->assertTrue($planner->can('view', $bpnUnit));
        $this->assertTrue($planner->can('view', $smdUnit));
        $this->assertFalse($planner->can('view', $makassarUnit));
        $this->assertTrue($planner->can('view', $bpnWorkOrder));
        $this->assertTrue($planner->can('view', $smdWorkOrder));
        $this->assertFalse($planner->can('view', $makassarWorkOrder));

        $this->actingAs($planner)->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites.data', 2)
                ->where('sites.data.0.id', $bpn->id)
                ->where('sites.data.1.id', $smd->id)
                ->where('boardColumns.open.meta.total', 2)
            );

        $this->actingAs($planner)->get(route('work-orders.show', $makassarWorkOrder))->assertForbidden();
        $this->assertSame($sulawesi->id, $makassar->region_id);
    }

    public function test_planner_area_sulawesi_is_limited_to_sulawesi_sites(): void
    {
        [, $sulawesi, $bpn, , $makassar] = $this->regionScenario();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $sulawesi->id, 'site_id' => null]);

        $bpnWorkOrder = $this->workOrder($this->unit($bpn, 'KT 2001 AA'), $planner);
        $makassarWorkOrder = $this->workOrder($this->unit($makassar, 'DD 2002 BB'), $planner);

        $this->assertFalse($planner->can('view', $bpnWorkOrder));
        $this->assertTrue($planner->can('view', $makassarWorkOrder));

        $this->actingAs($planner)->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites.data', 1)
                ->where('sites.data.0.id', $makassar->id)
                ->where('boardColumns.open.meta.total', 1)
            );
    }

    public function test_mechanic_remains_scoped_to_single_site(): void
    {
        [, , $bpn, $smd] = $this->regionScenario();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $bpn->id, 'region_id' => null]);

        $bpnUnit = $this->unit($bpn, 'KT 3001 AA');
        $smdUnit = $this->unit($smd, 'KT 3002 BB');

        $this->assertTrue($mechanic->can('view', $bpnUnit));
        $this->assertFalse($mechanic->can('view', $smdUnit));
    }

    public function test_superadmin_and_spv_ho_can_access_all_regions(): void
    {
        [, , $bpn, , $makassar] = $this->regionScenario();
        $bpnUnit = $this->unit($bpn, 'KT 4001 AA');
        $makassarUnit = $this->unit($makassar, 'DD 4002 BB');

        foreach ([UserRole::Superadmin, UserRole::SpvHo] as $role) {
            $user = User::factory()->create(['role' => $role, 'site_id' => null, 'region_id' => null]);

            $this->assertTrue($user->can('view', $bpnUnit));
            $this->assertTrue($user->can('view', $makassarUnit));
        }
    }

    /**
     * @return array{Region, Region, Site, Site, Site}
     */
    private function regionScenario(): array
    {
        $kalimantan = Region::query()->create(['name' => 'Kalimantan']);
        $sulawesi = Region::query()->create(['name' => 'Sulawesi']);
        $bpn = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan Timur', 'region_id' => $kalimantan->id]);
        $smd = Site::query()->create(['name' => 'SMD', 'region' => 'Kalimantan Timur', 'region_id' => $kalimantan->id]);
        $makassar = Site::query()->create(['name' => 'MAKASSAR', 'region' => 'Sulawesi Selatan', 'region_id' => $sulawesi->id]);

        return [$kalimantan, $sulawesi, $bpn, $smd, $makassar];
    }

    private function unit(Site $site, string $plate): Unit
    {
        return Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT NAJ',
            'current_plate' => $plate,
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]);
    }

    private function workOrder(Unit $unit, User $submittedBy): WorkOrder
    {
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service '.$unit->current_plate,
            'interval_km' => 10000,
            'interval_days' => 30,
        ]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subMonth()->toDateString(),
            'next_due_km' => 10000,
            'next_due_date' => now()->addWeek()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'manual',
            'status' => 'open',
            'submitted_by' => $submittedBy->id,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'replace',
            'status' => 'in_progress',
        ]);

        return $workOrder;
    }
}
