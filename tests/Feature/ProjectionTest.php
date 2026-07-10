<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InspectionLog;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\HighUsageService;
use App\Services\ProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_projection_service_groups_due_items_and_warnings(): void
    {
        [$unit, $planningItem] = $this->createProjectionScenario();

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame(1, $result['period_months']);
        $this->assertCount(1, $result['by_unit']);
        $this->assertSame($unit->current_plate, $result['by_unit'][0]['plate_number']);
        $this->assertSame($planningItem->name, $result['by_item'][0]['planning_item_name']);
        $this->assertSame($planningItem->name, $result['by_part'][0]['planning_item_name']);
        $this->assertSame(1, $result['by_part'][0]['total_estimated_quantity']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_authorized_roles_can_access_projection_page(): void
    {
        [$unit] = $this->createProjectionScenario();

        foreach ([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => $role === UserRole::PlannerArea ? $unit->site_id : null,
            ]);

            $this->actingAs($user)->get(route('projections.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Projections/Index')
                    ->has('projection.by_unit.data')
                    ->where('projection.by_unit.meta.per_page', 25)
                    ->where('filters.months', 1)
                );
        }
    }

    public function test_projection_page_source_summarizes_missing_km_warning(): void
    {
        $projectionSource = file_get_contents(resource_path('js/Pages/Projections/Index.tsx'));
        $layoutSource = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.tsx'));

        $this->assertStringContainsString('unit belum ada data KM — menunggu input Mekanik', $projectionSource);
        $this->assertStringContainsString('Lihat daftar', $projectionSource);
        $this->assertStringContainsString('Sembunyikan daftar', $projectionSource);
        $this->assertStringContainsString('max-h-80 overflow-y-auto', $projectionSource);
        $this->assertStringNotContainsString("projection.warnings.map((warning) => warning.plate_number).join(', ')", $projectionSource);
        $this->assertStringContainsString('Filter Lokasi', $projectionSource);
        $this->assertStringContainsString('Semua Lokasi', $projectionSource);
        $this->assertStringContainsString('Kalender hanya untuk monitoring', $projectionSource);
        $this->assertStringContainsString('Buka di Daftar Kerja', $projectionSource);
        $this->assertStringNotContainsString('WorkListActionPanel', $projectionSource);
        $this->assertStringNotContainsString('Panel Pengajuan', $projectionSource);
        $this->assertStringNotContainsString('Lanjutkan', $projectionSource);
        $this->assertStringContainsString("label: 'Proyeksi'", $layoutSource);
        $this->assertStringNotContainsString("label: 'Projections'", $layoutSource);
    }

    public function test_mechanic_cannot_access_projection_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::Mekanik]);

        $this->actingAs($user)->get(route('projections.index'))->assertForbidden();
    }

    public function test_unit_without_odometer_reading_keeps_current_odometer_projection(): void
    {
        [$unit] = $this->createProjectionScenario();
        $unit->update(['has_odometer_reading' => false]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame($unit->current_odo, $result['by_unit'][0]['estimated_period_odo']);
        $this->assertSame(0.0, $result['by_unit'][0]['avg_km_per_day']);
        $this->assertTrue($result['by_unit'][0]['insufficient_data']);
        $this->assertSame('Data KM belum tersedia — menunggu input mekanik', $result['by_unit'][0]['data_status_message']);
    }

    public function test_unit_with_enough_inspection_data_uses_normal_odometer_projection(): void
    {
        [$unit] = $this->createProjectionScenario(logs: [
            [now()->subDays(3), 600],
            [now()->subDays(2), 1000],
            [now()->subDay(), 1400],
            [now(), 1800],
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertFalse($result['by_unit'][0]['insufficient_data']);
        $this->assertSame(400.0, $result['by_unit'][0]['avg_km_per_day']);
        $this->assertGreaterThan($unit->current_odo, $result['by_unit'][0]['estimated_period_odo']);
        $this->assertNull($result['by_unit'][0]['data_status_message']);
    }

    public function test_projection_service_uses_rolling_30_day_window_for_long_history_units(): void
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '2']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planningItem = PlanningItem::query()->create(['name' => 'Test Item Rolling Unique', 'interval_km' => 5000, 'interval_days' => 90]);
        $unit = Unit::query()->create(['site_id' => $site->id, 'customer' => 'Customer A', 'current_plate' => 'DD 7777 AA', 'type' => 'Pickup', 'brand' => 'Toyota', 'year' => 2024, 'current_odo' => 3000, 'status' => 'active']);
        UnitPlanning::query()->updateOrCreate(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id], ['last_done_km' => 0, 'last_done_date' => now()->subDays(90)->toDateString(), 'next_due_km' => 4000, 'next_due_date' => now()->addDays(30)->toDateString()]);

        foreach ([
            [65, 0],
            [60, 500],
            [31, 1500],
            [15, 2500],
            [0, 3000],
        ] as [$daysAgo, $odo]) {
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => now()->subDays($daysAgo)->toDateString(),
                'odometer' => $odo,
            ]);
        }

        $result = app(ProjectionService::class)->calculate(1);

        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'DD 7777 AA');
        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $rollingAvg = $byUnit['avg_km_per_day'];
        $this->assertSame(round((3000 - 2500) / 15, 2), $rollingAvg);
    }

    public function test_projection_service_uses_full_history_when_unit_has_short_history(): void
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '2']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planningItem = PlanningItem::query()->create(['name' => 'Test Item Short Unique', 'interval_km' => 500, 'interval_days' => 60]);
        $unit = Unit::query()->create(['site_id' => $site->id, 'customer' => 'Customer A', 'current_plate' => 'DD 8888 AA', 'type' => 'Pickup', 'brand' => 'Toyota', 'year' => 2024, 'current_odo' => 300, 'status' => 'active']);
        UnitPlanning::query()->updateOrCreate(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id], ['last_done_km' => 0, 'last_done_date' => now()->subDays(60)->toDateString(), 'next_due_km' => 400, 'next_due_date' => now()->addDays(20)->toDateString()]);

        InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => now()->subDays(10)->toDateString(), 'odometer' => 0]);
        InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => now()->toDateString(), 'odometer' => 300]);

        $result = app(ProjectionService::class)->calculate(1);

        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'DD 8888 AA');
        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $this->assertSame(round(300 / 10, 2), $byUnit['avg_km_per_day']);
    }

    public function test_projection_excludes_blocked_days_from_effective_divisor(): void
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '2']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planningItem = PlanningItem::query()->create(['name' => 'Test Item Blocked Unique', 'interval_km' => 500, 'interval_days' => 60]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'DD 9999 AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 200,
            'status' => 'active',
        ]);

        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDays(10)->toDateString(),
            'odometer' => 0,
        ]);
        InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->toDateString(),
            'odometer' => 200,
        ]);

        $unitPlanning = UnitPlanning::query()->updateOrCreate(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id], ['last_done_km' => 0, 'last_done_date' => now()->subDays(60)->toDateString(), 'next_due_km' => 300, 'next_due_date' => now()->addDays(20)->toDateString()]);

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'blocked',
            'status' => 'blocked',
            'freeze_start' => now()->subDays(10)->startOfDay(),
            'freeze_end' => now()->subDays(8)->startOfDay(),
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'DD 9999 AA');
        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $this->assertSame(round(200 / 8, 2), $byUnit['avg_km_per_day']);
    }

    public function test_projection_counts_overlapping_freeze_days_once_per_unit(): void
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '2']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

        $site = Site::query()->create(['name' => 'Site Overlap', 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'DD 5555 AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 200,
            'status' => 'active',
        ]);

        InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => now()->subDays(10)->toDateString(), 'odometer' => 0]);
        InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => now()->toDateString(), 'odometer' => 200]);

        foreach ([['Overlap A', 10, 5], ['Overlap B', 8, 3], ['Overlap C', 7, 6]] as [$name, $startDaysAgo, $endDaysAgo]) {
            $planningItem = PlanningItem::query()->create(['name' => $name, 'interval_km' => 500, 'interval_days' => 60]);
            $unitPlanning = UnitPlanning::query()->create(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id, 'last_done_km' => 0, 'last_done_date' => now()->subDays(60)->toDateString(), 'next_due_km' => 300, 'next_due_date' => now()->addDays(20)->toDateString()]);
            $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'trigger_type' => 'normal', 'status' => 'open']);

            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $unitPlanning->id,
                'planning_item_id' => $planningItem->id,
                'action' => 'blocked',
                'status' => 'blocked',
                'freeze_start' => now()->subDays($startDaysAgo)->startOfDay(),
                'freeze_end' => now()->subDays($endDaysAgo)->startOfDay(),
            ]);
        }

        $result = app(ProjectionService::class)->calculate(1);

        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'DD 5555 AA');
        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $this->assertSame(round(200 / 3, 2), $byUnit['avg_km_per_day']);
    }

    public function test_high_usage_service_still_uses_full_history_not_rolling_window(): void
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '3']);
        SystemThreshold::query()->updateOrCreate(['key' => 'high_usage_threshold'], ['value' => '20']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '5']);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'DD 1111 BB',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1800,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Service 10K', 'interval_km' => 1000, 'interval_days' => 30]);
        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(20)->toDateString(),
            'next_due_km' => 4200,
            'next_due_date' => now()->addDays(20)->toDateString(),
        ]);

        foreach ([
            [now()->subDays(10), 0],
            [now()->subDays(5), 400],
            [now(), 1800],
        ] as $row) {
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => $row[0]->toDateString(),
                'odometer' => $row[1],
            ]);
        }

        $flags = app(HighUsageService::class)->detect($unit->refresh());

        $this->assertNotEmpty($flags);
        $this->assertSame(180.0, (float) $flags[0]->avg_km_per_day);
    }

    public function test_planner_area_is_forced_to_own_site_filter(): void
    {
        [$ownUnit] = $this->createProjectionScenario('Own Site', 'KT 8404 YR');
        [$otherUnit] = $this->createProjectionScenario('Other Site', 'KT 8620 YR');
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $ownUnit->site_id]);

        $this->actingAs($admin)->get(route('projections.index', ['site_id' => $otherUnit->site_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site_id', $ownUnit->site_id)
                ->where('projection.by_unit.data.0.unit_id', $ownUnit->id)
            );
    }

    public function test_projection_calendar_uses_effective_due_dates_and_excludes_finished_items(): void
    {
        $region = Region::query()->create(['name' => 'Kalimantan']);
        $site = Site::query()->create(['name' => 'Site Calendar', 'region' => 'Kalimantan', 'region_id' => $region->id]);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);
        $planningItem = PlanningItem::query()->create(['name' => 'Service Calendar', 'interval_km' => 1000, 'interval_days' => 30]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'KT 1234 CV',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1800,
            'status' => 'active',
        ]);
        $unitPlanning = UnitPlanning::query()->updateOrCreate([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
        ], [
            'last_done_km' => 1000,
            'last_done_date' => '2026-06-01',
            'next_due_km' => 4200,
            'next_due_date' => '2026-07-15',
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
            'scheduled_date' => '2026-07-20',
        ]);

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'postpone',
            'status' => 'postponed',
            'new_due_date' => '2026-07-25',
            'approved_at' => '2026-07-10 08:00:00',
            'triggered_by_high_usage' => true,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'complete',
        ]);

        $this->actingAs($user)->get(route('projections.index', ['month' => '2026-07', 'region_id' => $region->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.month', '2026-07')
                ->where('filters.region_id', $region->id)
                ->where('calendar.summary_by_date.2026-07-25.total', 1)
                ->where('calendar.summary_by_date.2026-07-25.high_usage', 1)
                ->where('calendar.items.0.due_date', '2026-07-25')
                ->where('calendar.items.0.is_high_usage', true)
                ->where('calendar.items.0.status_label', 'Postponed')
            );
    }

    /**
     * @return array{0: Unit, 1: PlanningItem}
     */
    private function createProjectionScenario(string $siteName = 'Site Test', string $plateNumber = 'KT 8404 YR', ?array $logs = null): array
    {
        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '4']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

        $site = Site::query()->create(['name' => $siteName, 'region' => 'Region Test']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => $plateNumber,
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1800,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Ban 265/70 R17 '.$plateNumber,
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);

        UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => now()->subDays(20)->toDateString(),
            'next_due_km' => 4200,
            'next_due_date' => now()->addDays(20)->toDateString(),
        ]);

        collect($logs ?? [
            [now()->subDays(2), 1000],
            [now()->subDay(), 1400],
            [now(), 1800],
        ])->each(fn (array $log): InspectionLog => InspectionLog::query()->create([
            'unit_id' => $unit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => $log[0]->toDateString(),
            'odometer' => $log[1],
        ]));

        return [$unit, $planningItem];
    }
}
