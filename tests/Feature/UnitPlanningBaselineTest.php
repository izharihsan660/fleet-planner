<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitPlanningBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_all_unit_access_roles_can_set_a_missing_baseline(): void
    {
        $site = Site::query()->create(['name' => 'Site Baseline', 'region' => 'Sulawesi']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));

        foreach ([UserRole::Mekanik, UserRole::PlannerArea, UserRole::SpvHo, UserRole::Superadmin] as $index => $role) {
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => in_array($role, [UserRole::Mekanik, UserRole::PlannerArea], true) ? $site->id : null,
            ]);
            $planningItem = PlanningItem::query()->create([
                'name' => 'Baseline '.$role->value,
                'interval_km' => 100000,
                'interval_days' => 365,
            ]);
            $unitPlanning = UnitPlanning::query()->create([
                'unit_id' => $unit->id,
                'planning_item_id' => $planningItem->id,
                'last_done_km' => 0,
                'last_done_date' => null,
                'next_due_km' => 100000,
                'next_due_date' => today()->subDay()->toDateString(),
            ]);

            $this->actingAs($user)
                ->get(route('units.history', $unit))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('history.can_set_baseline', true)
                    ->where("history.planning_items.{$index}.last_done_date", null));

            $lastDoneKm = 1000 + $index;

            $this->actingAs($user)
                ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                    'last_done_km' => $lastDoneKm,
                    'last_done_date' => today()->toDateString(),
                ])
                ->assertRedirect()
                ->assertSessionHas('status');

            $unitPlanning->refresh();

            $this->assertSame($lastDoneKm, $unitPlanning->last_done_km);
            $this->assertSame(today()->toDateString(), $unitPlanning->last_done_date?->toDateString());
            $this->assertSame($lastDoneKm + 100000, $unitPlanning->next_due_km);
            $this->assertSame(today()->addDays(365)->toDateString(), $unitPlanning->next_due_date?->toDateString());
            $this->assertFalse($unitPlanning->is_estimated);
        }
    }

    public function test_setting_baseline_immediately_reenables_normal_triggering(): void
    {
        $site = Site::query()->create(['name' => 'Site Trigger', 'region' => 'Sulawesi']);
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site->id)));
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 500, 'interval_days' => 30]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 500,
            'next_due_date' => today()->subDay()->toDateString(),
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($planner)
            ->patch(route('units.plannings.baseline.update', [$unit, $unitPlanning]), [
                'last_done_km' => 1000,
                'last_done_date' => today()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('work_orders', [
            'unit_id' => $unit->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $unitPlanning->id,
            'status' => 'on_hold',
        ]);
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(1, WorkOrderItem::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'PT Baseline',
            'current_plate' => 'DD 1153 EKX',
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 1000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ];
    }
}
