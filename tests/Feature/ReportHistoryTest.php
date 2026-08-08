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
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_superadmin_can_view_report_sections(): void
    {
        $this->createReportScenario();
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->has('summary')
                ->has('woSummary')
                ->has('byItem')
                ->has('byUnit')
                ->has('overdueByArea')
                ->has('baselineIncomplete')
                ->where('permissions.can_view_by_item', true)
            );
    }

    public function test_overdue_reports_exclude_items_with_missing_baseline(): void
    {
        [$site, $unit] = $this->createReportScenario();
        $knownPlanning = UnitPlanning::query()->where('unit_id', $unit->id)->firstOrFail();
        WorkOrderItem::query()->where('unit_planning_id', $knownPlanning->id)->where('status', 'on_hold')->update(['status' => 'overdue']);

        $missingItem = PlanningItem::query()->create([
            'name' => 'Baseline Kosong',
            'interval_km' => 5000,
            'interval_days' => 90,
        ]);
        $missingPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $missingItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 5000,
            'next_due_date' => today()->subDays(83)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $missingPlanning->id,
            'planning_item_id' => $missingItem->id,
            'status' => 'overdue',
        ]);
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($user)
            ->get(route('reports.index', [
                'month' => now()->month,
                'year' => now()->year,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_overdue', 1)
                ->where('woSummary.data.0.overdue', 1)
                ->where('byItem.data', fn (Collection $rows): bool => $rows->sum('total_overdue') === 1)
                ->where('byUnit.data.0.total_overdue', 1)
                ->where('overdueByArea.data.0.total_overdue', 1)
                ->where('overdueByArea.data.0.items', fn (Collection $items): bool => ! $items->contains('Baseline Kosong'))
            );
    }

    public function test_spv_ho_can_view_all_report_sections(): void
    {
        $this->createReportScenario();
        $user = User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($user)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_view_wo_summary', true)
                ->where('permissions.can_view_by_item', true)
                ->where('permissions.can_view_by_unit', true)
                ->where('permissions.default_tab', 'wo')
            );
    }

    public function test_report_page_access_matches_role_tabs(): void
    {
        [$site] = $this->createReportScenario();

        $expectations = [
            UserRole::Superadmin->value => [true, true, true, true],
            UserRole::SpvHo->value => [true, true, true, true],
            UserRole::PlannerArea->value => [true, true, true, true],
            UserRole::Mekanik->value => [true, true, true, true],
        ];

        foreach ($expectations as $role => [$woSummary, $byItem, $byUnit, $overdue]) {
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => in_array($role, [UserRole::PlannerArea->value, UserRole::Mekanik->value], true) ? $site->id : null,
            ]);

            $this->actingAs($user)->get(route('reports.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('permissions.can_view_wo_summary', $woSummary)
                    ->where('permissions.can_view_by_item', $byItem)
                    ->where('permissions.can_view_by_unit', $byUnit)
                    ->where('permissions.can_view_overdue', $overdue)
                );
        }
    }

    public function test_baseline_incomplete_report_groups_items_per_unit_and_scopes_planner_region(): void
    {
        $ownRegion = Region::query()->create(['name' => 'Sulawesi']);
        $otherRegion = Region::query()->create(['name' => 'Kalimantan']);
        $ownSite = Site::query()->create(['name' => 'Site Own', 'region' => 'Sulawesi', 'region_id' => $ownRegion->id]);
        $otherSite = Site::query()->create(['name' => 'Site Other', 'region' => 'Kalimantan', 'region_id' => $otherRegion->id]);
        $ownUnit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($ownSite->id, 'DD 1153 EKX')));
        $otherUnit = Unit::withoutEvents(fn () => Unit::query()->create($this->unitPayload($otherSite->id, 'KT 9999 ZZ')));
        $firstItem = PlanningItem::query()->create(['name' => 'Filter Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $secondItem = PlanningItem::query()->create(['name' => 'Brake Pad', 'interval_km' => 5000, 'interval_days' => 90]);
        $excludedItem = PlanningItem::query()->create(['name' => 'Tidak Berlaku', 'interval_km' => 5000, 'interval_days' => 90]);

        foreach ([$firstItem, $secondItem] as $planningItem) {
            UnitPlanning::query()->create([
                'unit_id' => $ownUnit->id,
                'planning_item_id' => $planningItem->id,
                'last_done_km' => 0,
                'last_done_date' => null,
            ]);
        }

        UnitPlanning::query()->create([
            'unit_id' => $ownUnit->id,
            'planning_item_id' => $excludedItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'is_excluded' => true,
        ]);
        UnitPlanning::query()->create([
            'unit_id' => $otherUnit->id,
            'planning_item_id' => $firstItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
        ]);

        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $ownRegion->id]);

        $this->actingAs($planner)
            ->get(route('reports.index', ['tab' => 'baseline']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_view_baseline', true)
                ->where('permissions.default_tab', 'baseline')
                ->has('baselineIncomplete.data', 1)
                ->where('baselineIncomplete.data.0.unit_id', $ownUnit->id)
                ->where('baselineIncomplete.data.0.plat_nomor', 'DD 1153 EKX')
                ->where('baselineIncomplete.data.0.site', 'Site Own')
                ->where('baselineIncomplete.data.0.missing_baseline_count', 2)
                ->where('baselineIncomplete.data.0.items', fn ($items) => collect($items)->sort()->values()->all() === ['Brake Pad', 'Filter Oli'])
            );
    }

    public function test_mechanic_reports_are_scoped_to_units_they_handled(): void
    {
        [$site, $handledUnit] = $this->createReportScenario();
        $otherUnit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'KT 9999 ZZ',
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2023,
            'current_odo' => 7000,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->firstOrFail();
        $otherPlanning = UnitPlanning::query()->where('unit_id', $otherUnit->id)->where('planning_item_id', $planningItem->id)->firstOrFail();
        $otherPlanning->update([
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(90)->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => now()->addDays(10)->toDateString(),
        ]);
        $otherWorkOrder = WorkOrder::query()->create(['unit_id' => $otherUnit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'in_progress']);

        WorkOrderItem::query()->create([
            'work_order_id' => $otherWorkOrder->id,
            'unit_planning_id' => $otherPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'complete',
            'submitted_by' => User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id])->id,
        ]);

        $mechanic = WorkOrderItem::query()->where('work_order_id', '!=', $otherWorkOrder->id)->whereNotNull('submitted_by')->firstOrFail()->submittedBy;

        $this->actingAs($mechanic)->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_items', 1)
                ->has('byUnit.data', 1)
                ->where('byUnit.data.0.plat_nomor', $handledUnit->current_plate)
                ->where('byUnit.meta.per_page', 25)
            );
    }

    public function test_unit_history_contains_replacements_plate_blocked_and_postpone(): void
    {
        [$site, $unit] = $this->createReportScenario();
        $unit->plateHistories()->create([
            'plate_number' => 'KT 1001 AA',
            'active_from' => now()->subYear()->toDateString(),
        ]);
        $user = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($user)->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Units/History')
                ->where('history.unit.id', $unit->id)
                ->has('history.replacements.data', 1)
                ->where('history.replacements.meta.per_page', 25)
                ->has('history.plate_histories.data', 2)
                ->has('history.blocked_breakdowns.data', 1)
                ->has('history.postpones.data', 1)
            );
    }

    public function test_check_overdue_command_marks_items_and_notifies_roles(): void
    {
        $this->createReportScenario();
        User::factory()->create(['role' => UserRole::SpvHo]);
        User::factory()->create(['role' => UserRole::SpvHo]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(1, WorkOrderItem::query()->where('status', 'overdue')->count());
        $this->assertSame(2, Notification::query()->where('type', 'maintenance_overdue')->count());
    }

    public function test_check_overdue_command_notifies_existing_overdue_items(): void
    {
        $this->createReportScenario();
        User::factory()->create(['role' => UserRole::SpvHo]);
        User::factory()->create(['role' => UserRole::SpvHo]);

        $item = WorkOrderItem::query()->firstOrFail();
        $item->update(['status' => 'overdue']);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(2, Notification::query()
            ->where('type', 'maintenance_overdue')
            ->where('data->unit_id', $item->workOrder->unit_id)
            ->where('data->planning_item_id', $item->planning_item_id)
            ->count());
    }

    public function test_check_overdue_command_groups_same_unit_and_item_notifications(): void
    {
        [$site] = $this->createReportScenario();
        User::factory()->create(['role' => UserRole::SpvHo]);

        $firstItem = WorkOrderItem::query()->where('status', 'on_hold')->firstOrFail();
        $secondWorkOrder = WorkOrder::query()->create([
            'unit_id' => $firstItem->workOrder->unit_id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
        ]);

        WorkOrderItem::query()->create([
            'work_order_id' => $secondWorkOrder->id,
            'unit_planning_id' => $firstItem->unit_planning_id,
            'planning_item_id' => $firstItem->planning_item_id,
            'status' => 'in_progress',
        ]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(2, WorkOrderItem::query()->where('status', 'overdue')->count());
        $this->assertSame(1, Notification::query()->where('type', 'maintenance_overdue')->count());

        Notification::query()
            ->where('type', 'maintenance_overdue')
            ->get()
            ->each(function (Notification $notification): void {
                $this->assertSame(2, $notification->data['overdue_count']);
                $this->assertStringContainsString('2 WO menunggu tindakan', $notification->message);
            });
    }

    public function test_check_overdue_command_does_not_mark_null_due_date_when_km_is_below_due(): void
    {
        [$unit, $item] = $this->createNullDueDateScenario(currentOdo: 0, nextDueKm: 10000);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(0, $unit->refresh()->current_odo);
        $this->assertSame('on_hold', $item->refresh()->status);
    }

    public function test_check_overdue_command_ignores_missing_baseline_when_km_reaches_due(): void
    {
        [, $item] = $this->createNullDueDateScenario(currentOdo: 12000, nextDueKm: 10000);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('on_hold', $item->refresh()->status);
    }

    public function test_check_overdue_command_restores_stale_overdue_with_null_due_date_and_km_below_due(): void
    {
        [, $item] = $this->createNullDueDateScenario(currentOdo: 0, nextDueKm: 10000, status: 'overdue');

        $this->artisan('maintenance:check-overdue --dry-run')
            ->assertSuccessful()
            ->expectsOutput('0 work order item akan ditandai overdue.')
            ->expectsOutput('1 work order item overdue stale akan dikembalikan ke on_hold.');

        $this->assertSame('overdue', $item->refresh()->status);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('on_hold', $item->refresh()->status);
    }

    /**
     * @return array{0: Site, 1: Unit}
     */
    private function createReportScenario(): array
    {
        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Region Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'KT 1234 AA',
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2023,
            'current_odo' => 6000,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Ganti Oli', 'interval_km' => 5000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(120)->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => now()->subDay()->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'in_progress']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'replace',
            'status' => 'complete',
            'completed_odo' => 5000,
            'completed_date' => now()->subDay()->toDateString(),
            'submitted_by' => $mechanic->id,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => 'on_hold',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'blocked',
            'status' => 'blocked',
            'reason' => 'Menunggu part',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'action' => 'postpone',
            'status' => 'postponed',
            'reason' => 'Unit masih operasi',
        ]);

        return [$site, $unit];
    }

    /**
     * @return array{0: Unit, 1: WorkOrderItem}
     */
    private function createNullDueDateScenario(int $currentOdo, int $nextDueKm, string $status = 'on_hold'): array
    {
        $site = Site::query()->create(['name' => 'Site Null Due', 'region' => 'Region Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'KT 8994 YS',
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2023,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => 'Filter Oli', 'interval_km' => 10000, 'interval_days' => 90]);
        $unitPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => $nextDueKm,
            'next_due_date' => null,
        ]);
        $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $site->id, 'trigger_type' => 'normal', 'status' => 'open']);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $unitPlanning->id,
            'planning_item_id' => $planningItem->id,
            'status' => $status,
        ]);

        return [$unit, $item];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId, string $plateNumber): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'Customer Baseline',
            'current_plate' => $plateNumber,
            'type' => 'Dump Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 10000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ];
    }
}
