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
 * maintenance:check-overdue menandai item terlambat setiap malam. Item yang
 * sudah punya mekanik penanggung jawab dan tanggal pengerjaan tidak boleh
 * kehilangan tahap kerjanya karena penandaan itu — kalau hilang, pekerjaannya
 * lenyap dari Tugas Saya mekanik tanpa ada yang memberi tahu siapa pun.
 */
class OverdueScheduleRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_item_marked_overdue_stays_visible_for_the_mechanic(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Ganti Oli', today()->subDays(3)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'in_progress');

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame('overdue', $item->refresh()->status);

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
        $this->assertContains('Terlambat 3 hari', $labels);
        $this->assertNotContains('Belum ada tindakan', $labels);
    }

    public function test_item_leaving_overdue_returns_to_in_progress_when_schedule_is_intact(): void
    {
        [$site, $unit, $mechanic, $planner] = $this->fleet();
        $planning = $this->planning($unit, 'Filter Solar', today()->addDays(10)->toDateString());
        [$workOrder, $item] = $this->scheduledItem($unit, $site, $mechanic, $planner, $planning, 'overdue');

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

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

    public function test_sweep_syncs_the_parent_work_order_status(): void
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
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'in_progress',
            'approved_at' => now(),
        ]);

        $this->artisan('maintenance:check-overdue')->assertSuccessful();

        $this->assertSame(
            'open',
            $workOrder->refresh()->status,
            'Status WO ikut dihitung ulang, tidak membeku di nilai lama.',
        );
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
            ->expectsOutput('Tidak ada item on_hold yang masih memegang mekanik dan jadwal.')
            ->assertSuccessful();
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
