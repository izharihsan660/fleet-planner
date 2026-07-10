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
