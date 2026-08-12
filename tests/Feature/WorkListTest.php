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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkListTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_kerja_shows_region_on_hold_and_overdue_items_ordered_by_late_days(): void
    {
        [$planner, $firstSite, $secondSite] = $this->createRegionUserAndSites();
        $lateItem = $this->createWorkListItem($firstSite, 'KT 1001 AA', 'Filter Oli', 'overdue', today()->subDays(12)->toDateString());
        $lessLateItem = $this->createWorkListItem($secondSite, 'KT 2002 BB', 'Brake Pad', 'overdue', today()->subDays(3)->toDateString());
        $safeItem = $this->createWorkListItem($firstSite, 'KT 3003 CC', 'Air Filter', 'on_hold', today()->addDays(5)->toDateString());
        $outsideSite = Site::query()->create(['name' => 'Site Luar', 'region' => 'Region Luar', 'region_id' => Region::query()->create(['name' => 'Region Luar'])->id]);
        $outsideItem = $this->createWorkListItem($outsideSite, 'KT 9999 ZZ', 'Outside Item', 'overdue', today()->subDays(30)->toDateString());

        $this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkList/Index')
                ->has('items', 3)
                ->where('items.0.id', $lateItem->id)
                ->where('items.0.status_label', 'Telat 12 hari')
                ->where('items.1.id', $lessLateItem->id)
                ->where('items.2.id', $safeItem->id)
                ->missing('items.3')
            );

        $this->assertNotContains($outsideItem->id, collect($this->actingAs($planner)->get(route('work-list.index'))->viewData('page')['props']['items'])->pluck('id'));
    }

    /**
     * Regresi pola "visibility ikut status container": Daftar Kerja dulu memfilter
     * work_orders.status in ('open','in_progress'), jadi item yang kembali
     * applicable di bawah WO yang sudah terminal — misalnya lewat jalur
     * exclude → complete → un-exclude — hilang dari daftar padahal masih overdue.
     */
    public function test_daftar_kerja_shows_applicable_items_under_terminal_work_orders(): void
    {
        [$planner, $site] = $this->createRegionUserAndSites();
        $completeWorkOrderItem = $this->createWorkListItem($site, 'KT 4004 DD', 'Filter Solar', 'overdue', today()->subDays(6)->toDateString());
        $completeWorkOrderItem->workOrder->update(['status' => 'complete']);

        $cancelledWorkOrderItem = $this->createWorkListItem($site, 'KT 5005 EE', 'Filter Udara', 'on_hold', today()->addDays(3)->toDateString());
        $cancelledWorkOrderItem->workOrder->update(['status' => 'cancelled']);

        $this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                ->where('items.0.id', $completeWorkOrderItem->id)
                ->where('items.0.status_label', 'Telat 6 hari')
                ->where('items.1.id', $cancelledWorkOrderItem->id)
            );

        // Item yang benar-benar selesai tetap tidak boleh muncul — yang menentukan
        // status itemnya sendiri, bukan status WO induknya (ke dua arah).
        $completeWorkOrderItem->update(['status' => 'complete']);

        $this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.id', $cancelledWorkOrderItem->id)
            );
    }

    public function test_item_under_terminal_work_order_can_still_be_submitted_from_daftar_kerja(): void
    {
        [$planner, $site] = $this->createRegionUserAndSites();
        $item = $this->createWorkListItem($site, 'KT 6006 FF', 'Filter Oli', 'overdue', today()->subDays(4)->toDateString());
        $item->workOrder->update(['status' => 'complete']);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($planner)
            ->post(route('work-list.store'), [
                'groups' => [[
                    'site_id' => $site->id,
                    'action' => 'replace',
                    'item_ids' => [$item->id],
                    'assigned_mechanic_id' => $mechanic->id,
                    'scheduled_date' => today()->addDay()->toDateString(),
                ]],
            ])
            ->assertRedirect(route('work-list.index'));

        $this->assertSame('replace', $item->refresh()->status);
    }

    public function test_daftar_kerja_filters_multiple_items_and_can_hide_incomplete_baselines(): void
    {
        [$planner, $site] = $this->createRegionUserAndSites();
        $completeItem = $this->createWorkListItem($site, 'KT 1001 AA', 'PM Check / Reguler Services', 'overdue', today()->subDays(2)->toDateString());
        $completeItem->workOrder->unit->update(['has_odometer_reading' => true]);
        $completeItem->unitPlanning->update(['last_done_km' => 500]);

        $incompleteItem = $this->createWorkListItem($site, 'KT 1002 AA', 'Service A', 'overdue', today()->subDay()->toDateString());
        $incompleteItem->workOrder->unit->update(['has_odometer_reading' => false]);
        $otherItem = $this->createWorkListItem($site, 'KT 1003 AA', 'Brake Pad', 'overdue', today()->subDays(10)->toDateString());
        $selectedPlanningItemIds = [$completeItem->planning_item_id, $incompleteItem->planning_item_id];

        $this->actingAs($planner)
            ->get(route('work-list.index', ['planning_item_ids' => $selectedPlanningItemIds]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                ->where('items.0.id', $completeItem->id)
                ->where('items.0.is_priority', true)
                ->where('filters.planning_item_ids', $selectedPlanningItemIds)
                ->where('filters.include_incomplete_baseline', true)
            );

        $this->actingAs($planner)
            ->get(route('work-list.index', [
                'planning_item_ids' => $selectedPlanningItemIds,
                'include_incomplete_baseline' => 0,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.id', $completeItem->id)
                ->where('filters.include_incomplete_baseline', false)
            );

        $this->assertNotContains($otherItem->id, collect($this->actingAs($planner)
            ->get(route('work-list.index', ['planning_item_ids' => $selectedPlanningItemIds]))
            ->viewData('page')['props']['items'])->pluck('id'));
    }

    public function test_daftar_kerja_defaults_to_priority_and_supports_due_date_and_due_km_sorting(): void
    {
        [$planner, $site] = $this->createRegionUserAndSites();
        $regularItem = $this->createWorkListItem($site, 'KT 2001 AA', 'Brake Shoe', 'overdue', today()->subDays(10)->toDateString());
        $regularItem->unitPlanning->update(['next_due_km' => 5000]);
        $priorityItem = $this->createWorkListItem($site, 'KT 2002 AA', 'Service B', 'on_hold', today()->addDays(5)->toDateString());
        $priorityItem->unitPlanning->update(['next_due_km' => 15000]);

        $this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.id', $priorityItem->id)
                ->where('items.0.is_priority', true)
                ->where('filters.sort_by', 'priority')
            );

        foreach (['due_date', 'due_km'] as $sortBy) {
            $this->actingAs($planner)
                ->get(route('work-list.index', ['sort_by' => $sortBy]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('items.0.id', $regularItem->id)
                    ->where('filters.sort_by', $sortBy)
                );
        }
    }

    public function test_filter_sources_keep_new_filter_state_in_the_query_string(): void
    {
        $pageSources = [
            file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx')),
            file_get_contents(resource_path('js/Pages/WorkList/Index.tsx')),
        ];

        foreach ($pageSources as $pageSource) {
            $this->assertStringContainsString('planning_item_ids:', $pageSource);
            $this->assertStringContainsString('include_incomplete_baseline:', $pageSource);
            $this->assertStringContainsString('sort_by:', $pageSource);
            $this->assertStringContainsString('Prioritas', $pageSource);
        }
    }

    public function test_daftar_kerja_props_allow_multi_site_action_bar_grouping(): void
    {
        [$planner, $firstSite, $secondSite] = $this->createRegionUserAndSites();
        $firstItem = $this->createWorkListItem($firstSite, 'KT 1001 AA', 'Filter Oli', 'on_hold', today()->addDays(5)->toDateString());
        $secondItem = $this->createWorkListItem($secondSite, 'KT 2002 BB', 'Brake Pad', 'overdue', today()->subDays(2)->toDateString());
        $firstMechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);
        $secondMechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $secondSite->id]);

        $response = $this->actingAs($planner)->get(route('work-list.index'))->assertOk();
        $props = $response->viewData('page')['props'];
        $siteIds = collect($props['items'])->whereIn('id', [$firstItem->id, $secondItem->id])->pluck('site_id')->unique()->values();

        $this->assertCount(2, $siteIds);
        $this->assertArrayHasKey((string) $firstSite->id, $props['mechanicsBySite']);
        $this->assertArrayHasKey((string) $secondSite->id, $props['mechanicsBySite']);
        $this->assertSame($firstMechanic->id, $props['mechanicsBySite'][$firstSite->id][0]['id']);
        $this->assertSame($secondMechanic->id, $props['mechanicsBySite'][$secondSite->id][0]['id']);
    }

    public function test_daftar_kerja_props_allow_single_site_action_bar_without_extra_grouping(): void
    {
        [$planner, $firstSite] = $this->createRegionUserAndSites();
        $firstItem = $this->createWorkListItem($firstSite, 'KT 1001 AA', 'Filter Oli', 'on_hold', today()->addDays(5)->toDateString());
        $secondItem = $this->createWorkListItem($firstSite, 'KT 1002 AA', 'Brake Pad', 'overdue', today()->subDays(1)->toDateString());
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);

        $response = $this->actingAs($planner)->get(route('work-list.index', ['site_id' => $firstSite->id]))->assertOk();
        $props = $response->viewData('page')['props'];
        $siteIds = collect($props['items'])->whereIn('id', [$firstItem->id, $secondItem->id])->pluck('site_id')->unique()->values();

        $this->assertCount(1, $siteIds);
        $this->assertSame($firstSite->id, $siteIds->first());
        $this->assertSame($mechanic->id, $props['mechanicsBySite'][$firstSite->id][0]['id']);
    }

    public function test_daftar_kerja_exposes_multiple_mechanics_without_forcing_a_default(): void
    {
        [$planner, $firstSite] = $this->createRegionUserAndSites();
        $this->createWorkListItem($firstSite, 'KT 1001 AA', 'Filter Oli', 'on_hold', today()->addDays(5)->toDateString());
        User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);
        User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);

        $response = $this->actingAs($planner)->get(route('work-list.index', ['site_id' => $firstSite->id]))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(2, $props['mechanicsBySite'][$firstSite->id]);
    }

    public function test_daftar_kerja_can_warn_when_more_than_eight_sites_are_selected(): void
    {
        [$planner, $firstSite] = $this->createRegionUserAndSites();
        $regionId = $firstSite->region_id;

        for ($index = 1; $index <= 9; $index++) {
            $site = Site::query()->create(['name' => 'Site Banyak '.$index, 'region' => 'Kalimantan', 'region_id' => $regionId]);
            $this->createWorkListItem($site, 'KT 90'.$index, 'Item '.$index, 'on_hold', today()->addDays(5)->toDateString());
        }

        $response = $this->actingAs($planner)->get(route('work-list.index'))->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertGreaterThan(8, collect($props['items'])->pluck('site_id')->unique()->count());
    }

    public function test_daftar_kerja_action_bar_copy_is_not_redundant(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/WorkList/Index.tsx'));

        $this->assertStringContainsString('Terapkan ke Semua Lokasi', $pageSource);
        $this->assertStringContainsString('Pertimbangkan untuk memilih lebih sedikit lokasi sekaligus', $pageSource);
        $this->assertStringNotContainsString('item dipilih di lokasi ini', $pageSource);
        $this->assertStringNotContainsString('item dipilih dari satu lokasi', $pageSource);
    }

    public function test_daftar_kerja_uses_collapsible_bottom_drawer_with_internal_scroll(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/WorkList/Index.tsx'));

        $this->assertStringContainsString('isActionPanelOpen', $pageSource);
        $this->assertStringContainsString('fixed inset-x-0 bottom-0', $pageSource);
        $this->assertStringContainsString('Lanjutkan →', $pageSource);
        $this->assertStringContainsString('max-h-[70vh] overflow-y-auto', $pageSource);
        $this->assertStringContainsString('Tutup', $pageSource);
    }

    public function test_daftar_kerja_form_source_lists_selected_plate_and_item_names(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/WorkList/Index.tsx'));

        $this->assertStringContainsString('Item yang dipilih:', $pageSource);
        $this->assertStringContainsString('{item.plate_number} — {item.item_name}', $pageSource);
        $this->assertStringContainsString('dan {hiddenCount} lainnya', $pageSource);
        $this->assertStringContainsString('Tampilkan lebih sedikit', $pageSource);
    }

    public function test_submit_from_daftar_kerja_updates_items_like_detail_actions(): void
    {
        [$planner, $firstSite, $secondSite] = $this->createRegionUserAndSites();
        $replaceItem = $this->createWorkListItem($firstSite, 'KT 1001 AA', 'Filter Oli', 'on_hold', today()->addDays(5)->toDateString());
        $blockedItem = $this->createWorkListItem($secondSite, 'KT 2002 BB', 'Brake Pad', 'overdue', today()->subDays(2)->toDateString());
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);
        User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($planner)
            ->post(route('work-list.store'), [
                'groups' => [
                    [
                        'site_id' => $firstSite->id,
                        'action' => 'replace',
                        'item_ids' => [$replaceItem->id],
                        'assigned_mechanic_id' => $mechanic->id,
                        'scheduled_date' => today()->addDay()->toDateString(),
                    ],
                    [
                        'site_id' => $secondSite->id,
                        'action' => 'blocked',
                        'item_ids' => [$blockedItem->id],
                        'assigned_mechanic_id' => null,
                        'scheduled_date' => today()->addDays(2)->toDateString(),
                    ],
                ],
            ])
            ->assertRedirect(route('work-list.index'));

        $this->assertSame('replace', $replaceItem->refresh()->status);
        $this->assertSame('replace', $replaceItem->action);
        $this->assertSame($mechanic->id, $replaceItem->workOrder->refresh()->assigned_mechanic_id);
        $this->assertSame('blocked', $blockedItem->refresh()->status);
        $this->assertSame('blocked', $blockedItem->action);
        $this->assertSame(2, Notification::query()->where('type', 'task_submitted')->count());
    }

    public function test_work_orders_kanban_still_uses_existing_page(): void
    {
        [$planner] = $this->createRegionUserAndSites();

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/Index')
                ->has('boardColumns')
            );
    }

    /**
     * @return array{0: User, 1: Site, 2: Site}
     */
    private function createRegionUserAndSites(): array
    {
        $region = Region::query()->create(['name' => 'Kalimantan']);
        $firstSite = Site::query()->create(['name' => 'Site Balikpapan', 'region' => 'Kalimantan', 'region_id' => $region->id]);
        $secondSite = Site::query()->create(['name' => 'Site Samarinda', 'region' => 'Kalimantan', 'region_id' => $region->id]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id, 'site_id' => null]);

        return [$planner, $firstSite, $secondSite];
    }

    private function createWorkListItem(Site $site, string $plate, string $itemName, string $status, string $nextDueDate): WorkOrderItem
    {
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => 1000,
            'status' => 'active',
        ]);
        $planningItem = PlanningItem::query()->create(['name' => $itemName, 'interval_km' => 10000, 'interval_days' => 90]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subDays(90)->toDateString(),
            'next_due_km' => 10000,
            'next_due_date' => $nextDueDate,
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => $status,
        ]);
    }
}
