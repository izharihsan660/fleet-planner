<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Database\Seeders\PlanningItemSeeder;
use Database\Seeders\SystemThresholdSeeder;
use Database\Seeders\UnitPlanningSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InspectionInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_planning_seeder_creates_records_for_each_unit_and_planning_item(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        Unit::query()->create($this->unitPayload($site->id));

        $this->seed([PlanningItemSeeder::class, UnitPlanningSeeder::class]);

        $this->assertSame(20, PlanningItem::query()->count());
        $this->assertSame(20, UnitPlanning::query()->count());
    }

    public function test_mechanic_can_record_daily_odometer_and_updates_unit_average(): void
    {
        $this->seed([SystemThresholdSeeder::class, PlanningItemSeeder::class]);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $this->seed(UnitPlanningSeeder::class);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDays(2)->toDateString(),
            'odometer' => 150,
            'previous_odo' => 100,
        ]);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 250,
            'previous_odo' => 150,
        ]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 350,
        ])->assertRedirect(route('inspections.create'));

        $unit->refresh();

        $this->assertSame(350, $unit->current_odo);
        $this->assertSame(100, $unit->avg_km_per_day);
        $this->assertSame(3, InspectionLog::query()->where('unit_id', $unit->id)->count());
        $this->assertSame(20, $unit->unitPlannings()->count());
    }

    public function test_input_km_submission_ignores_manipulated_inspection_date_and_uses_today(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->subDays(10)->toDateString(),
            'odometer' => 150,
        ])->assertRedirect(route('inspections.create'));

        $log = InspectionLog::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->assertSame(today()->toDateString(), $log->inspection_date->toDateString());
        $this->assertSame(150, $log->odometer);

        $this->assertDatabaseMissing('inspection_logs', [
            'unit_id' => $unit->id,
            'inspection_date' => now()->subDays(10)->toDateString(),
            'odometer' => 150,
        ]);
    }

    public function test_mechanic_input_km_flow_redirects_back_to_simple_input_page(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)
            ->get(route('inspections.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Create')
                ->has('units.data', 1)
                ->where('units.data.0.id', $unit->id)
            );

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 150,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(150, $unit->refresh()->current_odo);
    }

    public function test_mechanic_input_km_validation_uses_simple_indonesian_message(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 100,
        ])->assertSessionHasErrors([
            'odometer' => 'KM harus lebih besar dari 100. Coba cek lagi ya.',
        ]);

        $this->assertSame(100, $unit->refresh()->current_odo);
    }

    public function test_same_day_input_is_rejected_until_today_log_is_cancelled(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 200,
        ])->assertRedirect(route('inspections.create'));

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 250,
        ])->assertSessionHasErrors([
            'unit_id' => 'Unit ini sudah diinput hari ini. Batalkan dulu kalau mau mengulang.',
        ]);

        $this->assertSame(1, InspectionLog::query()->where('unit_id', $unit->id)->count());
        $this->assertSame(200, InspectionLog::query()->where('unit_id', $unit->id)->value('odometer'));
        $this->assertSame(200, $unit->refresh()->current_odo);
    }

    public function test_regular_km_input_unfreezes_breakdown_unit_and_shifts_frozen_due_dates(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $unit->update(['status' => 'breakdown']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planningItem = PlanningItem::query()->create(['name' => 'Service Breakdown', 'interval_km' => 1000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subMonth()->toDateString(),
            'next_due_km' => 1000,
            'next_due_date' => now()->addMonth()->toDateString(),
            'freeze_start' => now()->subDays(3),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'breakdown',
            'action' => 'breakdown',
            'freeze_start' => now()->subDays(3),
        ]);
        $expectedDueDate = now()->addMonth()->addDays(3)->toDateString();

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 200,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame('active', $unit->refresh()->status);
        $this->assertSame('breakdown', $item->refresh()->status);
        $this->assertNotNull($item->freeze_end);
        $this->assertNull($unitPlanning->refresh()->freeze_start);
        $this->assertSame($expectedDueDate, $unitPlanning->next_due_date->toDateString());
        $this->assertSame(200, $unit->current_odo);
        $this->assertSame(1, InspectionLog::query()->where('unit_id', $unit->id)->count());
    }

    public function test_mechanic_input_page_hides_units_already_input_today(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $inputTodayUnit = Unit::query()->create($this->unitPayload($site->id, 100));
        $availableUnit = Unit::query()->create($this->unitPayload($site->id, 200));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        InspectionLog::query()->create([
            'unit_id' => $inputTodayUnit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 150,
        ]);

        $this->actingAs($mechanic)
            ->get(route('inspections.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Create')
                ->has('units.data', 1)
                ->where('units.data.0.id', $availableUnit->id)
            );
    }

    public function test_mechanic_can_cancel_own_today_input_and_reinput_unit(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 100,
        ]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 180,
        ])->assertRedirect(route('inspections.create'));

        $todayLog = InspectionLog::query()->where('unit_id', $unit->id)->whereDate('inspection_date', now()->toDateString())->firstOrFail();

        $this->actingAs($mechanic)->delete(route('inspections.cancel-today', $todayLog))
            ->assertRedirect(route('inspections.index'));

        $this->assertModelMissing($todayLog);
        $this->assertSame(100, $unit->refresh()->current_odo);

        $this->actingAs($mechanic)
            ->get(route('inspections.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Create')
                ->has('units.data', 1)
                ->where('units.data.0.id', $unit->id)
            );
    }

    public function test_cancel_first_today_input_restores_unit_previous_odometer(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 123));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 180,
        ])->assertRedirect(route('inspections.create'));

        $todayLog = InspectionLog::query()->where('unit_id', $unit->id)->whereDate('inspection_date', now()->toDateString())->firstOrFail();

        $this->assertSame(123, $todayLog->previous_odo);
        $this->assertSame(180, $unit->refresh()->current_odo);

        $this->actingAs($mechanic)->delete(route('inspections.cancel-today', $todayLog))
            ->assertRedirect(route('inspections.index'));

        $this->assertSame(123, $unit->refresh()->current_odo);
    }

    public function test_mechanic_cannot_cancel_other_mechanics_or_previous_day_input(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $otherMechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $otherLog = InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $otherMechanic->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 150,
        ]);
        $yesterdayLog = InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 120,
        ]);

        $this->actingAs($mechanic)->delete(route('inspections.cancel-today', $otherLog))->assertForbidden();
        $this->actingAs($mechanic)->delete(route('inspections.cancel-today', $yesterdayLog))->assertForbidden();

        $this->assertModelExists($otherLog);
        $this->assertModelExists($yesterdayLog);
    }

    public function test_cancel_today_input_recalculates_triggers_without_stale_work_order_items(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planningItem = PlanningItem::query()->create(['name' => 'Service Test', 'interval_km' => 1000, 'interval_days' => 90]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subMonth()->toDateString(),
            'next_due_km' => 1000,
            'next_due_date' => now()->addMonths(6)->toDateString(),
        ]);
        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 100,
        ]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 600,
        ])->assertRedirect(route('inspections.create'));

        $this->assertSame(1, WorkOrderItem::query()->where('status', 'on_hold')->count());
        $todayLog = InspectionLog::query()->whereDate('inspection_date', now()->toDateString())->firstOrFail();

        $this->actingAs($mechanic)->delete(route('inspections.cancel-today', $todayLog))
            ->assertRedirect(route('inspections.index'));

        $this->assertSame(100, $unit->refresh()->current_odo);
        $this->assertSame(0, WorkOrderItem::query()->count());
        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_mechanic_cannot_record_unit_from_other_site(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $otherSite = Site::query()->create(['name' => 'Site Other', 'region' => 'Region Other']);
        $unit = Unit::query()->create($this->unitPayload($otherSite->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 200,
        ])->assertForbidden();
    }

    public function test_inspection_index_returns_resource_collection_props(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 100,
        ]);

        $this->actingAs($mechanic)
            ->get(route('inspections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Index')
                ->has('inspectionLogs.data', 1)
                ->where('inspectionLogs.meta.per_page', 50)
                ->has('units.data', 1)
            );
    }

    public function test_inspection_index_is_paginated_to_fifty_rows_per_page(): void
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        foreach (range(1, 55) as $day) {
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => now()->subDays($day)->toDateString(),
                'odometer' => 100 + $day,
            ]);
        }

        $this->actingAs($mechanic)
            ->get(route('inspections.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Index')
                ->has('inspectionLogs.data', 50)
                ->where('inspectionLogs.meta.current_page', 1)
                ->where('inspectionLogs.meta.per_page', 50)
                ->where('inspectionLogs.meta.total', 55)
            );

        $this->actingAs($mechanic)
            ->get(route('inspections.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Index')
                ->has('inspectionLogs.data', 5)
                ->where('inspectionLogs.meta.current_page', 2)
                ->where('inspectionLogs.meta.total', 55)
            );
    }

    public function test_inspection_create_returns_units_resource_collection_prop(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        Unit::query()->create($this->unitPayload($site->id, 100));
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($mechanic)
            ->get(route('inspections.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inspections/Create')
                ->has('units.data', 1)
                ->has('today')
                ->has('minimumInspectionData')
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId, int $currentOdo = 0): array
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
