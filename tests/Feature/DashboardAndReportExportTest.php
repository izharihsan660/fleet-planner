<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\ReportController;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DashboardAndReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_planner_area_dashboard_is_scoped_to_user_region(): void
    {
        [$insideSite, $outsideSite] = $this->createRegionScenario();
        $planner = User::factory()->create([
            'role' => UserRole::PlannerArea,
            'region_id' => $insideSite->region_id,
            'site_id' => null,
        ]);

        Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($insideSite, 'KT 1001 AA')));
        Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($outsideSite, 'KT 9999 ZZ')));

        $this->createItem($insideSite, 'KT 1002 AA', 'on_hold');
        $this->createItem($insideSite, 'KT 1003 AA', 'replace');
        $this->createItem($insideSite, 'KT 1004 AA', 'in_progress');
        $this->createItem($insideSite, 'KT 1005 AA', 'complete', now()->toDateString());
        $this->createItem($insideSite, 'KT 1006 AA', 'overdue');
        $this->createItem($outsideSite, 'KT 9001 ZZ', 'overdue');

        $this->actingAs($planner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('plannerDashboard.total_units', 6)
                ->where('plannerDashboard.status_counts.on_hold', 1)
                ->where('plannerDashboard.status_counts.waiting_approval', 1)
                ->where('plannerDashboard.status_counts.in_progress', 1)
                ->where('plannerDashboard.status_counts.complete_this_month', 1)
                ->where('plannerDashboard.status_counts.overdue', 1)
                ->where('plannerDashboard.site_rows.0.site_name', $insideSite->name)
                ->where('plannerDashboard.site_rows.0.overdue_count', 1)
                ->has('plannerDashboard.status_chart', 5)
                ->has('plannerDashboard.overdue_by_site_chart', 1)
            );
    }

    public function test_dashboard_reports_daily_km_input_compliance_within_scope(): void
    {
        [$insideSite, $outsideSite] = $this->createRegionScenario();
        $planner = User::factory()->create([
            'role' => UserRole::PlannerArea,
            'region_id' => $insideSite->region_id,
            'site_id' => null,
        ]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $insideSite->id]);

        $inputUnit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($insideSite, 'KT 2001 AA')));
        Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($insideSite, 'KT 2002 AA')));
        $outsideUnit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($outsideSite, 'KT 2999 ZZ')));

        // Log hari ini hanya untuk satu unit di dalam region; log unit luar region tidak boleh ikut dihitung.
        foreach ([$inputUnit, $outsideUnit] as $unit) {
            \App\Models\InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => now()->toDateString(),
                'odometer' => 500,
            ]);
        }

        // Log kemarin tidak dihitung sebagai kepatuhan hari ini.
        \App\Models\InspectionLog::query()->create([
            'unit_id' => $inputUnit->id,
            'mechanic_id' => $mechanic->id,
            'inspection_date' => now()->subDay()->toDateString(),
            'odometer' => 400,
        ]);

        $this->actingAs($planner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('plannerDashboard.km_input_today.input_count', 1)
                ->where('plannerDashboard.km_input_today.total_units', 2)
                ->where('plannerDashboard.km_input_today.missing_count', 1)
                ->where('plannerDashboard.km_input_today.percentage', 50)
                ->where('plannerDashboard.site_rows.0.km_input_count', 1)
                ->where('plannerDashboard.site_rows.0.unit_count', 2)
            );
    }

    public function test_superadmin_dashboard_shows_all_regions_by_default(): void
    {
        [$firstSite, $secondSite] = $this->createRegionScenario();
        $superadmin = User::factory()->create(['role' => UserRole::Superadmin]);

        Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($firstSite, 'KT 3001 AA')));
        Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($secondSite, 'KT 3002 BB')));
        $this->createItem($firstSite, 'KT 3003 AA', 'on_hold');
        $this->createItem($secondSite, 'KT 3004 BB', 'overdue');

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('overdueBanner.count', 1)
                ->where('overdueBanner.threshold', 20)
                ->where('plannerDashboard.can_filter_region', true)
                ->where('plannerDashboard.selected_region_id', null)
                ->where('plannerDashboard.total_units', 4)
                ->where('plannerDashboard.status_counts.on_hold', 1)
                ->where('plannerDashboard.status_counts.overdue', 1)
                ->has('plannerDashboard.region_options', 2)
                ->has('plannerDashboard.site_rows', 2)
                ->has('plannerDashboard.overdue_by_site_chart', 2)
            );
    }

    public function test_spv_ho_dashboard_can_filter_to_one_region(): void
    {
        [$firstSite, $secondSite] = $this->createRegionScenario();
        $spvHo = User::factory()->create(['role' => UserRole::SpvHo]);
        $planner = User::factory()->create([
            'role' => UserRole::PlannerArea,
            'region_id' => $firstSite->region_id,
            'site_id' => null,
        ]);

        $this->createItem($firstSite, 'KT 3101 AA', 'on_hold');
        $this->createItem($firstSite, 'KT 3102 AA', 'overdue');
        $this->createItem($secondSite, 'KT 3103 BB', 'overdue');

        $spvResponse = $this->actingAs($spvHo)->get(route('dashboard', ['region_id' => $firstSite->region_id]));
        $plannerResponse = $this->actingAs($planner)->get(route('dashboard'));

        $spvResponse->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('plannerDashboard.can_filter_region', true)
            ->where('plannerDashboard.selected_region_id', $firstSite->region_id)
            ->where('plannerDashboard.total_units', 2)
            ->where('plannerDashboard.status_counts.on_hold', 1)
            ->where('plannerDashboard.status_counts.overdue', 1)
            ->where('plannerDashboard.site_rows.0.site_name', $firstSite->name)
        );

        $plannerResponse->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('plannerDashboard.can_filter_region', false)
            ->where('plannerDashboard.selected_region_id', $firstSite->region_id)
            ->where('plannerDashboard.total_units', 2)
            ->where('plannerDashboard.status_counts.on_hold', 1)
            ->where('plannerDashboard.status_counts.overdue', 1)
            ->where('plannerDashboard.site_rows.0.site_name', $firstSite->name)
        );
    }

    public function test_mechanic_dashboard_still_redirects_to_tasks(): void
    {
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik]);

        $this->actingAs($mechanic)
            ->get(route('dashboard'))
            ->assertRedirect(route('mechanic.tasks'));
    }

    public function test_report_excel_export_uses_active_filters(): void
    {
        [$firstSite, $secondSite] = $this->createRegionScenario();
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->createItem($firstSite, 'KT 2001 AA', 'complete', '2026-07-10');
        $this->createItem($secondSite, 'KT 2002 BB', 'complete', '2026-07-10');
        $this->createItem($firstSite, 'KT 2003 CC', 'complete', '2026-06-10');

        $response = $this->actingAs($user)->get(route('reports.export', [
            'tab' => 'wo',
            'month' => 7,
            'year' => 2026,
            'site_id' => $firstSite->id,
        ]));

        $response->assertOk()->assertDownload('laporan-rekap-wo-2026-07.xlsx');

        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        $this->assertSame(['Lokasi', 'Total WO', 'Total Item', 'Selesai', 'Terlambat', 'Sedang Dikerjakan'], $rows[0]);
        $this->assertSame($firstSite->name, $rows[1][0]);
        $this->assertSame(1, (int) $rows[1][1]);
        $this->assertCount(2, $rows);
    }

    public function test_report_by_item_export_calculates_average_completion_days(): void
    {
        [$site] = $this->createRegionScenario();
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->createItem($site, 'KT 2101 AA', 'complete', '2026-07-04', '2026-07-01');
        $this->createItem($site, 'KT 2102 AA', 'complete', '2026-07-06', '2026-07-01');

        $response = $this->actingAs($user)->get(route('reports.export', [
            'tab' => 'item',
            'month' => 7,
            'year' => 2026,
            'site_id' => $site->id,
        ]));

        $response->assertOk()->assertDownload('laporan-per-item-2026-07.xlsx');

        $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        $this->assertSame(['Item', 'Total WO', 'Selesai', 'Terlambat', 'Avg Hari Penyelesaian'], $rows[0]);
        $this->assertSame('Filter Oli', $rows[1][0]);
        $this->assertSame(2, (int) $rows[1][1]);
        $this->assertSame(4.0, (float) $rows[1][4]);
    }

    public function test_report_by_item_uses_mysql_safe_completion_days_expression(): void
    {
        $originalDefaultConnection = config('database.default');

        try {
            config(['database.default' => 'mysql']);
            DB::purge('mysql');

            $method = new \ReflectionMethod(ReportController::class, 'completionDaysExpression');

            $this->assertSame(
                'DATEDIFF(work_order_items.completed_date, DATE(work_order_items.created_at))',
                $method->invoke(new ReportController)
            );
        } finally {
            config(['database.default' => $originalDefaultConnection]);
            DB::purge('mysql');
        }
    }

    /**
     * @return array{Site, Site}
     */
    private function createRegionScenario(): array
    {
        $firstRegion = Region::query()->create(['name' => 'Region Kaltim']);
        $secondRegion = Region::query()->create(['name' => 'Region Kalsel']);

        return [
            Site::query()->create(['name' => 'Site Samarinda', 'region' => 'Kaltim', 'region_id' => $firstRegion->id]),
            Site::query()->create(['name' => 'Site Banjarmasin', 'region' => 'Kalsel', 'region_id' => $secondRegion->id]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(Site $site, string $plate): array
    {
        return [
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ];
    }

    private function createItem(Site $site, string $plate, string $status, ?string $completedDate = null, ?string $createdAt = null): WorkOrderItem
    {
        $unit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($site, $plate)));
        $planningItem = PlanningItem::query()->first() ?? PlanningItem::query()->create(['name' => 'Filter Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->updateOrCreate([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
        ], [
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(90)->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => now()->addDays(10)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => $status === 'complete' ? 'complete' : 'open',
        ]);

        if ($completedDate !== null || $createdAt !== null) {
            $workOrder->forceFill([
                'created_at' => $createdAt ?? $completedDate,
                'updated_at' => $createdAt ?? $completedDate,
            ])->save();
        }

        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => $status,
            'completed_date' => $completedDate,
        ]);

        if ($completedDate !== null || $createdAt !== null) {
            $item->forceFill([
                'created_at' => $createdAt ?? $completedDate,
                'updated_at' => $createdAt ?? $completedDate,
            ])->save();
        }

        return $item;
    }
}
