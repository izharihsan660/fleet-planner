<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkOrderBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_splits_upcoming_and_preparation_from_live_unit_plannings(): void
    {
        $admin = $this->adminSite();
        $unit = $this->unit($admin->site_id, 10000, 100);
        $upcomingPlanning = $this->planning($unit, 'Service 20 hari', 20000, today()->addDays(20)->toDateString());
        $preparationPlanning = $this->planning($unit, 'Service 10 hari', 20000, today()->addDays(10)->toDateString());
        $onHoldThresholdPlanning = $this->planning($unit, 'Service 6 hari', 20000, today()->addDays(6)->toDateString());
        $outsidePlanning = $this->planning($unit, 'Service 40 hari', 20000, today()->addDays(40)->toDateString());

        $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/Index')
                ->has('boardColumns.upcoming.data', 1)
                ->where('boardColumns.upcoming.data.0.id', $upcomingPlanning->id)
                ->where('boardColumns.upcoming.meta.per_page', 20)
                ->has('boardColumns.preparation.data', 1)
                ->where('boardColumns.preparation.data.0.id', $preparationPlanning->id)
                ->where('boardColumns.preparation.meta.per_page', 20)
            );

        $this->assertDatabaseMissing('work_order_items', ['unit_planning_id' => $onHoldThresholdPlanning->id]);
        $this->assertDatabaseMissing('work_order_items', ['unit_planning_id' => $outsidePlanning->id]);
    }

    public function test_work_order_board_paginates_each_status_column_to_twenty_items(): void
    {
        $admin = $this->adminSite();

        for ($index = 1; $index <= 25; $index++) {
            $unit = $this->unit($admin->site_id, 10000 + $index, 100);
            $planning = $this->planning($unit, 'Open Item '.$index, 12000 + $index, today()->addDays(5)->toDateString());
            $workOrder = WorkOrder::query()->create([
                'unit_id' => $unit->id,
                'site_id' => $unit->site_id,
                'status' => 'open',
                'trigger_type' => 'manual',
                'submitted_by' => $admin->id,
            ]);

            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => 'on_hold',
                'action' => 'replace',
            ]);
        }

        $firstPage = $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk();

        $firstPage->assertInertia(fn (Assert $page) => $page
            ->has('boardColumns.open.data', 20)
            ->where('boardColumns.open.meta.per_page', 20)
            ->where('boardColumns.open.meta.total', 25)
            ->where('boardColumns.open.meta.current_page', 1)
        );

        $secondPage = $this->actingAs($admin)
            ->get(route('work-orders.index', ['open_page' => 2]))
            ->assertOk();

        $secondPage->assertInertia(fn (Assert $page) => $page
            ->has('boardColumns.open.data', 5)
            ->where('boardColumns.open.meta.current_page', 2)
        );

        $this->assertNotSame(
            $firstPage->viewData('page')['props']['boardColumns']['open']['data'][0]['id'],
            $secondPage->viewData('page')['props']['boardColumns']['open']['data'][0]['id']
        );
    }

    public function test_board_filters_multiple_planning_items_and_can_hide_incomplete_baselines(): void
    {
        $planner = $this->adminSite();

        $completeUnit = $this->unit($planner->site_id, 20000, 100);
        $completeUnit->update(['has_odometer_reading' => true]);
        $priorityPlanning = $this->planning($completeUnit, 'PM Check / Reguler Services', 19000, today()->subDay()->toDateString());
        $priorityPlanning->update(['last_done_km' => 15000]);
        $priorityItem = $this->overdueItemForPlanning($priorityPlanning);

        $incompleteUnit = $this->unit($planner->site_id, 30000, 100);
        $incompleteUnit->update(['has_odometer_reading' => false]);
        $servicePlanning = $this->planning($incompleteUnit, 'Service A', 29000, today()->subDays(2)->toDateString());
        $serviceItem = $this->overdueItemForPlanning($servicePlanning);

        $otherUnit = $this->unit($planner->site_id, 40000, 100);
        $otherPlanning = $this->planning($otherUnit, 'Brake Pad', 39000, today()->subDays(3)->toDateString());
        $this->overdueItemForPlanning($otherPlanning);

        $selectedPlanningItemIds = [$priorityPlanning->planning_item_id, $servicePlanning->planning_item_id];

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['planning_item_ids' => $selectedPlanningItemIds]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 2)
                ->where('filters.planning_item_ids', $selectedPlanningItemIds)
                ->where('filters.include_incomplete_baseline', true)
            );

        $this->actingAs($planner)
            ->get(route('work-orders.index', [
                'planning_item_ids' => $selectedPlanningItemIds,
                'include_incomplete_baseline' => 0,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $priorityItem->id)
                ->where('filters.include_incomplete_baseline', false)
            );

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['item_id' => $servicePlanning->planning_item_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $serviceItem->id)
                ->where('filters.planning_item_ids', [$servicePlanning->planning_item_id])
            );
    }

    public function test_unit_filter_uses_searchable_plate_combobox_and_keeps_unit_in_query_state(): void
    {
        $planner = $this->adminSite();
        $selectedUnit = $this->unit($planner->site_id, 8697, 100);
        $selectedPlanning = $this->planning($selectedUnit, 'Selected Unit Service', 8000, today()->subDay()->toDateString());
        $selectedItem = $this->overdueItemForPlanning($selectedPlanning);

        $otherUnit = $this->unit($planner->site_id, 1234, 100);
        $otherPlanning = $this->planning($otherUnit, 'Other Unit Service', 1200, today()->subDay()->toDateString());
        $this->overdueItemForPlanning($otherPlanning);

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['unit_id' => $selectedUnit->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $selectedItem->id)
                ->where('filters.unit_id', (string) $selectedUnit->id)
            );

        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx'));
        $comboboxSource = file_get_contents(resource_path('js/Components/UnitFilterCombobox.tsx'));

        $this->assertStringContainsString('<UnitFilterCombobox units={units.data} value={unitId} onChange={setUnitId} />', $pageSource);
        $this->assertStringContainsString('unit_id: unitId || undefined', $pageSource);
        $this->assertStringContainsString('ComboboxInput', $comboboxSource);
        $this->assertStringContainsString("unit.current_plate.toLocaleLowerCase('id-ID').includes(normalizedQuery)", $comboboxSource);
    }

    public function test_board_places_every_item_of_one_work_order_in_its_own_card(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $planner->site_id]);
        $unit = $this->unit($planner->site_id, 30000, 100);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
        ]);
        $itemIds = [];

        foreach ([
            ['Flushing Rem', 'on_hold', true],
            ['Brake Pad', 'overdue', false],
            ['Filter Udara', 'rejected', false],
            ['Greasing', 'on_hold', false],
            ['Ban Depan', 'replace', false],
            ['Ban Belakang', 'postpone', false],
            ['Service A', 'in_progress', false],
            ['Engine Check', 'breakdown', false],
            ['Waiting Part', 'blocked', false],
            ['Completed Item', 'complete', false],
            ['Approved Postpone', 'postponed', false],
        ] as [$name, $status, $baselineMissing]) {
            $planning = $this->planning($unit, $name, 31000, today()->addDays(10)->toDateString());

            if ($baselineMissing) {
                $planning->update([
                    'last_done_km' => 0,
                    'last_done_date' => null,
                    'next_due_km' => null,
                    'next_due_date' => null,
                ]);
            }

            $itemIds[$name] = WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => $status,
                'action' => $status === 'rejected'
                    ? 'replace'
                    : (in_array($status, ['replace', 'postpone', 'blocked', 'breakdown'], true) ? $status : null),
            ])->id;
        }

        $response = $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk();

        $onHoldIds = collect($response->inertiaProps('boardColumns.open.data'))->pluck('id');
        $inProgressIds = collect($response->inertiaProps('boardColumns.in_progress.data'))->pluck('id');
        $completeIds = collect($response->inertiaProps('boardColumns.complete.data'))->pluck('id');

        $this->assertSame([$itemIds['Service A']], $inProgressIds->all());
        $this->assertSame([$itemIds['Completed Item']], $completeIds->all());
        $this->assertSame(8, $onHoldIds->count());
        $this->assertEqualsCanonicalizing([
            $itemIds['Flushing Rem'],
            $itemIds['Brake Pad'],
            $itemIds['Filter Udara'],
            $itemIds['Greasing'],
            $itemIds['Ban Depan'],
            $itemIds['Ban Belakang'],
            $itemIds['Engine Check'],
            $itemIds['Waiting Part'],
        ], $onHoldIds->all());

        // Item postponed sudah tuntas untuk siklus ini dan tidak lagi memenuhi board.
        $this->assertFalse($onHoldIds->merge($inProgressIds)->merge($completeIds)->contains($itemIds['Approved Postpone']));
    }

    public function test_unit_with_twenty_items_shows_one_in_progress_card_and_the_rest_on_hold(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $planner->site_id]);
        $unit = $this->unit($planner->site_id, 50000, 100);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
        ]);

        $workedPlanning = $this->planning($unit, 'Service B', 51000, today()->addDays(5)->toDateString());
        $workedItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $workedPlanning->id,
            'planning_item_id' => $workedPlanning->planning_item_id,
            'status' => 'in_progress',
        ]);

        $onHoldItemIds = [];

        for ($index = 1; $index <= 19; $index++) {
            $isBaselineMissing = $index > 10;
            $planning = $this->planning($unit, 'Item Antre '.$index, 49000, today()->subDays($index)->toDateString());

            if ($isBaselineMissing) {
                $planning->update(['last_done_km' => 0, 'last_done_date' => null, 'next_due_km' => null, 'next_due_date' => null]);
            }

            $onHoldItemIds[] = WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => 'on_hold',
            ])->id;
        }

        $response = $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk();

        $inProgressCards = collect($response->inertiaProps('boardColumns.in_progress.data'));
        $onHoldCards = collect($response->inertiaProps('boardColumns.open.data'));

        $this->assertSame([$workedItem->id], $inProgressCards->pluck('id')->all());
        $this->assertSame(19, $onHoldCards->count());
        $this->assertEqualsCanonicalizing($onHoldItemIds, $onHoldCards->pluck('id')->all());
        $this->assertSame([19], $inProgressCards->pluck('other_active_items_count')->unique()->values()->all());
        $this->assertSame([19], $onHoldCards->pluck('other_active_items_count')->unique()->values()->all());
    }

    public function test_completing_one_item_moves_only_that_item_and_keeps_sibling_statuses(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 60000, 100);
        $unit->update(['has_odometer_reading' => true]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);

        $targetPlanning = $this->planning($unit, 'Service A', 59000, today()->subDay()->toDateString());
        $targetPlanning->update(['last_done_km' => 49000]);
        $targetItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $targetPlanning->id,
            'planning_item_id' => $targetPlanning->planning_item_id,
            'status' => 'overdue',
        ]);

        $siblingStatuses = ['on_hold', 'overdue', 'replace', 'postpone', 'blocked', 'rejected'];
        $siblingItems = [];

        foreach ($siblingStatuses as $index => $status) {
            $planning = $this->planning($unit, 'Sibling '.$status, 61000 + $index, today()->addDays(5)->toDateString());
            $siblingItems[] = WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => $status,
                'action' => in_array($status, ['replace', 'postpone', 'blocked'], true) ? $status : ($status === 'rejected' ? 'replace' : null),
            ]);
        }

        $statusesBefore = WorkOrderItem::query()
            ->whereKeyNot($targetItem->id)
            ->pluck('status', 'id')
            ->all();

        $this->actingAs($planner)
            ->post(route('work-orders.items.complete', [$workOrder, $targetItem]), [
                'completed_odo' => 60500,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('complete', $targetItem->refresh()->status);
        $this->assertSame($statusesBefore, WorkOrderItem::query()->whereKeyNot($targetItem->id)->pluck('status', 'id')->all());

        $response = $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk();

        $onHoldIds = collect($response->inertiaProps('boardColumns.open.data'))->pluck('id');
        $inProgressIds = collect($response->inertiaProps('boardColumns.in_progress.data'))->pluck('id');
        $completeIds = collect($response->inertiaProps('boardColumns.complete.data'))->pluck('id');
        $upcomingPlanningIds = collect($response->inertiaProps('boardColumns.upcoming.data'))->pluck('id')
            ->merge(collect($response->inertiaProps('boardColumns.preparation.data'))->pluck('id'));

        $this->assertFalse($onHoldIds->contains($targetItem->id));
        $this->assertFalse($inProgressIds->contains($targetItem->id));
        $this->assertTrue($completeIds->contains($targetItem->id));
        $this->assertEqualsCanonicalizing(
            collect($siblingItems)->pluck('id')->all(),
            $onHoldIds->all(),
            'Semua item lain tetap berada di kolom On Hold.'
        );

        // Item yang selesai baru muncul lagi di preview saat jadwal berikutnya mendekat.
        $this->assertFalse($upcomingPlanningIds->contains($targetPlanning->id));
        $this->assertTrue($targetPlanning->refresh()->next_due_date->greaterThan(today()->addDays(30)));
    }

    /**
     * Regresi model lama (1 card per WO): begitu 1 item di-complete, seluruh WO
     * hilang dari board bersama item yang belum selesai. Di model card per item
     * visibility tiap item harus berdiri sendiri.
     */
    public function test_completing_one_item_does_not_hide_the_other_items_of_the_same_unit(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $planner->site_id]);
        $unit = $this->unit($planner->site_id, 70000, 100);
        $unit->update(['has_odometer_reading' => true]);

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
            'approved_by' => $planner->id,
            'approved_at' => now(),
        ]);

        // Item A: sedang dikerjakan mekanik hari ini.
        $planningA = $this->planning($unit, 'Service A', 71000, today()->addDays(5)->toDateString());
        $planningA->update(['last_done_km' => 61000]);
        $itemA = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planningA->id,
            'planning_item_id' => $planningA->planning_item_id,
            'status' => 'in_progress',
        ]);

        // Item B: overdue 3 hari dan lewat 1.000 KM.
        $planningB = $this->planning($unit, 'Brake Pad', 69000, today()->subDays(3)->toDateString());
        $planningB->update(['last_done_km' => 59000]);
        $itemB = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planningB->id,
            'planning_item_id' => $planningB->planning_item_id,
            'status' => 'overdue',
        ]);

        // Item C: baseline belum diisi, jadi belum punya due sama sekali.
        $planningC = $this->planning($unit, 'Flushing Rem', 72000, today()->addDays(9)->toDateString());
        $planningC->update([
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => null,
            'next_due_date' => null,
        ]);
        $itemC = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planningC->id,
            'planning_item_id' => $planningC->planning_item_id,
            'status' => 'on_hold',
        ]);

        $boardBefore = $this->boardSnapshot($planner);

        $this->assertSame([$itemA->id], $boardBefore['in_progress']->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$itemB->id, $itemC->id], $boardBefore['open']->pluck('id')->all());
        $this->assertSame([], $boardBefore['complete']->pluck('id')->all());

        $cardBBefore = $boardBefore['open']->firstWhere('id', $itemB->id);
        $cardCBefore = $boardBefore['open']->firstWhere('id', $itemC->id);

        // Mekanik klik Complete pada item A saja.
        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $itemA]), [
                'completed_odo' => 70500,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $itemA->refresh()->status);
        $this->assertSame('overdue', $itemB->refresh()->status);
        $this->assertSame('on_hold', $itemC->refresh()->status);

        $boardAfter = $this->boardSnapshot($planner);

        // Item A pindah ke Complete, dan hanya item A.
        $this->assertSame([$itemA->id], $boardAfter['complete']->pluck('id')->all());
        $this->assertSame(1, $boardAfter['completeTotal']);
        $this->assertSame([], $boardAfter['in_progress']->pluck('id')->all());

        // Item B dan C tetap di On Hold, bukan 0 dan bukan hilang bersama WO.
        $this->assertEqualsCanonicalizing(
            [$itemB->id, $itemC->id],
            $boardAfter['open']->pluck('id')->all(),
            'Item lain di unit yang sama tetap muncul di kolom On Hold.'
        );
        $this->assertSame(2, $boardAfter['openTotal']);

        $cardBAfter = $boardAfter['open']->firstWhere('id', $itemB->id);
        $cardCAfter = $boardAfter['open']->firstWhere('id', $itemC->id);

        // Item B: status overdue-nya persis sama seperti sebelum item A selesai.
        $this->assertSame('overdue', $cardBAfter['status']);
        $this->assertSame('on_hold', $cardBAfter['phase']);
        $this->assertSame('red', $cardBAfter['due']['level']);
        $this->assertSame(3, $cardBAfter['due']['overdue_days']);
        $this->assertSame(1000, $cardBAfter['due']['overdue_km']);
        $this->assertFalse($cardBAfter['baseline_missing']);
        $this->assertContains('Terlambat 3 hari', collect($cardBAfter['badges'])->pluck('label')->all());
        $this->assertSame($cardBBefore['badges'], $cardBAfter['badges']);
        $this->assertSame($cardBBefore['due'], $cardBAfter['due']);

        // Item C: tetap ditandai baseline belum diisi, tanpa due palsu.
        $this->assertSame('on_hold', $cardCAfter['status']);
        $this->assertSame('on_hold', $cardCAfter['phase']);
        $this->assertTrue($cardCAfter['baseline_missing']);
        $this->assertNull($cardCAfter['due']);
        $this->assertContains('Data awal belum diisi', collect($cardCAfter['badges'])->pluck('label')->all());
        $this->assertSame($cardCBefore['badges'], $cardCAfter['badges']);

        // Sisa item aktif dihitung per unit, bukan per WO.
        $this->assertSame([1], $boardAfter['open']->pluck('other_active_items_count')->unique()->values()->all());

        // work_orders.status ikut berubah setelah sebagian item selesai, tapi tidak
        // boleh dipakai sebagai filter yang menyembunyikan item B dan C.
        $this->assertSame('open', $workOrder->refresh()->status);

        $workOrder->update(['status' => 'complete']);

        $boardWithCompletedWorkOrder = $this->boardSnapshot($planner);

        $this->assertEqualsCanonicalizing(
            [$itemB->id, $itemC->id],
            $boardWithCompletedWorkOrder['open']->pluck('id')->all(),
            'work_orders.status = complete tidak boleh menyembunyikan item yang belum selesai.'
        );
        $this->assertSame(2, $boardWithCompletedWorkOrder['openTotal']);
        $this->assertSame([$itemA->id], $boardWithCompletedWorkOrder['complete']->pluck('id')->all());
    }

    public function test_board_cards_hide_work_order_numbers_and_use_plain_language_badges(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $planner->site_id, 'name' => 'Budi']);
        $unit = $this->unit($planner->site_id, 30000, 100);
        $latePlanning = $this->planning($unit, 'Brake Pad', 31000, today()->subDays(3)->toDateString());
        $baselinePlanning = $this->planning($unit, 'Flushing Rem', 31000, today()->addDays(5)->toDateString());
        $baselinePlanning->update(['last_done_km' => 0, 'last_done_date' => null, 'next_due_km' => null, 'next_due_date' => null]);
        $approvalPlanning = $this->planning($unit, 'Ban Depan', 31000, today()->addDays(5)->toDateString());
        $scheduledPlanning = $this->planning($unit, 'Service A', 31000, today()->addDays(5)->toDateString());

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->addDays(2)->toDateString(),
        ]);

        $lateItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $latePlanning->id, 'planning_item_id' => $latePlanning->planning_item_id, 'status' => 'on_hold']);
        $baselineItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $baselinePlanning->id, 'planning_item_id' => $baselinePlanning->planning_item_id, 'status' => 'on_hold']);
        $approvalItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $approvalPlanning->id, 'planning_item_id' => $approvalPlanning->planning_item_id, 'status' => 'replace', 'action' => 'replace']);
        $scheduledItem = WorkOrderItem::query()->create(['work_order_id' => $workOrder->id, 'unit_planning_id' => $scheduledPlanning->id, 'planning_item_id' => $scheduledPlanning->planning_item_id, 'status' => 'in_progress']);

        $cards = collect($this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->inertiaProps('boardColumns.open.data'))->keyBy('id');

        $labelsFor = fn (int $itemId): array => collect($cards[$itemId]['badges'])->pluck('label')->all();

        $this->assertContains('Terlambat 3 hari', $labelsFor($lateItem->id));
        $this->assertContains('Data awal belum diisi', $labelsFor($baselineItem->id));
        $this->assertContains('Menunggu persetujuan', $labelsFor($approvalItem->id));
        $this->assertContains('Budi - '.today()->addDays(2)->toDateString(), $labelsFor($scheduledItem->id));
        $this->assertSame('Brake Pad', $cards[$lateItem->id]['item_name']);
        $this->assertSame($unit->current_plate, $cards[$lateItem->id]['unit_plate']);

        foreach ($cards as $card) {
            $this->assertSame(3, $card['other_active_items_count']);
            $this->assertStringNotContainsString('Overdue', implode(' ', collect($card['badges'])->pluck('label')->all()));
        }

        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx'));

        $this->assertStringNotContainsString('WO #', $pageSource);
        $this->assertStringContainsString('Unit ini juga punya {item.other_active_items_count} item lain yang perlu ditindak', $pageSource);
        $this->assertStringContainsString("route('work-orders.show', item.work_order_id)", $pageSource);
    }

    public function test_single_item_unit_card_omits_other_items_hint(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 30000, 100);
        $planning = $this->planning($unit, 'Brake Pad', 31000, today()->addDays(5)->toDateString());
        $item = $this->overdueItemForPlanning($planning);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.open.data.0.id', $item->id)
                ->where('boardColumns.open.data.0.other_active_items_count', 0)
            );
    }

    public function test_board_defaults_to_priority_and_supports_due_date_and_due_km_sorting(): void
    {
        $planner = $this->adminSite();

        $regularUnit = $this->unit($planner->site_id, 50000, 100);
        $regularPlanning = $this->planning($regularUnit, 'Brake Shoe', 51000, today()->addDay()->toDateString());
        $regularItem = $this->overdueItemForPlanning($regularPlanning);

        $priorityUnit = $this->unit($planner->site_id, 60000, 100);
        $priorityPlanning = $this->planning($priorityUnit, 'Service B', 65000, today()->addDays(5)->toDateString());
        $priorityItem = $this->overdueItemForPlanning($priorityPlanning);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.open.data.0.id', $priorityItem->id)
                ->where('boardColumns.open.data.0.is_priority', true)
                ->where('boardColumns.open.data.0.item_name', 'Service B')
                ->where('filters.sort_by', 'priority')
            );

        foreach (['due_date', 'due_km'] as $sortBy) {
            $this->actingAs($planner)
                ->get(route('work-orders.index', ['sort_by' => $sortBy]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('boardColumns.open.data.0.id', $regularItem->id)
                    ->where('filters.sort_by', $sortBy)
                );
        }
    }

    public function test_board_uses_contextual_empty_states_and_links_in_progress_to_on_hold(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx'));

        $this->assertStringContainsString('const emptyColumnConfig: Record<ColumnKey, EmptyColumnConfig>', $pageSource);
        $this->assertStringContainsString('Belum ada task Upcoming', $pageSource);
        $this->assertStringContainsString('Belum ada task Ancang-ancang', $pageSource);
        $this->assertStringContainsString('Belum ada pekerjaan yang perlu ditindak', $pageSource);
        $this->assertStringContainsString('Belum ada pekerjaan yang sedang dikerjakan', $pageSource);
        $this->assertStringContainsString('Pekerjaan pindah ke sini saat mekanik sudah mulai mengerjakannya sesuai tanggal rencana.', $pageSource);
        $this->assertStringContainsString('Belum ada pekerjaan yang selesai', $pageSource);
        $this->assertStringContainsString("label: 'Lihat kolom On Hold'", $pageSource);
        $this->assertStringContainsString("targetColumn: 'open'", $pageSource);
        $this->assertStringContainsString("element.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });", $pageSource);
        $this->assertStringContainsString("highlightedColumn === columnKey && 'ring-2 ring-primary ring-offset-2 ring-offset-background'", $pageSource);
        $this->assertStringContainsString('<EmptyColumn config={emptyColumnConfig[columnKey]} onNavigate={focusColumn} />', $pageSource);
    }

    public function test_preview_columns_prioritize_critical_items_and_support_due_date_sorting(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 10000, 100);
        $regularPlanning = $this->planning($unit, 'Brake Pad', 14000, today()->addDays(18)->toDateString());
        $priorityPlanning = $this->planning($unit, 'Service A', 15000, today()->addDays(22)->toDateString());
        $selectedPlanningItemIds = [$regularPlanning->planning_item_id, $priorityPlanning->planning_item_id];

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['planning_item_ids' => $selectedPlanningItemIds]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.upcoming.data', 2)
                ->where('boardColumns.upcoming.data.0.id', $priorityPlanning->id)
                ->where('boardColumns.upcoming.data.0.is_priority', true)
            );

        $this->actingAs($planner)
            ->get(route('work-orders.index', [
                'planning_item_ids' => $selectedPlanningItemIds,
                'sort_by' => 'due_date',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.upcoming.data.0.id', $regularPlanning->id)
                ->where('boardColumns.upcoming.data.0.is_priority', false)
            );
    }

    public function test_work_order_detail_exposes_current_odometer_and_incomplete_baseline_context(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 104321, 100);
        $planning = $this->planning($unit, 'Filter Oli', 110000, today()->addDays(10)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkOrders/Show')
                ->where('workOrder.data.unit.current_odo', 104321)
                ->where('workOrder.data.unit.baseline_incomplete', true)
            );
    }

    public function test_planner_area_request_from_preview_card_waits_for_spv_approval(): void
    {
        $admin = $this->adminSite();
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Engine Oil', 12000, today()->addDays(10)->toDateString());

        $this->actingAs($admin)
            ->post(route('unit-plannings.create-work-order', $planning))
            ->assertRedirect();

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->assertSame('open', $workOrder->status);
        $this->assertDatabaseHas('work_orders', [
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'submitted_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'pending_create',
            'action' => 'create_task',
        ]);

        $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 0)
                ->has('boardColumns.preparation.data', 1)
                ->where('boardColumns.preparation.data.0.id', $planning->id)
                ->where('boardColumns.preparation.data.0.approval_status', 'pending_create')
            );

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('open', $workOrder->refresh()->status);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'status' => 'on_hold',
            'approved_by' => $spv->id,
        ]);
    }

    public function test_spv_can_reject_preview_task_request_and_preview_returns(): void
    {
        $admin = $this->adminSite();
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Air Filter', 12000, today()->addDays(10)->toDateString());

        $this->actingAs($admin)
            ->post(route('unit-plannings.create-work-order', $planning))
            ->assertRedirect();

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->actingAs($spv)
            ->post(route('work-orders.reject', $workOrder))
            ->assertRedirect(route('work-orders.index'));

        $this->assertSame('cancelled', $workOrder->refresh()->status);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'status' => 'rejected',
            'approved_by' => $spv->id,
        ]);

        $this->actingAs($admin)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.preparation.data', 1)
                ->where('boardColumns.preparation.data.0.id', $planning->id)
                ->where('boardColumns.preparation.data.0.approval_status', 'rejected')
            );
    }

    public function test_create_task_now_can_include_mechanic_and_schedule_before_approval(): void
    {
        $admin = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $admin->site_id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Hydraulic Filter', 12000, today()->addDays(10)->toDateString());
        $scheduledDate = today()->addDays(3)->toDateString();

        $this->actingAs($admin)
            ->post(route('unit-plannings.create-work-order', $planning), [
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => $scheduledDate,
            ])
            ->assertRedirect();

        $workOrder = WorkOrder::query()->where('unit_id', $unit->id)->firstOrFail();

        $this->assertSame($mechanic->id, $workOrder->assigned_mechanic_id);
        $this->assertSame($scheduledDate, $workOrder->scheduled_date->toDateString());

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertDatabaseHas('work_order_items', [
            'unit_planning_id' => $planning->id,
            'status' => 'in_progress',
            'approved_by' => $spv->id,
        ]);
    }

    public function test_planner_area_can_assign_same_site_mechanic_to_approved_in_progress_work_order(): void
    {
        $admin = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $admin->site_id]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Brake', 12000, today()->addDays(5)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'submitted_by' => $admin->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'in_progress',
            'action' => 'replace',
        ]);

        $this->actingAs($admin)
            ->post(route('work-orders.assign-mechanic', $workOrder), [
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame($mechanic->id, $workOrder->refresh()->assigned_mechanic_id);
        $this->assertSame(today()->addDay()->toDateString(), $workOrder->scheduled_date->toDateString());
    }

    public function test_assign_mechanic_rejects_past_date(): void
    {
        $admin = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $admin->site_id]);
        $unit = $this->unit($admin->site_id, 10000, 100);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'approved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('work-orders.index'))
            ->post(route('work-orders.assign-mechanic', $workOrder), [
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('scheduled_date');
    }

    public function test_planner_can_submit_actions_for_overdue_items(): void
    {
        $planner = $this->adminSite();
        $replaceItem = $this->overdueWorkOrderItem($planner, 'Replace Item');
        $postponeItem = $this->overdueWorkOrderItem($planner, 'Postpone Item');
        $blockedItem = $this->overdueWorkOrderItem($planner, 'Blocked Item');
        $breakdownItem = $this->overdueWorkOrderItem($planner, 'Breakdown Item');

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$replaceItem->workOrder, $replaceItem]), ['reason' => 'Butuh replace'])
            ->assertRedirect(route('work-orders.show', $replaceItem->workOrder));

        $this->assertSame('replace', $replaceItem->refresh()->status);

        $this->actingAs($planner)
            ->post(route('work-orders.items.postpone', [$postponeItem->workOrder, $postponeItem]), [
                'reason' => 'Tunggu jadwal',
                'new_due_km' => 25000,
                'new_due_date' => today()->addWeek()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $postponeItem->workOrder));

        $this->assertSame('postpone', $postponeItem->refresh()->status);

        $this->actingAs($planner)
            ->post(route('work-order-items.blocked', $blockedItem), ['reason' => 'Menunggu part'])
            ->assertRedirect();

        $this->assertSame('blocked', $blockedItem->refresh()->status);

        $this->actingAs($planner)
            ->post(route('units.breakdown', $breakdownItem->workOrder->unit), ['reason' => 'Unit breakdown'])
            ->assertRedirect();

        $this->assertSame('breakdown', $breakdownItem->refresh()->status);
    }

    public function test_completed_item_leaves_the_active_columns_while_sibling_stays(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 10000, 100);
        $completePlanning = $this->planning($unit, 'Completed Service', 12000, today()->addDays(10)->toDateString());
        $overduePlanning = $this->planning($unit, 'Remaining Service', 9000, today()->subDay()->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);

        $completeItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $completePlanning->id,
            'planning_item_id' => $completePlanning->planning_item_id,
            'status' => 'complete',
            'completed_date' => today()->toDateString(),
            'completed_odo' => 10000,
        ]);
        $overdueItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $overduePlanning->id,
            'planning_item_id' => $overduePlanning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $overdueItem->id)
                ->where('boardColumns.open.data.0.other_active_items_count', 0)
                ->has('boardColumns.in_progress.data', 0)
                ->has('boardColumns.complete.data', 1)
                ->where('boardColumns.complete.data.0.id', $completeItem->id)
                ->where('boardColumns.complete.data.0.badges.0.label', 'Selesai '.today()->toDateString())
            );
    }

    public function test_item_is_in_progress_only_after_the_scheduled_mechanic_day_arrives(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create([
            'role' => UserRole::Mekanik,
            'site_id' => $planner->site_id,
            'name' => 'Mekanik Dengan Nama Sangat Panjang Untuk Uji Ellipsis',
        ]);
        $unit = $this->unit($planner->site_id, 10000, 100);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
        ]);
        $planning = $this->planning($unit, 'Belum Disentuh E', 12000, today()->addDays(10)->toDateString());
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 0)
                ->has('boardColumns.in_progress.data', 1)
                ->where('boardColumns.in_progress.data.0.id', $item->id)
                ->where('boardColumns.in_progress.data.0.phase', 'in_progress')
                ->where('boardColumns.in_progress.data.0.badges.0.label', 'Sedang dikerjakan')
            );

        $workOrder->update(['scheduled_date' => today()->addDays(3)->toDateString()]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.in_progress.data', 0)
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $item->id)
                ->where('boardColumns.open.data.0.badges.0.label', $mechanic->name.' - '.today()->addDays(3)->toDateString())
            );

        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_complete_work_order_still_shows_its_unfinished_item_in_on_hold(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 10000, 100);
        $completePlanning = $this->planning($unit, 'Completed Service', 12000, today()->addDays(10)->toDateString());
        $overduePlanning = $this->planning($unit, 'Overdue Service', 9000, today()->subDay()->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'complete',
            'trigger_type' => 'normal',
        ]);

        $completeItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $completePlanning->id,
            'planning_item_id' => $completePlanning->planning_item_id,
            'status' => 'complete',
        ]);
        $overdueItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $overduePlanning->id,
            'planning_item_id' => $overduePlanning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.complete.data', 1)
                ->where('boardColumns.complete.data.0.id', $completeItem->id)
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $overdueItem->id)
            );

        $this->artisan('work-orders:audit-statuses')
            ->expectsOutput('Mismatched work orders: 0')
            ->assertSuccessful();

        $this->artisan('work-orders:audit-statuses --fix')
            ->expectsOutput('Mismatched work orders: 0')
            ->assertSuccessful();

        $this->assertSame('complete', $workOrder->refresh()->status);
    }

    public function test_quick_search_matches_partial_plate_across_every_column(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $planner->site_id]);

        $targetUnit = $this->unit($planner->site_id, 8473, 100);
        $targetUnit->update(['current_plate' => 'KT 8473 ZH']);
        $otherUnit = $this->unit($planner->site_id, 1200, 100);
        $otherUnit->update(['current_plate' => 'KT 9911 AB']);

        $targetWorkOrder = WorkOrder::query()->create([
            'unit_id' => $targetUnit->id,
            'site_id' => $targetUnit->site_id,
            'status' => 'in_progress',
            'trigger_type' => 'normal',
            'assigned_mechanic_id' => $mechanic->id,
            'scheduled_date' => today()->toDateString(),
        ]);

        $targetOnHold = WorkOrderItem::query()->create([
            'work_order_id' => $targetWorkOrder->id,
            'unit_planning_id' => $this->planning($targetUnit, 'Brake Pad', 9000, today()->addDays(5)->toDateString())->id,
            'planning_item_id' => PlanningItem::query()->where('name', 'Brake Pad')->value('id'),
            'status' => 'on_hold',
        ]);
        $targetInProgress = WorkOrderItem::query()->create([
            'work_order_id' => $targetWorkOrder->id,
            'unit_planning_id' => $this->planning($targetUnit, 'Greasing', 9000, today()->addDays(5)->toDateString())->id,
            'planning_item_id' => PlanningItem::query()->where('name', 'Greasing')->value('id'),
            'status' => 'in_progress',
        ]);
        $targetComplete = WorkOrderItem::query()->create([
            'work_order_id' => $targetWorkOrder->id,
            'unit_planning_id' => $this->planning($targetUnit, 'Filter Oli', 9000, today()->addDays(5)->toDateString())->id,
            'planning_item_id' => PlanningItem::query()->where('name', 'Filter Oli')->value('id'),
            'status' => 'complete',
            'completed_date' => today()->toDateString(),
        ]);

        $otherItem = $this->overdueItemForPlanning($this->planning($otherUnit, 'Ban Depan', 1100, today()->subDay()->toDateString()));

        foreach (['8473', 'kt 8473', 'KT8473'] as $term) {
            $response = $this->actingAs($planner)
                ->get(route('work-orders.index', ['search' => $term]))
                ->assertOk();

            $this->assertSame([$targetOnHold->id], collect($response->inertiaProps('boardColumns.open.data'))->pluck('id')->all(), $term);
            $this->assertSame([$targetInProgress->id], collect($response->inertiaProps('boardColumns.in_progress.data'))->pluck('id')->all(), $term);
            $this->assertSame([$targetComplete->id], collect($response->inertiaProps('boardColumns.complete.data'))->pluck('id')->all(), $term);
        }

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['search' => '8473']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.search', '8473'));

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', '')
                ->where('boardColumns.open.data', fn ($items): bool => collect($items)->pluck('id')->contains($otherItem->id))
            );
    }

    public function test_quick_search_matches_item_name_regardless_of_letter_case(): void
    {
        $planner = $this->adminSite();
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $planner->site_id]);
        $serviceB = PlanningItem::query()->create(['name' => 'Service B', 'interval_km' => 10000, 'interval_days' => 180]);

        $matchingItemIds = [];

        foreach ([['in_progress', 5000], ['on_hold', 6000]] as $index => [$status, $odo]) {
            $unit = $this->unit($planner->site_id, $odo, 100);
            $planning = UnitPlanning::query()->updateOrCreate(
                ['unit_id' => $unit->id, 'planning_item_id' => $serviceB->id],
                [
                    'last_done_km' => 0,
                    'last_done_date' => today()->subDays(180)->toDateString(),
                    'next_due_km' => $odo + 1000,
                    'next_due_date' => today()->addDays(5)->toDateString(),
                ],
            );
            $workOrder = WorkOrder::query()->create([
                'unit_id' => $unit->id,
                'site_id' => $unit->site_id,
                'status' => 'in_progress',
                'trigger_type' => 'normal',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->toDateString(),
            ]);

            $matchingItemIds[$status] = WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $serviceB->id,
                'status' => $status,
            ])->id;

            $otherPlanning = $this->planning($unit, 'Ban Belakang '.$index, $odo + 900, today()->addDays(5)->toDateString());
            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $otherPlanning->id,
                'planning_item_id' => $otherPlanning->planning_item_id,
                'status' => 'on_hold',
            ]);
        }

        $response = $this->actingAs($planner)
            ->get(route('work-orders.index', ['search' => 'service b']))
            ->assertOk();

        $this->assertSame([$matchingItemIds['on_hold']], collect($response->inertiaProps('boardColumns.open.data'))->pluck('id')->all());
        $this->assertSame([$matchingItemIds['in_progress']], collect($response->inertiaProps('boardColumns.in_progress.data'))->pluck('id')->all());
    }

    public function test_quick_search_stacks_with_existing_dropdown_filters(): void
    {
        $planner = User::factory()->create(['role' => UserRole::Superadmin]);
        $firstSite = Site::query()->create(['name' => 'Site Satu', 'region' => 'East']);
        $secondSite = Site::query()->create(['name' => 'Site Dua', 'region' => 'East']);

        SystemThreshold::query()->updateOrCreate(['key' => 'warning_days'], ['value' => '7']);
        SystemThreshold::query()->updateOrCreate(['key' => 'warning_km'], ['value' => '1000']);

        $firstUnit = $this->unit($firstSite->id, 7100, 100);
        $firstUnit->update(['current_plate' => 'KT 8473 ZH']);
        $secondUnit = $this->unit($secondSite->id, 7200, 100);
        $secondUnit->update(['current_plate' => 'KT 8473 QQ']);

        $firstItem = $this->overdueItemForPlanning($this->planning($firstUnit, 'Brake Pad Satu', 7000, today()->subDay()->toDateString()));
        $secondItem = $this->overdueItemForPlanning($this->planning($secondUnit, 'Brake Pad Dua', 7000, today()->subDay()->toDateString()));

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['search' => '8473']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 2)
                ->where('filters.search', '8473')
            );

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['search' => '8473', 'site_id' => $firstSite->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $firstItem->id)
                ->where('filters.search', '8473')
                ->where('filters.site_id', (string) $firstSite->id)
            );

        $this->assertNotSame($firstItem->id, $secondItem->id);
    }

    public function test_search_box_filters_the_board_realtime_with_debounce_and_explains_empty_results(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx'));

        $this->assertStringContainsString('placeholder="Cari unit atau item, misal: KT 8473 atau Service B"', $pageSource);
        $this->assertStringContainsString('onChange={(event) => setSearch(event.target.value)}', $pageSource);
        $this->assertStringContainsString('}, 300);', $pageSource);
        $this->assertStringContainsString("only: ['boardColumns', 'filters']", $pageSource);
        $this->assertStringContainsString("title: `Tidak ada hasil untuk '\${appliedSearch}'`", $pageSource);
        $this->assertStringContainsString('search: search || undefined', $pageSource);
        $this->assertStringContainsString('<UnitFilterCombobox units={units.data} value={unitId} onChange={setUnitId} />', $pageSource);
        $this->assertStringContainsString('<MaintenanceItemFilter items={planningItems} selectedIds={planningItemIds} onChange={setPlanningItemIds} />', $pageSource);
    }

    public function test_search_without_matches_returns_empty_columns_and_keeps_the_keyword(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 3000, 100);
        $this->overdueItemForPlanning($this->planning($unit, 'Brake Pad', 2900, today()->subDay()->toDateString()));

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['search' => 'tidak-ada-plat-ini']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 0)
                ->has('boardColumns.in_progress.data', 0)
                ->has('boardColumns.complete.data', 0)
                ->where('filters.search', 'tidak-ada-plat-ini')
            );
    }

    /**
     * @return array{open: Collection<int, array<string, mixed>>, in_progress: Collection<int, array<string, mixed>>, complete: Collection<int, array<string, mixed>>, openTotal: int, completeTotal: int}
     */
    private function boardSnapshot(User $user): array
    {
        $response = $this->actingAs($user)
            ->get(route('work-orders.index'))
            ->assertOk();

        return [
            'open' => collect($response->inertiaProps('boardColumns.open.data')),
            'in_progress' => collect($response->inertiaProps('boardColumns.in_progress.data')),
            'complete' => collect($response->inertiaProps('boardColumns.complete.data')),
            'openTotal' => (int) $response->inertiaProps('boardColumns.open.meta.total'),
            'completeTotal' => (int) $response->inertiaProps('boardColumns.complete.meta.total'),
        ];
    }

    private function adminSite(): User
    {
        $site = Site::query()->create(['name' => 'Site A', 'region' => 'East']);

        SystemThreshold::query()->updateOrCreate(['key' => 'warning_days'], ['value' => '7']);
        SystemThreshold::query()->updateOrCreate(['key' => 'warning_km'], ['value' => '1000']);
        SystemThreshold::query()->updateOrCreate(['key' => 'ancang_ancang_days'], ['value' => '14']);
        SystemThreshold::query()->updateOrCreate(['key' => 'ancang_ancang_km'], ['value' => '2000']);
        SystemThreshold::query()->updateOrCreate(['key' => 'upcoming_days'], ['value' => '28']);
        SystemThreshold::query()->updateOrCreate(['key' => 'upcoming_km'], ['value' => '4000']);

        return User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
    }

    private function unit(int $siteId, int $currentOdo, int $avgKmPerDay): Unit
    {
        return Unit::query()->create([
            'site_id' => $siteId,
            'customer' => 'Customer',
            'current_plate' => 'KT '.$currentOdo.' AA',
            'type' => 'Truck',
            'brand' => 'Hino',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'avg_km_per_day' => $avgKmPerDay,
            'status' => 'active',
        ]);
    }

    private function planning(Unit $unit, string $name, int $nextDueKm, string $nextDueDate): UnitPlanning
    {
        $item = PlanningItem::query()->create([
            'name' => $name,
            'interval_km' => 10000,
            'interval_days' => 180,
        ]);

        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $item->id,
            'last_done_km' => 0,
            'last_done_date' => today()->subDays(180)->toDateString(),
            'next_due_km' => $nextDueKm,
            'next_due_date' => $nextDueDate,
        ]);
    }

    private function overdueWorkOrderItem(User $planner, string $name): WorkOrderItem
    {
        $unit = $this->unit($planner->site_id, fake()->unique()->numberBetween(10000, 90000), 100);
        $planning = $this->planning($unit, $name, $unit->current_odo - 100, today()->subDay()->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue',
        ])->load('workOrder.unit');
    }

    private function overdueItemForPlanning(UnitPlanning $planning): WorkOrderItem
    {
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $planning->unit_id,
            'site_id' => $planning->unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue',
        ]);
    }
}
