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
use Carbon\CarbonImmutable;
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

    public function test_projection_includes_items_with_zero_baseline_km_when_date_is_due(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);
        $unitPlanning->update([
            'last_done_km' => 0,
            'last_done_date' => now()->subDays(20)->toDateString(),
            'next_due_km' => 2000,
            'due_manually_set' => false,
            'is_estimated' => false,
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame(0, $result['excluded_date_without_source_count']);

        foreach (['by_unit', 'by_item', 'by_part'] as $group) {
            $this->assertCount(1, $result[$group]);
            $item = $result[$group][0]['items'][0];
            $this->assertSame('date', $item['trigger']);
            $this->assertTrue($item['baseline_km_missing']);
            $this->assertSame(now()->addDays(20)->toDateString(), $item['estimated_due_date']);
        }
    }

    public function test_projection_includes_manually_set_date_at_period_end_without_baseline_km(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();

        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        foreach ([0, 0] as $lastDoneKm) {
            $unitPlanning->update([
                'last_done_km' => $lastDoneKm,
                'last_done_date' => null,
                'next_due_km' => null,
                'next_due_date' => now()->addDays(30)->toDateString(),
                'due_manually_set' => true,
                'is_estimated' => false,
            ]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertCount(1, $result['by_unit']);
            $item = $result['by_unit'][0]['items'][0];
            $this->assertSame('date', $item['trigger']);
            $this->assertTrue($item['baseline_km_missing']);
            $this->assertTrue($item['insufficient_data']);
            $this->assertSame($result['period_end'], $item['estimated_due_date']);
            $this->assertSame(0, $result['excluded_date_without_source_count']);
        }
    }

    public function test_projection_excludes_and_counts_dates_without_source_when_km_baseline_is_missing(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();

        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        foreach ([0, 0] as $lastDoneKm) {
            $unitPlanning->update([
                'last_done_km' => $lastDoneKm,
                'last_done_date' => null,
                'next_due_km' => 5000,
                'next_due_date' => now()->addDays(20)->toDateString(),
                'due_manually_set' => false,
                'is_estimated' => false,
            ]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertSame([], $result['by_unit']);
            $this->assertSame([], $result['by_item']);
            $this->assertSame([], $result['by_part']);
            $this->assertSame(1, $result['excluded_date_without_source_count']);
        }
    }

    public function test_projection_accepts_estimated_date_source_without_km_baseline(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();

        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        foreach ([0, 0] as $lastDoneKm) {
            $unitPlanning->update([
                'last_done_km' => $lastDoneKm,
                'last_done_date' => null,
                'next_due_km' => 5000,
                'next_due_date' => now()->addDays(20)->toDateString(),
                'due_manually_set' => false,
                'is_estimated' => true,
            ]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertCount(1, $result['by_unit']);
            $item = $result['by_unit'][0]['items'][0];
            $this->assertSame('date', $item['trigger']);
            $this->assertTrue($item['baseline_km_missing']);
            $this->assertSame(now()->addDays(20)->toDateString(), $item['estimated_due_date']);
            $this->assertSame(0, $result['excluded_date_without_source_count']);
        }
    }

    public function test_projection_preserves_positive_km_baseline_dates_without_date_source(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);

        foreach ([[null, 'date', 20], [4200, 'both', 6]] as [$nextDueKm, $trigger, $estimatedDateOffset]) {
            $unitPlanning->update([
                'last_done_km' => 1000,
                'last_done_date' => null,
                'next_due_km' => $nextDueKm,
                'next_due_date' => now()->addDays(20)->toDateString(),
                'due_manually_set' => false,
                'is_estimated' => false,
            ]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertCount(1, $result['by_unit']);
            $item = $result['by_unit'][0]['items'][0];
            $this->assertSame($trigger, $item['trigger']);
            $this->assertFalse($item['baseline_km_missing']);
            $this->assertSame(now()->addDays($estimatedDateOffset)->toDateString(), $item['estimated_due_date']);
            $this->assertSame(0, $result['excluded_date_without_source_count']);
        }
    }

    public function test_projection_exclusion_summary_uses_site_region_and_period_filters_and_ignores_excluded_items(): void
    {
        [$firstUnit, , $firstPlanning] = $this->createProjectionScenario('First Site', 'KT 8404 YR');
        [$secondUnit, , $secondPlanning] = $this->createProjectionScenario('Second Site', 'KT 8620 YR');
        $firstRegion = Region::query()->create(['name' => 'First Region']);
        $secondRegion = Region::query()->create(['name' => 'Second Region']);
        $firstUnit->site->update(['region_id' => $firstRegion->id]);
        $secondUnit->site->update(['region_id' => $secondRegion->id]);

        foreach ([$firstPlanning, $secondPlanning] as $unitPlanning) {
            $unitPlanning->update([
                'last_done_km' => 0,
                'last_done_date' => null,
                'next_due_km' => null,
                'next_due_date' => now()->addDays(20)->toDateString(),
                'due_manually_set' => false,
                'is_estimated' => false,
            ]);
        }

        foreach ([[31, false], [20, true], [null, false]] as $scenarioIndex => [$dateOffset, $isExcluded]) {
            $planningItem = PlanningItem::query()->create([
                'name' => 'Excluded date scope '.$scenarioIndex,
                'interval_km' => 1000,
                'interval_days' => 30,
            ]);
            UnitPlanning::query()->create([
                'unit_id' => $firstUnit->id,
                'planning_item_id' => $planningItem->id,
                // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
                'last_done_km' => 0,
                'last_done_date' => null,
                'next_due_km' => null,
                'next_due_date' => $dateOffset === null ? null : now()->addDays($dateOffset)->toDateString(),
                'due_manually_set' => false,
                'is_estimated' => false,
                'is_excluded' => $isExcluded,
            ]);
        }

        $service = app(ProjectionService::class);

        $this->assertSame(2, $service->calculate(1)['excluded_date_without_source_count']);
        $this->assertSame(1, $service->calculate(1, siteId: $firstUnit->site_id)['excluded_date_without_source_count']);
        $this->assertSame(1, $service->calculate(1, regionId: $firstRegion->id)['excluded_date_without_source_count']);
        $this->assertSame(1, $service->calculate(1, regionId: $secondRegion->id)['excluded_date_without_source_count']);
        $this->assertSame(0, $service->calculate(1, siteId: $firstUnit->site_id, regionId: $secondRegion->id)['excluded_date_without_source_count']);
        $this->assertSame(2, $service->calculate(2, siteId: $firstUnit->site_id)['excluded_date_without_source_count']);

        $admin = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($admin)->get(route('projections.index', ['site_id' => $firstUnit->site_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('projection.excluded_date_without_source_count', 1)
                ->where('filters.site_id', $firstUnit->site_id)
            );
    }

    public function test_superadmin_and_spv_ho_can_filter_by_region_or_site_with_site_taking_priority(): void
    {
        [$firstUnit] = $this->createProjectionScenario('Zulu Site', 'KT 1001 AA');
        [$secondUnit] = $this->createProjectionScenario('Alpha Site', 'KT 1002 AA');
        [$outsideUnit] = $this->createProjectionScenario('Beta Site', 'KT 1003 AA');
        $region = Region::query()->create(['name' => 'Zulu Region']);
        $outsideRegion = Region::query()->create(['name' => 'Alpha Region']);
        $firstUnit->site->update(['region_id' => $region->id]);
        $secondUnit->site->update(['region_id' => $region->id]);
        $outsideUnit->site->update(['region_id' => $outsideRegion->id]);

        $scenarios = [
            [[], [$firstUnit->id, $secondUnit->id, $outsideUnit->id], null, null],
            [['region_id' => $region->id], [$firstUnit->id, $secondUnit->id], $region->id, null],
            [['site_id' => $secondUnit->site_id], [$secondUnit->id], null, $secondUnit->site_id],
            [['region_id' => $region->id, 'site_id' => $outsideUnit->site_id], [$outsideUnit->id], null, $outsideUnit->site_id],
            [['region_id' => 'not-a-region', 'site_id' => $outsideUnit->site_id], [$outsideUnit->id], null, $outsideUnit->site_id],
        ];

        foreach ([UserRole::Superadmin, UserRole::SpvHo] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($scenarios as [$filters, $expectedUnitIds, $regionId, $siteId]) {
                $this->actingAs($user)->get(route('projections.index', $filters))
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page
                        ->where('filters.region_id', $regionId)
                        ->where('filters.site_id', $siteId)
                        ->where('projection.by_unit.data', fn ($units) => collect($units)->pluck('unit_id')->all() === $expectedUnitIds)
                        ->has('projection.warnings', count($expectedUnitIds))
                        ->has('regions.data', 2)
                        ->where('regions.data.0.id', $outsideRegion->id)
                        ->where('regions.data.1.id', $region->id)
                        ->has('sites.data', 3)
                        ->where('sites.data.0.id', $secondUnit->site_id)
                        ->where('sites.data.1.id', $outsideUnit->site_id)
                        ->where('sites.data.2.id', $firstUnit->site_id)
                        ->where('today', CarbonImmutable::today()->toDateString())
                    );
            }
        }
    }

    public function test_planner_region_is_forced_before_validation_and_dropdowns_only_contain_own_scope(): void
    {
        [$firstUnit] = $this->createProjectionScenario('Zulu Site', 'KT 1001 AA');
        [$secondUnit] = $this->createProjectionScenario('Alpha Site', 'KT 1002 AA');
        [$outsideUnit] = $this->createProjectionScenario('Outside Site', 'KT 1003 AA');
        $region = Region::query()->create(['name' => 'Own Region']);
        $outsideRegion = Region::query()->create(['name' => 'Outside Region']);
        $firstUnit->site->update(['region_id' => $region->id]);
        $secondUnit->site->update(['region_id' => $region->id]);
        $outsideUnit->site->update(['region_id' => $outsideRegion->id]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id]);

        foreach ([[], ['region_id' => $outsideRegion->id], ['region_id' => 'not-a-region']] as $filters) {
            $this->actingAs($planner)->get(route('projections.index', $filters))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('filters.region_id', $region->id)
                    ->where('filters.site_id', null)
                    ->where('projection.by_unit.data', fn ($units) => collect($units)->pluck('unit_id')->all() === [$firstUnit->id, $secondUnit->id])
                    ->has('regions.data', 1)
                    ->where('regions.data.0.id', $region->id)
                    ->has('sites.data', 2)
                    ->where('sites.data.0.id', $secondUnit->site_id)
                    ->where('sites.data.1.id', $firstUnit->site_id)
                );
        }

        foreach ([[$secondUnit, 1], [$outsideUnit, 0]] as [$unit, $expectedCount]) {
            $this->actingAs($planner)->get(route('projections.index', ['region_id' => $outsideRegion->id, 'site_id' => $unit->site_id]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('filters.region_id', null)
                    ->where('filters.site_id', $unit->site_id)
                    ->has('projection.by_unit.data', $expectedCount)
                    ->has('projection.warnings', $expectedCount)
                );
        }
    }

    public function test_projection_region_filter_scopes_exclusion_summary_and_km_warning_banner(): void
    {
        [$ownUnit, , $ownPlanning] = $this->createProjectionScenario('Own Site', 'KT 1001 AA');
        [$outsideUnit, , $outsidePlanning] = $this->createProjectionScenario('Outside Site', 'KT 1002 AA');
        $region = Region::query()->create(['name' => 'Own Region']);
        $outsideRegion = Region::query()->create(['name' => 'Outside Region']);
        $ownUnit->site->update(['region_id' => $region->id]);
        $outsideUnit->site->update(['region_id' => $outsideRegion->id]);
        UnitPlanning::query()->whereNotIn('id', [$ownPlanning->id, $outsidePlanning->id])->update(['is_excluded' => true]);

        foreach ([$ownPlanning, $outsidePlanning] as $planning) {
            $planning->update(['last_done_km' => 0, 'last_done_date' => null, 'due_manually_set' => false, 'is_estimated' => false]);
        }

        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        foreach ([[$region->id, 1], [null, 2]] as [$regionId, $expectedCount]) {
            $this->actingAs($user)->get(route('projections.index', ['region_id' => $regionId]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('projection.excluded_date_without_source_count', $expectedCount)
                    ->has('projection.warnings', $expectedCount)
                    ->has('projection.by_unit.data', 0)
                );
        }
    }

    public function test_projection_rejects_invalid_region_and_site_filters(): void
    {
        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        foreach ([['region_id' => 999], ['region_id' => 'invalid'], ['site_id' => 999], ['site_id' => 'invalid']] as $filters) {
            $this->actingAs($user)->getJson(route('projections.index', $filters))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_keys($filters));
        }
    }

    public function test_projection_excludes_missing_baseline_when_date_is_outside_period(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);
        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        $unitPlanning->update([
            'last_done_km' => 0,
            'next_due_km' => 2000,
            'next_due_date' => now()->addDays(31)->toDateString(),
            'due_manually_set' => false,
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame([], $result['by_unit']);
        $this->assertSame([], $result['by_item']);
        $this->assertSame([], $result['by_part']);
    }

    public function test_projection_does_not_use_unconfirmed_km_due_without_baseline(): void
    {
        [$unit, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);
        $unit->update(['current_odo' => 150000]);
        $unitPlanning->update([
            'last_done_km' => 0,
            'next_due_km' => 10000,
            'next_due_date' => null,
            'due_manually_set' => false,
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame([], $result['by_unit']);
        $this->assertSame([], $result['by_item']);
        $this->assertSame([], $result['by_part']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_projection_excludes_zero_baseline_with_unconfirmed_km_and_date_outside_period(): void
    {
        [$unit, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);
        $unit->update(['current_odo' => 150000]);
        $unitPlanning->update([
            'last_done_km' => 0,
            'next_due_km' => 5000,
            'next_due_date' => now()->addDays(31)->toDateString(),
            'due_manually_set' => false,
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame([], $result['by_unit']);
        $this->assertSame([], $result['by_item']);
        $this->assertSame([], $result['by_part']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_projection_manual_due_km_does_not_override_shared_km_baseline_guard(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);
        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        $unitPlanning->update([
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 13800,
            'next_due_date' => null,
            'due_manually_set' => true,
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame([], $result['by_unit']);
        $this->assertSame([], $result['by_item']);
        $this->assertSame([], $result['by_part']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, $result['excluded_date_without_source_count']);
    }

    public function test_projection_rejects_missing_or_non_positive_manually_set_due_km(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);

        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        foreach ([null, 0, -100] as $nextDueKm) {
            $unitPlanning->update([
                'last_done_km' => 0,
                'next_due_km' => $nextDueKm,
                'next_due_date' => null,
                'due_manually_set' => true,
            ]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertSame([], $result['by_unit']);
            $this->assertSame([], $result['by_item']);
            $this->assertSame([], $result['by_part']);
        }
    }

    public function test_projection_km_path_requires_odometer_data_but_keeps_valid_dates(): void
    {
        [$unit, , $unitPlanning] = $this->createProjectionScenario();
        $unitPlanning->update([
            'last_done_km' => 1000,
            'next_due_km' => 1000,
            'next_due_date' => null,
            'due_manually_set' => true,
        ]);

        foreach ([[true, 4], [false, 3]] as [$hasOdometerReading, $minimumInspectionData]) {
            $unit->update(['has_odometer_reading' => $hasOdometerReading]);
            SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => (string) $minimumInspectionData]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertSame([], $result['by_unit']);
            $this->assertCount(1, $result['warnings']);

            $unitPlanning->update(['next_due_date' => now()->addDays(20)->toDateString()]);

            $dateResult = app(ProjectionService::class)->calculate(1);

            $this->assertCount(1, $dateResult['by_unit']);
            $this->assertSame(0.0, $dateResult['by_unit'][0]['avg_km_per_day']);
            $this->assertSame($unit->current_odo, $dateResult['by_unit'][0]['estimated_period_odo']);
            $item = $dateResult['by_unit'][0]['items'][0];
            $this->assertSame('date', $item['trigger']);
            $this->assertFalse($item['baseline_km_missing']);
            $this->assertSame(now()->addDays(20)->toDateString(), $item['estimated_due_date']);

            $unitPlanning->update(['next_due_date' => null]);
        }
    }

    public function test_projection_preserves_normal_baseline_triggers_and_earliest_due_date(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        SystemThreshold::query()->where('key', 'min_inspection_data')->update(['value' => '3']);

        foreach ([[4200, null, 'km', 6], [15000, 20, 'date', 20], [4200, 20, 'both', 6]] as [$nextDueKm, $dueDateOffset, $trigger, $estimatedDateOffset]) {
            $unitPlanning->update([
                'next_due_km' => $nextDueKm,
                'next_due_date' => $dueDateOffset === null ? null : now()->addDays($dueDateOffset)->toDateString(),
            ]);

            $result = app(ProjectionService::class)->calculate(1);

            $this->assertCount(1, $result['by_unit']);
            $this->assertSame(400.0, $result['by_unit'][0]['avg_km_per_day']);
            $this->assertSame(13800, $result['by_unit'][0]['estimated_period_odo']);
            $item = $result['by_unit'][0]['items'][0];
            $this->assertSame($trigger, $item['trigger']);
            $this->assertFalse($item['baseline_km_missing']);
            $this->assertSame(now()->addDays($estimatedDateOffset)->toDateString(), $item['estimated_due_date']);
        }
    }

    public function test_projection_still_excludes_excluded_items_with_date_due_and_missing_baseline(): void
    {
        [, , $unitPlanning] = $this->createProjectionScenario();
        // TODO: kembalikan kasus NULL setelah migration last_done_km nullable di-deploy
        $unitPlanning->update([
            'last_done_km' => 0,
            'due_manually_set' => true,
            'is_excluded' => true,
        ]);

        $result = app(ProjectionService::class)->calculate(1);

        $this->assertSame([], $result['by_unit']);
        $this->assertSame([], $result['by_item']);
        $this->assertSame([], $result['by_part']);
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
                    ->where('projection.by_unit.data.0.items.0.trigger', 'date')
                    ->where('projection.by_unit.data.0.items.0.baseline_km_missing', false)
                    ->where('projection.excluded_date_without_source_count', 0)
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
        // Label periode menyebut jumlah hari, dan jumlah harinya diturunkan dari
        // period_options kiriman backend — bukan literal di frontend yang bisa
        // basi kalau basis di ProjectionService berubah.
        $this->assertStringContainsString('${option.months} Bulan (${option.days} hari)', $projectionSource);
        $this->assertStringContainsString('periodRangeLabel(periodOptions)', $projectionSource);
        $this->assertStringContainsString('periodOptions.map((option) =>', $projectionSource);

        foreach (['(30 hari)', '(60 hari)', '(90 hari)', '30/60/90', 'Kebutuhan 1-3 Bulan'] as $hardcoded) {
            $this->assertStringNotContainsString($hardcoded, $projectionSource, 'Jumlah hari periode tidak boleh ditulis literal di frontend.');
        }
        $this->assertStringContainsString('Filter Wilayah / Lokasi', $projectionSource);
        $this->assertStringContainsString('<SelectGroup>', $projectionSource);
        $this->assertStringContainsString('<SelectLabel>Wilayah</SelectLabel>', $projectionSource);
        $this->assertStringContainsString('<SelectLabel>Lokasi</SelectLabel>', $projectionSource);
        $this->assertStringContainsString('value={`region:${region.id}`}', $projectionSource);
        $this->assertStringContainsString('value={`site:${site.id}`}', $projectionSource);
        $this->assertStringContainsString('Semua Lokasi', $projectionSource);
        $this->assertStringContainsString('Proyeksi Perawatan', $projectionSource);
        $this->assertStringContainsString('Est. Jatuh Tempo', $projectionSource);
        $this->assertStringContainsString('Tampilan Bulan:', $projectionSource);
        $this->assertStringContainsString('Pemakaian Tinggi', $projectionSource);
        $this->assertStringContainsString('Kalender hanya untuk pemantauan', $projectionSource);
        $this->assertStringNotContainsString('Proyeksi Maintenance', $projectionSource);
        $this->assertStringNotContainsString('Month View:', $projectionSource);
        $this->assertStringContainsString('Buka di Daftar Kerja', $projectionSource);
        $this->assertStringNotContainsString('WorkListActionPanel', $projectionSource);
        $this->assertStringNotContainsString('Panel Pengajuan', $projectionSource);
        $this->assertStringNotContainsString('Lanjutkan', $projectionSource);
        $this->assertStringContainsString("label: 'Proyeksi'", $layoutSource);
        $this->assertStringNotContainsString("label: 'Projections'", $layoutSource);
    }

    public function test_projection_page_displays_baseline_km_badges_and_trigger_labels(): void
    {
        $projectionSource = file_get_contents(resource_path('js/Pages/Projections/Index.tsx'));

        $this->assertStringContainsString("import { Badge } from '@/Components/ui/badge'", $projectionSource);
        $this->assertStringContainsString('variant="outline"', $projectionSource);
        $this->assertStringContainsString('Baseline KM Kosong', $projectionSource);
        $this->assertStringContainsString('item.baseline_km_missing', $projectionSource);
        $this->assertStringContainsString("item.baseline_km_missing ? '—' : formatKm(item.estimated_due_km)", $projectionSource);
        $this->assertStringContainsString('date < today', $projectionSource);
        $this->assertStringContainsString('variant="destructive"', $projectionSource);
        $this->assertStringContainsString('Lewat jatuh tempo', $projectionSource);
        $this->assertSame(2, substr_count($projectionSource, '<EstimatedDueDate date={item.estimated_due_date} today={today} />'));
        $this->assertStringContainsString('<TableHead>Pemicu</TableHead>', $projectionSource);
        $this->assertStringContainsString("km: 'KM'", $projectionSource);
        $this->assertStringContainsString("date: 'Tanggal'", $projectionSource);
        $this->assertStringContainsString("both: 'KM & Tanggal'", $projectionSource);
        $this->assertStringContainsString('projectionTriggerLabels[item.trigger]', $projectionSource);
        $this->assertStringContainsString('projection.excluded_date_without_source_count', $projectionSource);
        $this->assertStringContainsString('item dikecualikan karena tanggal jatuh tempo tanpa dasar', $projectionSource);
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
        UnitPlanning::query()->updateOrCreate(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id], ['last_done_km' => 1000, 'last_done_date' => now()->subDays(90)->toDateString(), 'next_due_km' => 4000, 'next_due_date' => now()->addDays(30)->toDateString()]);

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

        // Item due tepat 30 hari dari hari ini harus selalu masuk proyeksi 1 bulan,
        // apa pun panjang bulan kalender berjalan.
        $this->assertSame(CarbonImmutable::today()->addDays(30)->toDateString(), $result['period_end']);

        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'DD 7777 AA');
        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $this->assertContains('Test Item Rolling Unique', collect($byUnit['items'])->pluck('planning_item_name')->all());
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
        UnitPlanning::query()->updateOrCreate(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id], ['last_done_km' => 100, 'last_done_date' => now()->subDays(60)->toDateString(), 'next_due_km' => 400, 'next_due_date' => now()->addDays(20)->toDateString()]);

        InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => now()->subDays(10)->toDateString(), 'odometer' => 0]);
        InspectionLog::query()->create(['unit_id' => $unit->id, 'mechanic_id' => $mechanic->id, 'inspection_date' => now()->toDateString(), 'odometer' => 300]);

        $result = app(ProjectionService::class)->calculate(1);

        $byUnit = collect($result['by_unit'])->firstWhere('plate_number', 'DD 8888 AA');
        $this->assertNotNull($byUnit);
        $this->assertFalse($byUnit['insufficient_data']);
        $this->assertSame(round(300 / 10, 2), $byUnit['avg_km_per_day']);
    }

    public function test_projection_period_days_and_options_follow_the_shipped_30_day_basis(): void
    {
        $service = app(ProjectionService::class);
        $today = CarbonImmutable::today();

        foreach ([1 => 30, 2 => 60, 3 => 90] as $months => $expectedDays) {
            $result = $service->calculate($months);

            $this->assertSame($expectedDays, $result['period_days']);
            $this->assertSame($today->addDays($expectedDays)->toDateString(), $result['period_end']);
        }

        $this->assertSame(
            [['months' => 1, 'days' => 30], ['months' => 2, 'days' => 60], ['months' => 3, 'days' => 90]],
            $service->periodOptions(),
        );
    }

    public function test_period_days_sent_to_frontend_follow_the_service_basis(): void
    {
        // Basis hari sengaja diganti lewat subclass: kalau ada angka hari yang
        // ditulis terpisah (di resource, controller, atau label frontend),
        // nilai yang sampai ke halaman tidak akan ikut berubah dan test gagal.
        $this->app->bind(ProjectionService::class, fn (): ProjectionService => new class extends ProjectionService
        {
            public const DAYS_PER_PERIOD_MONTH = 7;
        });

        $user = User::factory()->create(['role' => UserRole::Superadmin]);

        $this->actingAs($user)->get(route('projections.index', ['months' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('projection.period_months', 2)
                ->where('projection.period_days', 14)
                ->where('projection.period_end', CarbonImmutable::today()->addDays(14)->toDateString())
                ->where('period_options', [
                    ['months' => 1, 'days' => 7],
                    ['months' => 2, 'days' => 14],
                    ['months' => 3, 'days' => 21],
                ])
            );

        // Frontend hanya merangkai "X Bulan (Y hari)" dari angka di atas.
        $this->assertStringContainsString(
            '${option.months} Bulan (${option.days} hari)',
            file_get_contents(resource_path('js/Pages/Projections/Index.tsx')),
        );
    }

    public function test_projection_period_uses_30_day_basis_at_end_of_january(): void
    {
        // Akhir Januari adalah kasus terburuk untuk basis bulan kalender:
        // 2026-01-30 + 1 bulan = 2026-02-28, sehingga item yang due tepat
        // 30 hari lagi (2026-03-01) dulu terlempar keluar proyeksi 1 bulan.
        $this->travelTo('2026-01-30');

        SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '2']);
        SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

        $site = Site::query()->create(['name' => 'Site Akhir Januari', 'region' => 'Region Test']);
        $dueOnDay30 = PlanningItem::query()->create(['name' => 'Service Hari Ke-30', 'interval_km' => 5000, 'interval_days' => 90]);
        $dueOnDay31 = PlanningItem::query()->create(['name' => 'Service Hari Ke-31', 'interval_km' => 5000, 'interval_days' => 90]);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'DD 3001 FB',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]);

        // Tanpa data inspeksi, jalur KM mati — yang diuji murni jendela tanggal.
        foreach ([[$dueOnDay30, '2026-03-01'], [$dueOnDay31, '2026-03-02']] as [$planningItem, $nextDueDate]) {
            UnitPlanning::query()->updateOrCreate(
                ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
                [
                    'last_done_km' => 1000,
                    'last_done_date' => '2025-12-01',
                    'next_due_km' => 999000,
                    'next_due_date' => $nextDueDate,
                ],
            );
        }

        $oneMonth = app(ProjectionService::class)->calculate(1);

        // 1 bulan = 30 hari, bukan 2026-02-28 versi bulan kalender.
        $this->assertSame('2026-03-01', $oneMonth['period_end']);

        $byUnit = collect($oneMonth['by_unit'])->firstWhere('plate_number', 'DD 3001 FB');
        $this->assertNotNull($byUnit);

        $itemNames = collect($byUnit['items'])->pluck('planning_item_name')->all();
        $this->assertContains('Service Hari Ke-30', $itemNames, 'Item due tepat 30 hari lagi harus masuk proyeksi 1 bulan.');
        $this->assertNotContains('Service Hari Ke-31', $itemNames, 'Item due 31 hari lagi harus di luar proyeksi 1 bulan.');

        // Batas 2 dan 3 bulan ikut basis hari yang sama.
        $this->assertSame('2026-03-31', app(ProjectionService::class)->calculate(2)['period_end']);
        $this->assertSame('2026-04-30', app(ProjectionService::class)->calculate(3)['period_end']);
    }

    public function test_projection_matches_manual_km_calculation_for_one_and_two_month_periods(): void
    {
        CarbonImmutable::setTestNow('2026-07-01 08:00:00');

        try {
            SystemThreshold::query()->updateOrCreate(['key' => 'min_inspection_data'], ['value' => '2']);
            SystemThreshold::query()->updateOrCreate(['key' => 'rolling_window_days'], ['value' => '30']);

            $site = Site::query()->create(['name' => 'Site Projection Accuracy', 'region' => 'Region Test']);
            $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
            $unit = Unit::query()->create([
                'site_id' => $site->id,
                'customer' => 'Customer Projection',
                'current_plate' => 'DD 1000 KM',
                'type' => 'Pickup',
                'brand' => 'Toyota',
                'year' => 2024,
                'current_odo' => 10000,
                'status' => 'active',
            ]);

            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => '2026-06-21',
                'odometer' => 9000,
            ]);
            InspectionLog::query()->create([
                'unit_id' => $unit->id,
                'mechanic_id' => $mechanic->id,
                'inspection_date' => '2026-07-01',
                'odometer' => 10000,
            ]);

            $julyItem = PlanningItem::query()->create(['name' => 'Service July', 'interval_km' => 2000, 'interval_days' => 3650]);
            $augustItem = PlanningItem::query()->create(['name' => 'Service August', 'interval_km' => 4500, 'interval_days' => 3650]);

            foreach ([[$julyItem, 12000], [$augustItem, 14500]] as [$planningItem, $nextDueKm]) {
                UnitPlanning::query()->create([
                    'unit_id' => $unit->id,
                    'planning_item_id' => $planningItem->id,
                    'last_done_km' => 10000,
                    'last_done_date' => '2026-07-01',
                    'next_due_km' => $nextDueKm,
                    'next_due_date' => null,
                ]);
            }

            $oneMonth = app(ProjectionService::class)->calculate(1);
            $twoMonths = app(ProjectionService::class)->calculate(2);
            $oneMonthUnit = collect($oneMonth['by_unit'])->firstWhere('unit_id', $unit->id);
            $twoMonthUnit = collect($twoMonths['by_unit'])->firstWhere('unit_id', $unit->id);

            // 1 bulan = 30 hari: 2026-07-01 + 30 hari = 2026-07-31 (bukan 2026-08-01).
            $this->assertSame('2026-07-31', $oneMonth['period_end']);
            $this->assertSame(100.0, $oneMonthUnit['avg_km_per_day']);
            $this->assertSame(10000 + (100 * 30), $oneMonthUnit['estimated_period_odo']);
            $this->assertSame(['Service July'], collect($oneMonthUnit['items'])->pluck('planning_item_name')->all());
            $this->assertSame('2026-07-21', $oneMonthUnit['items'][0]['estimated_due_date']);

            // 2 bulan = 60 hari: 2026-07-01 + 60 hari = 2026-08-30 (bukan 2026-09-01).
            $this->assertSame('2026-08-30', $twoMonths['period_end']);
            $this->assertSame(100.0, $twoMonthUnit['avg_km_per_day']);
            $this->assertSame(10000 + (100 * 60), $twoMonthUnit['estimated_period_odo']);
            $this->assertEqualsCanonicalizing(
                ['Service July', 'Service August'],
                collect($twoMonthUnit['items'])->pluck('planning_item_name')->all(),
            );
            $this->assertSame(
                '2026-08-15',
                collect($twoMonthUnit['items'])->firstWhere('planning_item_name', 'Service August')['estimated_due_date'],
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_projection_excludes_breakdown_days_from_effective_divisor(): void
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

        $unitPlanning = UnitPlanning::query()->updateOrCreate(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id], ['last_done_km' => 100, 'last_done_date' => now()->subDays(60)->toDateString(), 'next_due_km' => 300, 'next_due_date' => now()->addDays(20)->toDateString()]);

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
            'action' => 'breakdown',
            'status' => 'breakdown',
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
            $unitPlanning = UnitPlanning::query()->create(['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id, 'last_done_km' => 100, 'last_done_date' => now()->subDays(60)->toDateString(), 'next_due_km' => 300, 'next_due_date' => now()->addDays(20)->toDateString()]);
            $workOrder = WorkOrder::query()->create(['unit_id' => $unit->id, 'site_id' => $unit->site_id, 'trigger_type' => 'normal', 'status' => 'open']);

            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $unitPlanning->id,
                'planning_item_id' => $planningItem->id,
                'action' => 'breakdown',
                'status' => 'breakdown',
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
        // Skenario ini memakai tanggal absolut, jadi jamnya dibekukan supaya
        // hasilnya tidak berubah seiring berjalannya waktu.
        $this->travelTo('2026-07-10');

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

        // Jadwal item mengalahkan due planning-nya di kalender.
        $scheduledPlanningItem = PlanningItem::query()->create(['name' => 'Service Terjadwal', 'interval_km' => 1000, 'interval_days' => 30]);
        $scheduledPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $scheduledPlanningItem->id,
            'last_done_km' => 1000,
            'last_done_date' => '2026-06-01',
            'next_due_km' => 4200,
            'next_due_date' => '2026-07-15',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $scheduledPlanning->id,
            'planning_item_id' => $scheduledPlanningItem->id,
            'status' => 'in_progress',
            'scheduled_date' => '2026-07-22',
        ]);

        $missingPlanningItem = PlanningItem::query()->create(['name' => 'Service Tanpa Baseline', 'interval_km' => 1000, 'interval_days' => 30]);
        $missingPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $missingPlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => '2026-06-01',
            'next_due_km' => 1000,
            'next_due_date' => '2026-07-18',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $missingPlanning->id,
            'planning_item_id' => $missingPlanningItem->id,
            'status' => 'overdue',
            'triggered_by_high_usage' => true,
        ]);

        $this->actingAs($user)->get(route('projections.index', ['month' => '2026-07', 'region_id' => $region->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.month', '2026-07')
                ->where('filters.region_id', $region->id)
                ->where('calendar.summary_by_date.2026-07-22.total', 1)
                ->where('calendar.summary_by_date.2026-07-25.total', 1)
                ->where('calendar.summary_by_date.2026-07-25.high_usage', 1)
                ->where('calendar.items.0.due_date', '2026-07-25')
                ->where('calendar.items.0.is_high_usage', true)
                ->where('calendar.items.0.status_label', 'Ditunda')
                ->where('calendar.items', fn ($items) => collect($items)->contains('item_name', 'Service Tanpa Baseline'))
            );
    }

    /**
     * @return array{0: Unit, 1: PlanningItem}
     */
    /**
     * @return array{Unit, PlanningItem, UnitPlanning}
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

        $unitPlanning = UnitPlanning::query()->create([
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

        return [$unit, $planningItem, $unitPlanning];
    }
}
