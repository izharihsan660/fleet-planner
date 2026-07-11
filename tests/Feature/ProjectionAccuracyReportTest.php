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
use App\Services\ProjectionAccuracyService;
use Database\Seeders\SystemThresholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Golden scenario laporan akurasi proyeksi. Unit berjalan tepat 100 km/hari,
 * sehingga setiap angka bisa dihitung tangan:
 *
 * Rapor rumus: prediksi = tanggal task dibuat + ceil(sisa_km / avg),
 * dibandingkan tanggal log odometer pertama yang menyentuh due KM.
 * Rapor eksekusi: due -> selesai, diurai menunggu-approval dan eksekusi.
 */
class ProjectionAccuracyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_formula_report_matches_hand_calculated_deviation(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        [$site, $unit, $planning] = $this->makeUnitWithPlanning();
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        // Log harian 21 hari terakhir: tepat 100 km/hari (hari -20 odo 0 ... hari 0 odo 2000).
        for ($i = 20; $i >= 0; $i--) {
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => now()->subDays($i)->toDateString(),
                'odometer' => (20 - $i) * 100,
            ]);
        }

        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'complete']);

        // Task dibuat hari -10 (odo saat itu 1000), due KM 2000.
        // Prediksi: hari -10 + ceil(1000/100) = hari 0. KM 2000 tercapai di log hari 0.
        // Meleset = 0 hari.
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'complete',
            'action' => 'replace',
            'previous_due_km' => 2000,
            'previous_due_date' => now()->subDays(5)->toDateString(),
            'completed_date' => now()->toDateString(),
            'completed_odo' => 2000,
            'approved_at' => now()->subDays(8),
        ]);
        $item->created_at = now()->subDays(10);
        $item->save();

        $report = app(ProjectionAccuracyService::class)->report((int) now()->month, (int) now()->year, null, $spv);

        $this->assertSame(1, $report['formula']['evaluated']);
        $this->assertSame(0, $report['formula']['not_measurable']);
        $this->assertSame(0.0, $report['formula']['avg_deviation_days']);
        $this->assertSame(100, $report['formula']['within_week_pct']);
        $this->assertSame(0.0, $report['formula']['rows'][0]['avg_deviation_days']);

        // Rapor eksekusi: due hari -5, selesai hari 0 -> telat +5.
        // Dibuat hari -10, approve hari -8 -> menunggu approval 2 hari; approve -> selesai 8 hari.
        $this->assertSame(1, $report['execution']['evaluated']);
        $execution = $report['execution']['rows'][0];
        $this->assertSame(5.0, $execution['avg_late_days']);
        $this->assertSame(2.0, $execution['avg_approval_days']);
        $this->assertSame(8.0, $execution['avg_execution_days']);
    }

    public function test_formula_report_detects_slowdown_and_skips_unmeasurable_items(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        [$site, $unit, $planning] = $this->makeUnitWithPlanning();
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        // Hari -20 s/d -10: 100 km/hari (odo 0 -> 1000). Setelah task dibuat unit melambat:
        // hari -9 s/d 0: 50 km/hari (odo 1050 -> 1500).
        for ($i = 20; $i >= 10; $i--) {
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => now()->subDays($i)->toDateString(),
                'odometer' => (20 - $i) * 100,
            ]);
        }

        for ($i = 9; $i >= 0; $i--) {
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => now()->subDays($i)->toDateString(),
                'odometer' => 1000 + (10 - $i) * 50,
            ]);
        }

        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'complete']);

        // Task dibuat hari -10 (avg saat itu 100 km/hari, odo 1000), due KM 1500.
        // Prediksi: hari -10 + ceil(500/100) = hari -5. Kenyataannya unit melambat,
        // KM 1500 baru tercapai hari 0 -> meleset +5 hari.
        $measured = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'complete',
            'action' => 'replace',
            'previous_due_km' => 1500,
            'completed_date' => now()->toDateString(),
            'completed_odo' => 1500,
        ]);
        $measured->created_at = now()->subDays(10);
        $measured->save();

        // Item tanpa previous_due_km tidak bisa diukur -> masuk hitungan not_measurable.
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'complete',
            'action' => 'replace',
            'completed_date' => now()->toDateString(),
            'completed_odo' => 1500,
        ]);

        $report = app(ProjectionAccuracyService::class)->report((int) now()->month, (int) now()->year, null, $spv);

        $this->assertSame(1, $report['formula']['evaluated']);
        $this->assertSame(1, $report['formula']['not_measurable']);
        $this->assertSame(5.0, $report['formula']['avg_deviation_days']);
        $this->assertSame(100, $report['formula']['within_week_pct']);
    }

    public function test_reports_page_exposes_accuracy_tab(): void
    {
        $this->seed(SystemThresholdSeeder::class);

        $spv = User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($spv)
            ->get(route('reports.index', ['tab' => 'accuracy']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('permissions.can_view_accuracy', true)
                ->where('permissions.default_tab', 'accuracy')
                ->has('accuracy.formula')
                ->has('accuracy.execution')
            );
    }

    /**
     * @return array{Site, Unit, UnitPlanning}
     */
    private function makeUnitWithPlanning(): array
    {
        $site = Site::query()->create(['name' => 'Site Akurasi', 'region' => 'Region Test']);

        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'QA Akurasi',
            'current_plate' => 'QA 3000 AC',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 0,
            'status' => 'active',
        ]);

        $planningItem = PlanningItem::query()->create(['name' => 'Akurasi Item', 'interval_km' => 1000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
            ['last_done_km' => 0, 'last_done_date' => now()->subDays(30)->toDateString(), 'next_due_km' => 1000, 'next_due_date' => null],
        );

        return [$site, $unit, $planning];
    }
}
