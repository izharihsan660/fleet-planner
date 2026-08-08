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
        $priorityWorkOrder = $this->workOrderForPlanning($priorityPlanning);

        $incompleteUnit = $this->unit($planner->site_id, 30000, 100);
        $incompleteUnit->update(['has_odometer_reading' => false]);
        $servicePlanning = $this->planning($incompleteUnit, 'Service A', 29000, today()->subDays(2)->toDateString());
        $serviceWorkOrder = $this->workOrderForPlanning($servicePlanning);

        $otherUnit = $this->unit($planner->site_id, 40000, 100);
        $otherPlanning = $this->planning($otherUnit, 'Brake Pad', 39000, today()->subDays(3)->toDateString());
        $this->workOrderForPlanning($otherPlanning);

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
                ->where('boardColumns.open.data.0.id', $priorityWorkOrder->id)
                ->where('filters.include_incomplete_baseline', false)
            );

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['item_id' => $servicePlanning->planning_item_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $serviceWorkOrder->id)
                ->where('filters.planning_item_ids', [$servicePlanning->planning_item_id])
            );
    }

    public function test_unit_filter_uses_searchable_plate_combobox_and_keeps_unit_in_query_state(): void
    {
        $planner = $this->adminSite();
        $selectedUnit = $this->unit($planner->site_id, 8697, 100);
        $selectedPlanning = $this->planning($selectedUnit, 'Selected Unit Service', 8000, today()->subDay()->toDateString());
        $selectedWorkOrder = $this->workOrderForPlanning($selectedPlanning);

        $otherUnit = $this->unit($planner->site_id, 1234, 100);
        $otherPlanning = $this->planning($otherUnit, 'Other Unit Service', 1200, today()->subDay()->toDateString());
        $this->workOrderForPlanning($otherPlanning);

        $this->actingAs($planner)
            ->get(route('work-orders.index', ['unit_id' => $selectedUnit->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('boardColumns.open.data', 1)
                ->where('boardColumns.open.data.0.id', $selectedWorkOrder->id)
                ->where('filters.unit_id', (string) $selectedUnit->id)
            );

        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx'));
        $comboboxSource = file_get_contents(resource_path('js/Components/UnitFilterCombobox.tsx'));

        $this->assertStringContainsString('<UnitFilterCombobox units={units.data} value={unitId} onChange={setUnitId} />', $pageSource);
        $this->assertStringContainsString('unit_id: unitId || undefined', $pageSource);
        $this->assertStringContainsString('ComboboxInput', $comboboxSource);
        $this->assertStringContainsString("unit.current_plate.toLocaleLowerCase('id-ID').includes(normalizedQuery)", $comboboxSource);
    }

    public function test_board_exposes_mixed_work_order_item_counts(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 30000, 100);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);

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

            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => $status,
                'action' => in_array($status, ['replace', 'postpone', 'blocked', 'breakdown'], true) ? $status : null,
            ]);
        }

        $response = $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.open.data.0.id', $workOrder->id)
            ->where('boardColumns.open.data.0.items_count', 11)
            ->where('boardColumns.open.data.0.completed_items_count', 2)
            ->where('boardColumns.open.data.0.remaining_items_count', 9)
            ->where('boardColumns.open.data.0.blocked_items_count', 1)
            ->where('boardColumns.open.data.0.is_direct_completion_ready', false)
            ->where('boardColumns.open.data.0.overdue_items_count', 1)
            ->where('boardColumns.open.data.0.rejected_items_count', 1)
            ->where('boardColumns.open.data.0.baseline_incomplete_items_count', 1)
        );

        $card = collect($response->inertiaProps('boardColumns.open.data'))->firstWhere('id', $workOrder->id);
        $breakdown = collect($card['active_item_breakdown']);

        $this->assertSame($card['remaining_items_count'], $breakdown->sum('count'));
        $this->assertSame([
            'overdue' => 1,
            'rejected' => 1,
            'baseline_incomplete' => 1,
            'in_progress' => 1,
            'on_hold' => 1,
            'replace' => 1,
            'postpone' => 1,
            'breakdown' => 1,
            'blocked' => 1,
        ], $breakdown->pluck('count', 'key')->all());

        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Index.tsx'));

        $this->assertStringContainsString('`Tinjau ${remainingItems} Item →`', $pageSource);
        $this->assertStringContainsString('`Selesaikan ${remainingItems} Item →`', $pageSource);
        $this->assertStringNotContainsString('Lihat & Selesaikan', $pageSource);
    }

    public function test_board_defaults_to_priority_and_supports_due_date_and_due_km_sorting(): void
    {
        $planner = $this->adminSite();

        $regularUnit = $this->unit($planner->site_id, 50000, 100);
        $regularPlanning = $this->planning($regularUnit, 'Brake Shoe', 51000, today()->addDay()->toDateString());
        $regularWorkOrder = $this->workOrderForPlanning($regularPlanning);

        $priorityUnit = $this->unit($planner->site_id, 60000, 100);
        $priorityPlanning = $this->planning($priorityUnit, 'Service B', 65000, today()->addDays(5)->toDateString());
        $priorityWorkOrder = $this->workOrderForPlanning($priorityPlanning);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.open.data.0.id', $priorityWorkOrder->id)
                ->where('boardColumns.open.data.0.has_priority_items', true)
                ->where('boardColumns.open.data.0.planning_item_names.0', 'Service B')
                ->where('filters.sort_by', 'priority')
            );

        foreach (['due_date', 'due_km'] as $sortBy) {
            $this->actingAs($planner)
                ->get(route('work-orders.index', ['sort_by' => $sortBy]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('boardColumns.open.data.0.id', $regularWorkOrder->id)
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
        $this->assertStringContainsString('Belum ada WO yang menunggu tindak lanjut', $pageSource);
        $this->assertStringContainsString('Belum ada WO yang aktif dikerjakan', $pageSource);
        $this->assertStringContainsString('WO akan pindah ke sini setelah item di-approve Spv HO dan berstatus in_progress.', $pageSource);
        $this->assertStringContainsString('Belum ada WO yang selesai', $pageSource);
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

    public function test_work_order_card_exposes_total_and_remaining_item_counts(): void
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

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $completePlanning->id,
            'planning_item_id' => $completePlanning->planning_item_id,
            'status' => 'complete',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $overduePlanning->id,
            'planning_item_id' => $overduePlanning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.open.data.0.id', $workOrder->id)
                ->where('boardColumns.open.data.0.items_count', 2)
                ->where('boardColumns.open.data.0.completed_items_count', 1)
                ->where('boardColumns.open.data.0.remaining_items_count', 1)
            );
    }

    public function test_work_order_board_exposes_multi_item_progress_and_assigned_mechanic_meta(): void
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

        foreach ([
            'Complete A' => 'complete',
            'Complete B' => 'complete',
            'Postpone C' => 'postponed',
            'Blocked D' => 'blocked',
            'Belum Disentuh E' => 'in_progress',
        ] as $name => $status) {
            $planning = $this->planning($unit, $name, 12000, today()->addDays(10)->toDateString());

            WorkOrderItem::query()->create([
                'work_order_id' => $workOrder->id,
                'unit_planning_id' => $planning->id,
                'planning_item_id' => $planning->planning_item_id,
                'status' => $status,
                'action' => $status === 'postponed' ? 'postpone' : null,
            ]);
        }

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.in_progress.data.0.id', $workOrder->id)
                ->where('boardColumns.in_progress.data.0.items_count', 5)
                ->where('boardColumns.in_progress.data.0.completed_items_count', 3)
                ->where('boardColumns.in_progress.data.0.remaining_items_count', 2)
                ->where('boardColumns.in_progress.data.0.is_direct_completion_ready', false)
                ->where('boardColumns.in_progress.data.0.sub_status.key', 'assigned')
                ->where('boardColumns.in_progress.data.0.sub_status.label', 'Mekanik: '.$mechanic->name)
                ->where('boardColumns.in_progress.data.0.assigned_mechanic.name', $mechanic->name)
            );
    }

    public function test_complete_column_keeps_total_item_count_instead_of_zero(): void
    {
        $planner = $this->adminSite();
        $unit = $this->unit($planner->site_id, 10000, 100);
        $planning = $this->planning($unit, 'Greasing', 12000, today()->addDays(10)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'status' => 'complete',
            'trigger_type' => 'normal',
        ]);

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'complete',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.complete.data.0.id', $workOrder->id)
                ->where('boardColumns.complete.data.0.items_count', 1)
                ->where('boardColumns.complete.data.0.completed_items_count', 1)
                ->where('boardColumns.complete.data.0.remaining_items_count', 0)
            );
    }

    public function test_complete_work_order_remains_terminal_when_item_composition_is_mismatched(): void
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

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $completePlanning->id,
            'planning_item_id' => $completePlanning->planning_item_id,
            'status' => 'complete',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $overduePlanning->id,
            'planning_item_id' => $overduePlanning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->actingAs($planner)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('boardColumns.complete.data.0.id', $workOrder->id)
                ->where('boardColumns.complete.data.0.items_count', 2)
                ->where('boardColumns.complete.data.0.completed_items_count', 1)
                ->where('boardColumns.complete.data.0.remaining_items_count', 1)
            );

        $this->artisan('work-orders:audit-statuses')
            ->expectsOutput('Mismatched work orders: 0')
            ->assertSuccessful();

        $this->artisan('work-orders:audit-statuses --fix')
            ->expectsOutput('Mismatched work orders: 0')
            ->assertSuccessful();

        $this->assertSame('complete', $workOrder->refresh()->status);
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

    private function workOrderForPlanning(UnitPlanning $planning): WorkOrder
    {
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $planning->unit_id,
            'site_id' => $planning->unit->site_id,
            'status' => 'open',
            'trigger_type' => 'normal',
        ]);

        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue',
        ]);

        return $workOrder;
    }
}
