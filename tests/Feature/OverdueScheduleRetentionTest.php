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
use Tests\TestCase;

/**
 * maintenance:check-overdue hanya menandai item on_hold. Item yang sudah
 * dikerjakan tetap in_progress meskipun melewati due.
 */
class OverdueScheduleRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_replace_stays_in_progress_after_due_and_shows_overdue_badges(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Ganti Oli', today()->subDays(3)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'in_progress');
        $item->update(['action' => 'replace']);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertNotNull($item->approved_at);
        $this->assertNull($item->completed_date);
        $this->assertSame('in_progress', $workOrder->refresh()->status);

        $tasks = collect($this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->inertiaProps('tasks'));

        $this->assertSame(
            [$item->id],
            $tasks->pluck('id')->all(),
            'Item yang terlambat tapi sudah dijadwalkan tetap menjadi tugas mekanik.',
        );

        $board = $this->boardSnapshot($planner);

        $this->assertSame(
            [$item->id],
            $board['in_progress']->pluck('id')->all(),
            'Item yang sudah dijadwalkan tetap di kolom Sedang Dikerjakan.',
        );
        $this->assertSame([], $board['open']->pluck('id')->all());

        $card = $board['in_progress']->firstWhere('id', $item->id);
        $labels = collect($card['badges'])->pluck('label')->all();

        $this->assertSame('in_progress', $card['phase']);
        $this->assertContains('Sedang dikerjakan', $labels);
        $this->assertContains('Overdue', $labels);
        $this->assertNotContains('Belum ada tindakan', $labels);

        $workListItem = collect($this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->inertiaProps('items'))->firstWhere('id', $item->id);

        $this->assertSame('in_progress', $workListItem['status']);
        $this->assertSame('Sedang dikerjakan', $workListItem['status_label']);
        $this->assertTrue($workListItem['is_overdue']);
    }

    public function test_item_leaving_overdue_returns_to_in_progress_when_schedule_is_intact(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Solar', today()->addDays(10)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'overdue');
        $item->update(['action' => 'replace']);

        $this->assertTrue($item->isScheduled());

        $this->artisan('maintenance:check-overdue --dry-run')
            ->expectsOutput('0 work order item overdue stale akan dikembalikan ke on_hold.')
            ->expectsOutput('1 work order item overdue stale akan dikembalikan ke in_progress.')
            ->assertSuccessful();

        $this->assertSame('overdue', $item->refresh()->status);

        $this->artisan('maintenance:check-overdue')
            ->expectsOutput('0 work order item overdue stale dikembalikan ke on_hold.')
            ->expectsOutput('1 work order item overdue stale dikembalikan ke in_progress.')
            ->assertSuccessful();

        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertSame($mechanic->id, $workOrder->refresh()->assigned_mechanic_id);
        $this->assertSame(today()->subDay()->toDateString(), $item->refresh()->scheduled_date?->toDateString());
    }

    public function test_item_leaving_overdue_falls_back_to_on_hold_without_a_schedule(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Brake Pad', today()->addDays(10)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('on_hold', $item->refresh()->status);
    }

    public function test_stale_approved_replace_without_a_schedule_returns_to_on_hold(): void
    {
        [$site, $unit, , $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Kabin', today()->addDays(10)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue',
            'action' => 'replace',
            'approved_by' => $planner->id,
            'approved_at' => now(),
        ]);

        $this->assertFalse($item->isScheduled());

        $this->artisan('maintenance:check-overdue --dry-run')
            ->expectsOutput('1 work order item overdue stale akan dikembalikan ke on_hold.')
            ->expectsOutput('0 work order item overdue stale akan dikembalikan ke in_progress.')
            ->assertSuccessful();

        $this->assertSame('overdue', $item->refresh()->status);

        $this->artisan('maintenance:check-overdue')
            ->expectsOutput('1 work order item overdue stale dikembalikan ke on_hold.')
            ->expectsOutput('0 work order item overdue stale dikembalikan ke in_progress.')
            ->assertSuccessful();

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertNull($item->completed_date);
    }

    public function test_on_hold_item_becomes_overdue_and_syncs_parent_work_order(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Oli Gardan', today()->subDays(2)->toDateString());
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'approved_by' => $planner->id,
            'approved_at' => now(),
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
            'approved_at' => now(),
        ]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('overdue', $item->refresh()->status);
        $this->assertSame(
            'open',
            $workOrder->refresh()->status,
            'Status WO ikut dihitung ulang, tidak membeku di nilai lama.',
        );
    }

    public function test_postponed_complete_and_blocked_items_are_not_marked_overdue(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $items = [];

        foreach (['postponed', 'complete', 'blocked'] as $status) {
            $planning = $this->planning($unit, 'Item '.$status, today()->subDays(2)->toDateString());
            $planning->update(['next_due_km' => $unit->current_odo]);
            [, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, $status);
            $items[] = $item;
        }

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        foreach ($items as $item) {
            $this->assertSame($item->getOriginal('status'), $item->refresh()->status);
        }
    }

    public function test_in_progress_badge_is_computed_from_current_due_date_and_km(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Udara', today()->addDays(10)->toDateString());
        $planning->update(['next_due_km' => null]);
        [, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'in_progress');

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $card = $this->boardSnapshot($planner)['in_progress']->firstWhere('id', $item->id);
        $this->assertNotContains('Overdue', collect($card['badges'])->pluck('label'));
        $workListItem = collect($this->actingAs($planner)->get(route('work-list.index'))->assertOk()->inertiaProps('items'))->firstWhere('id', $item->id);
        $this->assertFalse($workListItem['is_overdue']);

        $planning->update(['next_due_date' => null, 'next_due_km' => $unit->current_odo]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('in_progress', $item->refresh()->status);
        $card = $this->boardSnapshot($planner)['in_progress']->firstWhere('id', $item->id);
        $this->assertContains('Overdue', collect($card['badges'])->pluck('label'));
        $workListItem = collect($this->actingAs($planner)->get(route('work-list.index'))->assertOk()->inertiaProps('items'))->firstWhere('id', $item->id);
        $this->assertTrue($workListItem['is_overdue']);
    }

    public function test_missing_baseline_never_produces_an_overdue_badge(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Baseline kosong', today()->subDays(2)->toDateString());
        $planning->update(['last_done_km' => 0, 'next_due_km' => null]);
        [, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'in_progress');

        $this->assertTrue((new UnitPlanning(['last_done_km' => null]))->isBaselineMissing());

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $card = $this->boardSnapshot($planner)['in_progress']->firstWhere('id', $item->id);
        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertNotContains('Overdue', collect($card['badges'])->pluck('label'));
    }

    public function test_repair_command_restores_items_stranded_by_the_old_sweep(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Udara', today()->addDays(10)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'on_hold');

        $this->artisan('fleet:restore-scheduled-on-hold --dry-run')
            ->expectsOutput('1 item akan dikembalikan ke in_progress. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $this->assertSame('on_hold', $item->refresh()->status);

        $this->artisan('fleet:restore-scheduled-on-hold --execute')
            ->expectsOutput('1 item berhasil dikembalikan ke in_progress.')
            ->assertSuccessful();

        $this->assertSame('in_progress', $item->refresh()->status);

        $this->artisan('fleet:restore-scheduled-on-hold --execute')
            ->expectsOutput('Tidak ada item on_hold/overdue yang memenuhi kriteria pemulihan.')
            ->assertSuccessful();
    }

    public function test_repair_command_restores_scheduled_approved_replace_from_overdue_and_on_hold(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Solar', today()->subDays(3)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'overdue');
        $item->update(['action' => 'replace']);

        $onHoldPlanning = $this->planning($unit, 'Filter Oli', today()->subDays(3)->toDateString());
        [, $onHoldItem] = $this->scheduledItem($unit, $site, $mechanic, $planner, $onHoldPlanning, 'on_hold');
        $onHoldItem->update(['action' => 'replace']);

        $completedPlanning = $this->planning($unit, 'Brake Pad', today()->subDays(3)->toDateString());
        [, $completedItem] = $this->scheduledItem($unit, $site, $mechanic, $planner, $completedPlanning, 'overdue');
        $completedItem->update(['action' => 'replace', 'completed_date' => today()->toDateString()]);

        $otherActionPlanning = $this->planning($unit, 'Oli Gardan', today()->subDays(3)->toDateString());
        [, $otherActionItem] = $this->scheduledItem($unit, $site, $mechanic, $planner, $otherActionPlanning, 'overdue');
        $otherActionItem->update(['action' => 'postpone']);

        $this->artisan('fleet:restore-scheduled-on-hold --dry-run')
            ->expectsOutput('2 item akan dikembalikan ke in_progress. Tidak ada data yang diubah.')
            ->assertSuccessful();

        $this->assertSame('overdue', $item->refresh()->status);
        $this->assertSame('on_hold', $onHoldItem->refresh()->status);

        $this->artisan('fleet:restore-scheduled-on-hold --execute')
            ->expectsOutput('2 item berhasil dikembalikan ke in_progress.')
            ->assertSuccessful();

        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertSame('in_progress', $onHoldItem->refresh()->status);
        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('overdue', $completedItem->refresh()->status);
        $this->assertSame('overdue', $otherActionItem->refresh()->status);
    }

    public function test_repair_command_excludes_approved_replace_waiting_for_assignment(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $pendingPlanning = $this->planning($unit, 'Filter Kabin', today()->addDays(10)->toDateString());
        [$pendingWorkOrder, $pendingItem] = $this->scheduledItem($unit, $site, $mechanic, $planner, $pendingPlanning, 'on_hold');
        $pendingItem->update(['action' => 'replace', 'scheduled_date' => null]);
        $pendingWorkOrder->update(['assigned_mechanic_id' => null]);

        $overduePlanning = $this->planning($unit, 'Filter Udara', today()->subDays(3)->toDateString());
        [$overdueWorkOrder, $overdueItem] = $this->scheduledItem($unit, $site, $mechanic, $planner, $overduePlanning, 'on_hold');
        $overdueItem->update(['action' => 'replace', 'scheduled_date' => null]);
        $overdueWorkOrder->update(['assigned_mechanic_id' => null]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();
        $this->assertSame('overdue', $overdueItem->refresh()->status);

        $this->artisan('fleet:restore-scheduled-on-hold --execute')
            ->expectsOutput('Tidak ada item on_hold/overdue yang memenuhi kriteria pemulihan.')
            ->assertSuccessful();

        $this->assertSame('on_hold', $pendingItem->refresh()->status);
        $this->assertSame('overdue', $overdueItem->refresh()->status);
    }

    public function test_repair_command_does_not_undo_site_relocation(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Transmisi', today()->addDays(10)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'in_progress');
        $item->update(['action' => 'replace']);

        $newSite = Site::query()->create(['name' => 'Site Tujuan', 'region' => 'Kalimantan']);
        $unit->update(['site_id' => $newSite->id]);

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertNull($item->scheduled_date);
        $this->assertNull($workOrder->refresh()->assigned_mechanic_id);

        $this->artisan('fleet:restore-scheduled-on-hold --execute')
            ->expectsOutput('Tidak ada item on_hold/overdue yang memenuhi kriteria pemulihan.')
            ->assertSuccessful();

        $this->assertSame('on_hold', $item->refresh()->status);
    }

    /**
     * @return array{0: Site, 1: Unit, 2: User, 3: User}
     */
    private function fleet(): array
    {
        SystemThreshold::query()->create(['key' => 'warning_km', 'value' => '500']);
        SystemThreshold::query()->create(['key' => 'warning_days', 'value' => '7']);
        SystemThreshold::query()->create(['key' => 'ancang_ancang_km', 'value' => '1000']);
        SystemThreshold::query()->create(['key' => 'ancang_ancang_days', 'value' => '14']);
        SystemThreshold::query()->create(['key' => 'upcoming_km', 'value' => '2000']);
        SystemThreshold::query()->create(['key' => 'upcoming_days', 'value' => '28']);

        $site = Site::query()->create(['name' => 'Site Overdue', 'region' => 'Kalimantan']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer A',
            'current_plate' => 'KT 7788 AA',
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => 10000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $planner = User::factory()->create(['role' => UserRole::Superadmin, 'site_id' => $site->id]);

        return [$site, $unit, $mechanic, $planner];
    }

    private function planning(Unit $unit, string $name, string $nextDueDate): UnitPlanning
    {
        $planningItem = PlanningItem::query()->create(['name' => $name, 'interval_km' => 5000, 'interval_days' => 90]);

        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 5000,
            'last_done_date' => today()->subDays(80)->toDateString(),
            'next_due_km' => 50000,
            'next_due_date' => $nextDueDate,
        ]);
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderItem}
     */
    private function scheduledItem(
        Unit $unit,
        Site $site,
        User $mechanic,
        User $planner,
        UnitPlanning $planning,
        string $status,
    ): array {
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
            'approved_by' => $planner->id,
            'approved_at' => now(),
        ]);

        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => $status,
            'scheduled_date' => today()->subDay()->toDateString(),
            'approved_by' => $planner->id,
            'approved_at' => now(),
        ]);

        return [$workOrder, $item];
    }

    /**
     * @return array<string, Collection<int, mixed>>
     */
    private function boardSnapshot(User $user): array
    {
        $response = $this->actingAs($user)
            ->get(route('work-orders.index'))
            ->assertOk();

        return [
            'open' => collect($response->inertiaProps('boardColumns.open.data')),
            'in_progress' => collect($response->inertiaProps('boardColumns.in_progress.data')),
        ];
    }
}
