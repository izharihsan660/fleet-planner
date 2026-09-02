<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Database\Seeders\PlanningItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderActionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_area_submits_replace_spv_approves_and_spv_ho_is_notified(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $spv_ho = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);

        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $this->actingAs($admin)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), [
                'reason' => 'Ganti part sesuai jadwal',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('replace', $item->refresh()->status);
        $this->assertSame('replace', $item->action);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertDatabaseHas(Notification::class, [
            'user_id' => $spv_ho->id,
            'type' => 'work_order_approved',
        ]);
    }

    public function test_warranty_replace_approval_does_not_notify_spv_ho(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(40000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $spv_ho = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);

        $this->actingAs($admin)->post(route('work-orders.items.replace', [$workOrder, $item]));
        $this->actingAs($spv)->post(route('work-orders.approve', $workOrder));

        $this->assertDatabaseMissing(Notification::class, [
            'user_id' => $spv_ho->id,
            'type' => 'work_order_approved',
        ]);
    }

    public function test_replace_submission_with_mechanic_assignment_is_ready_after_spv_approval(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);
        $scheduledDate = today()->addDay()->toDateString();

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), [
                'reason' => 'Ganti part sesuai jadwal',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => $scheduledDate,
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame($mechanic->id, $workOrder->refresh()->assigned_mechanic_id);
        $this->assertSame($scheduledDate, $item->refresh()->scheduled_date->toDateString());

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame($mechanic->id, $workOrder->assigned_mechanic_id);
        $this->assertSame('in_progress', $item->refresh()->status);
    }

    public function test_missing_baseline_replace_runs_through_approval_and_initializes_cycle_on_mechanic_completion(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planning->update([
            'last_done_km' => 0,
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => null,
            'next_due_date' => null,
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);
        $plannedDate = today()->addDays(3)->toDateString();
        $scheduledDate = today()->addDay()->toDateString();

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), [
                'reason' => 'Inisialisasi cycle lewat penggantian aktual.',
                'planned_date' => $plannedDate,
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => $scheduledDate,
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertDatabaseHas('work_order_items', [
            'id' => $item->id,
            'status' => 'replace',
            'action' => 'replace',
            'submitted_by' => $planner->id,
        ]);
        $this->assertSame($plannedDate, $item->refresh()->planned_date?->toDateString());
        $this->assertTrue($planning->refresh()->isBaselineMissing());
        $this->assertNull($planning->next_due_km);
        $this->assertNull($planning->next_due_date);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertNotNull($item->approved_at);
        $this->assertTrue($planning->refresh()->isBaselineMissing());

        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.id', $item->id)
                ->where('tasks.0.planned_date', $plannedDate)
            );

        $completedOdo = 75850;
        $completedDate = today()->toDateString();

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 0,
                'completed_date' => $completedDate,
            ])
            ->assertSessionHasErrors('completed_odo');

        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertTrue($planning->refresh()->isBaselineMissing());

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => $completedOdo,
                'completed_date' => $completedDate,
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $planningItem = $planning->planningItem()->firstOrFail();

        $this->assertDatabaseHas('work_order_items', [
            'id' => $item->id,
            'status' => 'complete',
            'completed_odo' => $completedOdo,
        ]);
        $this->assertSame($plannedDate, $item->refresh()->planned_date?->toDateString());
        $this->assertSame($completedDate, $item->completed_date?->toDateString());
        $this->assertDatabaseHas('unit_plannings', [
            'id' => $planning->id,
            'last_done_km' => $completedOdo,
            'next_due_km' => $completedOdo + $planningItem->interval_km,
        ]);
        $this->assertSame($completedDate, $planning->refresh()->last_done_date?->toDateString());
        $this->assertSame(today()->addDays($planningItem->interval_days)->toDateString(), $planning->next_due_date?->toDateString());
        $this->assertFalse($planning->isBaselineMissing());
        $this->assertSame('complete', $workOrder->refresh()->status);
    }

    /**
     * Tidak ada pengajuan tanpa rencana jadwal — mekanik penanggung jawab dan
     * tanggal sama-sama wajib saat Ajukan Penggantian.
     */
    public function test_replace_submission_requires_mechanic_and_schedule(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);

        $this->actingAs($planner)
            ->from(route('work-orders.show', $workOrder))
            ->post(route('work-orders.items.replace', [$workOrder, $item]), ['reason' => 'Belum tahu mekanik'])
            ->assertSessionHasErrors(['assigned_mechanic_id', 'scheduled_date']);

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertNull($item->scheduled_date);
    }

    /**
     * Penjaga terakhir: assigned_mechanic_id memakai nullOnDelete, jadi
     * penanggung jawab bisa hilang bila akun mekaniknya dihapus setelah
     * pengajuan. Item seperti itu tidak boleh diam-diam jadi tugas mekanik.
     */
    public function test_approval_falls_back_to_on_hold_when_assigned_mechanic_is_deleted(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), [
                'reason' => 'Perlu diganti.',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $mechanic->delete();

        $this->assertNull($workOrder->refresh()->assigned_mechanic_id);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $item->refresh();

        $this->assertSame('on_hold', $item->status);
        $this->assertNotNull($item->approved_at);
        $this->assertNotNull($item->scheduled_date);
    }

    /**
     * WO hasil trigger otomatis tidak bisa di-approve borongan selagi itemnya
     * masih on_hold — item itu belum punya penanggung jawab maupun jadwal.
     * Planner harus mengajukannya lebih dulu.
     */
    public function test_spv_cannot_approve_untriaged_auto_generated_work_order(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);

        // submitted_by null menandai WO terbit otomatis dari trigger maintenance.
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
        ]);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertStatus(422);

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertNull($item->approved_at);
        $this->assertSame('open', $workOrder->refresh()->status);

        // Setelah planner mengajukan lengkap dengan jadwal, approve berjalan.
        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), [
                'reason' => 'Perlu diganti.',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $item->refresh()->status);
        $this->assertSame($mechanic->id, $workOrder->refresh()->assigned_mechanic_id);
    }

    public function test_planner_area_submits_postpone_and_spv_approval_moves_due_schedule(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);
        // Relatif ke hari ini: new_due_date divalidasi after_or_equal:today.
        $requestedDueDate = today()->addDays(4)->toDateString();

        $this->actingAs($admin)
            ->post(route('work-orders.items.postpone', [$workOrder, $item]), [
                'reason' => 'Unit belum bisa masuk workshop',
                'new_due_km' => 88000,
                'new_due_date' => $requestedDueDate,
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('postpone', $item->refresh()->status);
        $this->assertSame(88000, $item->new_due_km);
        $this->assertSame($requestedDueDate, $item->new_due_date->toDateString());

        $this->actingAs($admin)
            ->get(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrder.data.items.0.new_due_date', $requestedDueDate)
                ->where('workOrder.data.items.0.effective_due_date', $requestedDueDate)
            );

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('complete', $workOrder->refresh()->status);
        $this->assertSame('postponed', $item->refresh()->status);
        $this->assertSame(88000, $planning->refresh()->next_due_km);
        $this->assertSame($requestedDueDate, $planning->next_due_date->toDateString());

        $this->actingAs($admin)
            ->get(route('units.history', $unit))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('history.postpones.data.0.new_due_date', $requestedDueDate)
            );
    }

    public function test_planner_area_can_resubmit_rejected_replace_with_previous_context_visible(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);

        $item->update([
            'status' => 'rejected',
            'action' => 'replace',
            'reason' => 'Ganti ban depan.',
            'notes' => 'Foto kondisi ban belum dilampirkan.',
        ]);
        $workOrder->update(['status' => 'cancelled']);

        $this->actingAs($planner)
            ->get(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workOrder.data.items.0.status', 'rejected')
                ->where('workOrder.data.items.0.action', 'replace')
                ->where('workOrder.data.items.0.reason', 'Ganti ban depan.')
                ->where('workOrder.data.items.0.notes', 'Foto kondisi ban belum dilampirkan.')
            );

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $item]), [
                'reason' => 'Ganti ban depan dengan foto pendukung lengkap.',
                'assigned_mechanic_id' => User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id])->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('replace', $item->refresh()->status);
        $this->assertSame('Ganti ban depan dengan foto pendukung lengkap.', $item->reason);
        $this->assertSame('Foto kondisi ban belum dilampirkan.', $item->notes);
        $this->assertSame('open', $workOrder->refresh()->status);

        $pageSource = file_get_contents(resource_path('js/Pages/WorkOrders/Show.tsx'));

        $this->assertStringContainsString('Alasan penolakan:', $pageSource);
        $this->assertStringContainsString('Submit Ulang Replace', $pageSource);
        $this->assertStringContainsString('Submit Ulang Postpone', $pageSource);
    }

    public function test_planner_area_can_resubmit_rejected_postpone_with_revised_schedule(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);

        $item->update([
            'status' => 'rejected',
            'action' => 'postpone',
            'reason' => 'Unit masih beroperasi.',
            'notes' => 'Tanggal pengganti terlalu jauh.',
            'new_due_km' => 80000,
            'new_due_date' => today()->addDays(30)->toDateString(),
        ]);
        $workOrder->update(['status' => 'cancelled']);
        $revisedDueDate = today()->addDays(14)->toDateString();

        $this->actingAs($planner)
            ->post(route('work-orders.items.postpone', [$workOrder, $item]), [
                'reason' => 'Unit masuk workshop dua minggu lagi.',
                'new_due_km' => 78000,
                'new_due_date' => $revisedDueDate,
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('postpone', $item->refresh()->status);
        $this->assertSame('Unit masuk workshop dua minggu lagi.', $item->reason);
        $this->assertSame(78000, $item->new_due_km);
        $this->assertSame($revisedDueDate, $item->new_due_date->toDateString());
        $this->assertSame('open', $workOrder->refresh()->status);
    }

    public function test_reject_recalculates_work_order_status_instead_of_forcing_in_progress(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);

        $item->update([
            'status' => 'replace',
            'action' => 'replace',
            'reason' => 'Ganti ban depan.',
        ]);
        $workOrder->update([
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($spv)
            ->post(route('work-orders.reject', $workOrder))
            ->assertRedirect(route('work-orders.index'));

        $this->assertSame('rejected', $item->refresh()->status);
        $this->assertSame('open', $workOrder->refresh()->status);
    }

    public function test_work_order_returns_to_on_hold_when_no_item_remains_in_progress(): void
    {
        [$site, $unit, $firstPlanning] = $this->makePlanningContext(75000);
        $secondPlanning = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => PlanningItem::query()->create([
                'name' => 'Air Filter',
                'interval_km' => 10000,
                'interval_days' => 30,
            ])->id,
            'last_done_km' => 65000,
            'last_done_date' => now()->subMonths(2)->toDateString(),
            'next_due_km' => 75000,
            'next_due_date' => now()->subDay()->toDateString(),
        ]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
        ]);
        $firstItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $firstPlanning->id,
            'planning_item_id' => $firstPlanning->planning_item_id,
            'status' => 'in_progress',
            'action' => 'replace',
            'scheduled_date' => today()->toDateString(),
        ]);
        $secondItem = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $secondPlanning->id,
            'planning_item_id' => $secondPlanning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $firstItem]), [
                'completed_odo' => 76000,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('open', $workOrder->refresh()->status);
        $this->assertSame('complete', $firstItem->refresh()->status);
        $this->assertSame('overdue', $secondItem->refresh()->status);

        // WO turun ke 'open', tapi item overdue-nya masih tugas mekanik yang sama.
        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.id', $secondItem->id)
            );

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $secondItem]), [
                'reason' => 'Overdue perlu ditinjau dan diajukan ulang.',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('open', $workOrder->refresh()->status);
        $this->assertSame('replace', $secondItem->refresh()->status);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $secondItem->refresh()->status);

        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.0.id', $secondItem->id));

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $secondItem]), [
                'completed_odo' => 77000,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $workOrder->refresh()->status);
    }

    /**
     * Regresi pola "visibility ikut status container": Tugas Saya dulu memfilter
     * work_orders.status = 'in_progress', jadi begitu item in_progress terakhir
     * selesai WO turun ke 'open' dan seluruh sisa tugas mekanik ikut hilang.
     */
    public function test_completing_one_task_does_not_hide_the_mechanic_remaining_tasks(): void
    {
        [$site, $unit, $planningA] = $this->makePlanningContext(80000);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $otherMechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);

        $planningB = UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => PlanningItem::query()->create([
                'name' => 'Brake Pad Regresi',
                'interval_km' => 10000,
                'interval_days' => 180,
            ])->id,
            'last_done_km' => 69000,
            'last_done_date' => today()->subDays(180)->toDateString(),
            'next_due_km' => 79000,
            'next_due_date' => today()->subDays(4)->toDateString(),
        ]);

        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
            'approved_at' => now(),
        ]);

        $itemA = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planningA->id,
            'planning_item_id' => $planningA->planning_item_id,
            'status' => 'in_progress',
            'scheduled_date' => today()->toDateString(),
        ]);
        $itemB = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planningB->id,
            'planning_item_id' => $planningB->planning_item_id,
            'status' => 'overdue',
        ]);

        // Item A dan B sama-sama tampil sebelum ada yang diselesaikan.
        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mechanic/Tasks')
                ->has('tasks', 2)
            );

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $itemA]), [
                'completed_odo' => 80500,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $itemA->refresh()->status);
        $this->assertSame('overdue', $itemB->refresh()->status);

        // Item in_progress terakhir sudah selesai, jadi WO induk turun ke 'open'.
        $this->assertSame('open', $workOrder->refresh()->status);

        $tasks = collect($this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->inertiaProps('tasks'));

        $this->assertSame(
            [$itemB->id],
            $tasks->pluck('id')->all(),
            'Item overdue yang belum selesai tetap jadi tugas mekanik walau status WO sudah turun.'
        );
        $this->assertSame($unit->current_plate, $tasks->first()['unit_name']);
        $this->assertSame('Brake Pad Regresi', $tasks->first()['item_name']);

        // Item yang sudah selesai tidak ikut tertinggal di daftar tugas.
        $this->assertFalse($tasks->pluck('id')->contains($itemA->id));

        // Item B masih bisa diselesaikan dari daftar tugas, tanpa perlu WO di-approve ulang.
        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $itemB]), [
                'completed_odo' => 80600,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $itemB->refresh()->status);
        $this->assertSame('complete', $workOrder->refresh()->status);

        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks', 0));

        // Tugas tetap milik mekanik yang di-assign, bukan bocor ke mekanik lain.
        $this->actingAs($otherMechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks', 0));
    }

    public function test_spv_approve_work_order_approves_mixed_pending_actions_together(): void
    {
        [$site, $unit, $plannings] = $this->makeMultiplePlanningContext(75000, 3);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
            'submitted_by' => $planner->id,
        ]);

        $items = collect($plannings)->map(fn (UnitPlanning $planning): WorkOrderItem => WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
            'submitted_by' => $planner->id,
        ]))->values();

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $items[0]]), [
                'reason' => 'Replace ban kiri',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $items[1]]), [
                'reason' => 'Replace ban kanan',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDays(2)->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->actingAs($planner)
            ->post(route('work-orders.items.postpone', [$workOrder, $items[2]]), [
                'reason' => 'Unit belum tersedia',
                'new_due_km' => 90000,
                'new_due_date' => today()->addDays(10)->toDateString(),
            ])->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('replace', $items[0]->refresh()->status);
        $this->assertSame('replace', $items[1]->refresh()->status);
        $this->assertSame('postpone', $items[2]->refresh()->status);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $workOrder->refresh()->status);
        $this->assertSame('in_progress', $items[0]->refresh()->status);
        $this->assertSame('in_progress', $items[1]->refresh()->status);
        $this->assertSame('postponed', $items[2]->refresh()->status);
        $this->assertSame(today()->addDay()->toDateString(), $items[0]->scheduled_date->toDateString());
        $this->assertSame(today()->addDays(2)->toDateString(), $items[1]->scheduled_date->toDateString());
        $this->assertSame(90000, $plannings[2]->refresh()->next_due_km);
        $this->assertSame(today()->addDays(10)->toDateString(), $plannings[2]->next_due_date->toDateString());
    }

    public function test_spv_approve_auto_work_order_only_approves_submitted_items_not_untriaged_on_hold(): void
    {
        [$site, $unit, $plannings] = $this->makeMultiplePlanningContext(75000, 3);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);

        // Auto-generated work order: no submitted_by, all items start on_hold.
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
        ]);

        $items = collect($plannings)->map(fn (UnitPlanning $planning): WorkOrderItem => WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'on_hold',
        ]))->values();

        // Planner triages only the first item.
        $this->actingAs($planner)
            ->post(route('work-orders.items.replace', [$workOrder, $items[0]]), [
                'reason' => 'Ganti part item pertama',
                'assigned_mechanic_id' => $mechanic->id,
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertRedirect(route('work-orders.show', $workOrder));

        $this->assertSame('in_progress', $items[0]->refresh()->status);
        $this->assertSame('on_hold', $items[1]->refresh()->status);
        $this->assertSame('on_hold', $items[2]->refresh()->status);
    }

    public function test_spv_cannot_approve_work_order_without_submitted_action(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin);

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertStatus(422);

        $this->assertSame('open', $workOrder->refresh()->status);
        $this->assertSame('on_hold', $item->refresh()->status);
    }

    public function test_blocked_item_can_be_resolved_to_on_hold(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $admin, 'blocked');

        $this->actingAs($admin)
            ->post(route('work-order-items.resolve-blocked', $item))
            ->assertRedirect();

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertSame('open', $workOrder->refresh()->status);
    }

    public function test_mechanic_my_tasks_lists_assigned_items_and_complete_removes_card(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
        ]);
        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'in_progress',
            'action' => 'replace',
            'scheduled_date' => today()->toDateString(),
        ]);

        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mechanic/Tasks')
                ->has('tasks', 1)
                ->where('tasks.0.id', $item->id)
                ->where('tasks.0.unit_name', $unit->current_plate)
            );

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete', [$workOrder, $item]), [
                'completed_odo' => 76000,
                'completed_date' => today()->toDateString(),
            ])
            ->assertRedirect(route('mechanic.tasks'));

        $this->assertSame('complete', $item->refresh()->status);

        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mechanic/Tasks')
                ->has('tasks', 0)
            );
    }

    public function test_mechanic_task_card_only_displays_planned_date_when_available(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/Mechanic/Tasks.tsx'));

        $this->assertStringContainsString('{task.planned_date && (', $pageSource);
        $this->assertStringContainsString('Rencana: {task.planned_date}', $pageSource);
        $this->assertStringNotContainsString("task.planned_date ?? '-'", $pageSource);
    }

    public function test_mechanic_my_tasks_excludes_items_with_missing_baseline(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planning->update(['last_done_km' => 0, 'last_done_date' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'in_progress',
            'assigned_mechanic_id' => $mechanic->id,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => 'overdue',
        ]);

        $this->actingAs($mechanic)
            ->get(route('mechanic.tasks'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mechanic/Tasks')
                ->has('tasks', 0)
            );
    }

    public function test_work_order_approval_rejects_item_with_missing_baseline(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planning->update(['last_done_km' => 0, 'last_done_date' => null]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner, 'pending_create');

        $this->actingAs($spv)
            ->post(route('work-orders.approve', $workOrder))
            ->assertStatus(422);

        $this->assertSame('pending_create', $item->refresh()->status);
        $this->assertNull($workOrder->refresh()->approved_at);
    }

    public function test_authorized_roles_can_set_historical_baseline_and_complete_missing_baseline_item(): void
    {
        foreach ([UserRole::PlannerArea, UserRole::SpvHo, UserRole::Superadmin] as $role) {
            [$site, $unit, $planning] = $this->makePlanningContext(75000);
            $planning->update(['last_done_km' => 0, 'last_done_date' => null, 'next_due_km' => null, 'next_due_date' => null]);
            $user = User::factory()->create([
                'role' => $role,
                'site_id' => $role === UserRole::PlannerArea ? $site->id : null,
            ]);
            [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $user);
            $item->update(['reason' => 'Sudah lewat, segera dilakukan penggantian.']);

            $this->actingAs($user)
                ->get(route('work-orders.show', $workOrder))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('canManageBaselineItems', true)
                    ->where('workOrder.data.items.0.reason', null)
                    ->where('workOrder.data.items.0.historical_reason', 'Sudah lewat, segera dilakukan penggantian.')
                );

            $baselineDate = today()->subDays(60)->toDateString();
            $completedDate = today()->toDateString();
            $completedOdo = 76000;

            $this->actingAs($user)
                ->post(route('work-orders.items.complete-with-baseline', [$workOrder, $item]), [
                    'last_done_km' => 65000,
                    'last_done_date' => $baselineDate,
                    'completed_odo' => $completedOdo,
                    'completed_date' => $completedDate,
                    'notes' => 'Penggantian selesai hari ini.',
                ])
                ->assertRedirect(route('work-orders.show', $workOrder));

            $planningItem = $planning->planningItem()->firstOrFail();

            $this->assertDatabaseHas('work_order_items', [
                'id' => $item->id,
                'status' => 'complete',
                'reason' => 'Sudah lewat, segera dilakukan penggantian.',
                'baseline_last_done_km' => 65000,
                'previous_due_km' => 65000 + $planningItem->interval_km,
                'completed_odo' => $completedOdo,
                'notes' => 'Penggantian selesai hari ini.',
                'submitted_by' => $user->id,
            ]);
            $this->assertDatabaseHas('unit_plannings', [
                'id' => $planning->id,
                'last_done_km' => $completedOdo,
                'next_due_km' => $completedOdo + $planningItem->interval_km,
                'is_estimated' => false,
            ]);
            $this->assertSame($baselineDate, $item->refresh()->baseline_last_done_date?->toDateString());
            $this->assertSame($completedDate, $item->completed_date?->toDateString());
            $this->assertSame($completedDate, $planning->refresh()->last_done_date?->toDateString());
            $this->assertSame('complete', $workOrder->refresh()->status);
        }
    }

    public function test_set_baseline_and_complete_validates_history_before_current_completion(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planning->update(['last_done_km' => 0, 'last_done_date' => null]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $planner);

        $this->actingAs($planner)
            ->post(route('work-orders.items.complete-with-baseline', [$workOrder, $item]), [
                'last_done_km' => 76000,
                'last_done_date' => today()->toDateString(),
                'completed_odo' => 75000,
                'completed_date' => today()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors(['completed_odo', 'completed_date']);

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertTrue($planning->refresh()->isBaselineMissing());
    }

    public function test_mechanic_cannot_set_baseline_and_complete_from_work_order(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planning->update(['last_done_km' => 0, 'last_done_date' => null]);
        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $mechanic);

        $this->actingAs($mechanic)
            ->post(route('work-orders.items.complete-with-baseline', [$workOrder, $item]), [
                'last_done_km' => 65000,
                'last_done_date' => today()->subDays(60)->toDateString(),
                'completed_odo' => 75000,
                'completed_date' => today()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertTrue($planning->refresh()->isBaselineMissing());
    }

    public function test_planner_area_cannot_set_baseline_and_complete_outside_access_scope(): void
    {
        [$site, $unit, $planning] = $this->makePlanningContext(75000);
        $planning->update(['last_done_km' => 0, 'last_done_date' => null]);
        $owner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);
        [$workOrder, $item] = $this->makeWorkOrder($unit, $planning, $owner);
        $otherSite = Site::query()->create(['name' => 'Site Tanpa Akses', 'region' => 'Wilayah Lain']);
        $otherPlanner = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $otherSite->id]);

        $this->actingAs($otherPlanner)
            ->post(route('work-orders.items.complete-with-baseline', [$workOrder, $item]), [
                'last_done_km' => 65000,
                'last_done_date' => today()->subDays(60)->toDateString(),
                'completed_odo' => 75000,
                'completed_date' => today()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame('on_hold', $item->refresh()->status);
        $this->assertTrue($planning->refresh()->isBaselineMissing());
    }

    public function test_planner_area_cannot_open_or_store_daily_km_input(): void
    {
        [$site, $unit] = $this->makePlanningContext(75000);
        $admin = User::factory()->create(['role' => UserRole::PlannerArea, 'site_id' => $site->id]);

        $this->actingAs($admin)->get(route('inspections.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('inspections.store'), [
            'unit_id' => $unit->id,
            'inspection_date' => today()->toDateString(),
            'odometer' => 76000,
        ])->assertForbidden();
    }

    /**
     * @return array{0: Site, 1: Unit, 2: UnitPlanning}
     */
    private function makePlanningContext(int $currentOdo): array
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => fake()->unique()->numerify('DD #### QA'),
            'type' => 'Operasional',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ]);

        $planningItem = PlanningItem::query()->where('name', 'PM Check / Reguler Services')->firstOrFail();
        $planning = UnitPlanning::query()->updateOrCreate(
            ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
            [
                'last_done_km' => $currentOdo - $planningItem->interval_km,
                'last_done_date' => today()->subDays($planningItem->interval_days)->toDateString(),
                'next_due_km' => $currentOdo,
                'next_due_date' => today()->toDateString(),
            ],
        );

        return [$site, $unit->refresh(), $planning->refresh()];
    }

    /**
     * @return array{0: Site, 1: Unit, 2: array<int, UnitPlanning>}
     */
    private function makeMultiplePlanningContext(int $currentOdo, int $count): array
    {
        $this->seed(PlanningItemSeeder::class);

        $site = Site::query()->create(['name' => 'Site Test', 'region' => 'Test']);
        $unit = Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'Customer Test',
            'current_plate' => 'DD 1234 QB',
            'type' => 'Operasional',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdo,
            'status' => 'active',
        ]);

        $plannings = PlanningItem::query()
            ->orderBy('id')
            ->take($count)
            ->get()
            ->map(fn (PlanningItem $planningItem): UnitPlanning => UnitPlanning::query()->updateOrCreate(
                ['unit_id' => $unit->id, 'planning_item_id' => $planningItem->id],
                [
                    'last_done_km' => $currentOdo - $planningItem->interval_km,
                    'last_done_date' => today()->subDays($planningItem->interval_days)->toDateString(),
                    'next_due_km' => $currentOdo,
                    'next_due_date' => today()->toDateString(),
                ],
            )->refresh())
            ->all();

        return [$site, $unit->refresh(), $plannings];
    }

    /**
     * @return array{0: WorkOrder, 1: WorkOrderItem}
     */
    private function makeWorkOrder(Unit $unit, UnitPlanning $planning, User $actor, string $status = 'on_hold'): array
    {
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $unit->site_id,
            'trigger_type' => 'normal',
            'status' => 'open',
            'submitted_by' => $actor->id,
        ]);

        $item = WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id,
            'status' => $status,
            'action' => $status === 'blocked' ? 'blocked' : null,
            'submitted_by' => $actor->id,
        ]);

        return [$workOrder, $item];
    }
}
