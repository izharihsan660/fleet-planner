<?php

namespace Tests\Feature;

use App\Enums\UserRole;
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

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_queue_shows_pending_items_across_regions_ordered_by_oldest_waiting(): void
    {
        [$spv, $oldItem, $newerItem] = $this->createApprovalScenario();
        $mechanic = User::factory()->create([
            'name' => 'Mekanik BPN',
            'role' => UserRole::Mekanik,
            'site_id' => $oldItem->workOrder->site_id,
        ]);
        $scheduledDate = today()->addDays(2)->toDateString();
        $oldSubmittedDate = now()->subDays(10)->startOfDay();
        $oldItem->forceFill([
            'scheduled_date' => $scheduledDate,
            'created_at' => $oldSubmittedDate,
            'updated_at' => now()->subDays(5),
        ])->save();
        $oldItem->workOrder->update(['assigned_mechanic_id' => $mechanic->id]);
        $newerItem->forceFill(['updated_at' => now()->subHours(6)])->save();

        $this->actingAs($spv)
            ->get(route('approval-queue.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApprovalQueue/Index')
                ->has('items', 2)
                ->where('items.0.id', $oldItem->id)
                ->where('items.0.waiting_label', '5 hari')
                ->where('items.0.is_warning', true)
                ->where('items.0.current_odo', 1000)
                ->where('items.0.baseline_incomplete', false)
                ->where('items.0.submitted_date', $oldSubmittedDate->toDateString())
                ->where('items.0.due_date', $oldItem->unitPlanning->next_due_date->toDateString())
                ->where('items.0.assigned_mechanic_name', 'Mekanik BPN')
                ->where('items.0.scheduled_date', $scheduledDate)
                ->where('items.0.action', 'replace')
                ->where('items.1.id', $newerItem->id)
                ->where('items.1.due_date', $newerItem->unitPlanning->next_due_date->toDateString())
                ->where('items.1.new_due_date', $newerItem->new_due_date->toDateString())
                ->where('items.1.assigned_mechanic_name', null)
                ->where('items.1.scheduled_date', null)
                ->where('items.1.action', 'postpone')
            );
    }

    public function test_approval_queue_exposes_submission_and_due_dates_for_blocked_action_items(): void
    {
        [$spv, $replaceItem, $postponeItem] = $this->createApprovalScenario();
        $planner = $replaceItem->submittedBy()->firstOrFail();
        $blockedDueDate = today()->addDays(45)->toDateString();
        $blockedSubmittedDate = now()->subDays(4)->startOfDay();
        $blockedItem = $this->createPendingItem($replaceItem->workOrder->site, $planner, 'KT 3003 CC', 'Air Dryer', 'pending_create');
        $blockedMechanic = User::factory()->create([
            'name' => 'Mekanik Blocked',
            'role' => UserRole::Mekanik,
            'site_id' => $blockedItem->workOrder->site_id,
        ]);
        $blockedScheduledDate = today()->addDays(3)->toDateString();

        $blockedItem->unitPlanning()->update(['next_due_date' => $blockedDueDate]);
        $blockedItem->workOrder->update(['assigned_mechanic_id' => $blockedMechanic->id]);
        $blockedItem->update(['scheduled_date' => $blockedScheduledDate]);
        $blockedItem->forceFill([
            'action' => 'blocked',
            'created_at' => $blockedSubmittedDate,
            'updated_at' => now()->subHour(),
        ])->save();

        $this->actingAs($spv)
            ->get(route('approval-queue.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApprovalQueue/Index')
                ->has('items', 3)
                ->where('items', function (mixed $items) use ($blockedDueDate, $blockedItem, $blockedScheduledDate, $blockedSubmittedDate, $postponeItem, $replaceItem): bool {
                    $itemsByAction = collect($items)->keyBy('action');

                    return data_get($itemsByAction, 'replace.id') === $replaceItem->id
                        && data_get($itemsByAction, 'postpone.id') === $postponeItem->id
                        && data_get($itemsByAction, 'blocked.id') === $blockedItem->id
                        && data_get($itemsByAction, 'blocked.submitted_date') === $blockedSubmittedDate->toDateString()
                        && data_get($itemsByAction, 'blocked.due_date') === $blockedDueDate
                        && data_get($itemsByAction, 'blocked.assigned_mechanic_name') === 'Mekanik Blocked'
                        && data_get($itemsByAction, 'blocked.scheduled_date') === $blockedScheduledDate;
                })
            );
    }

    public function test_approval_queue_shows_but_rejects_items_with_missing_baseline(): void
    {
        [$spv, $zeroBaselineItem, $postponeBaselineItem] = $this->createApprovalScenario();
        $staleDueDate = '2024-12-31';
        $zeroBaselineItem->unitPlanning()->update([
            'last_done_km' => 0,
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_date' => $staleDueDate,
        ]);
        $postponeBaselineItem->unitPlanning()->update([
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_date' => $staleDueDate,
        ]);
        $postponeBaselineItem->update(['previous_due_date' => $staleDueDate]);

        $nullBaseline = new UnitPlanning;
        $nullBaseline->forceFill(['last_done_km' => null]);

        $this->assertTrue($nullBaseline->isBaselineMissing());

        $this->actingAs($spv)
            ->get(route('approval-queue.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApprovalQueue/Index')
                ->has('items', 2)
                ->where('items', function (mixed $items) use ($postponeBaselineItem, $zeroBaselineItem): bool {
                    $itemsById = collect($items)->keyBy('id');

                    return data_get($itemsById, $zeroBaselineItem->id.'.baseline_missing') === true
                        && data_get($itemsById, $zeroBaselineItem->id.'.due_date') === null
                        && data_get($itemsById, $postponeBaselineItem->id.'.baseline_missing') === true
                        && data_get($itemsById, $postponeBaselineItem->id.'.due_date') === null;
                })
            );

        $this->actingAs($spv)
            ->post(route('approval-queue.store'), [
                'decision' => 'approve',
                'item_ids' => [$zeroBaselineItem->id],
            ])
            ->assertStatus(422);

        $this->assertSame('replace', $zeroBaselineItem->refresh()->status);
    }

    public function test_approval_queue_shows_all_pending_items_from_the_same_work_order(): void
    {
        [$spv, $serviceA, $unrelatedItem] = $this->createApprovalScenario();
        $unrelatedItem->update(['status' => 'complete']);
        $serviceA->planningItem()->update(['name' => 'Service A']);
        $serviceA->workOrder->unit()->update(['current_plate' => 'DD 8182 ST']);

        $serviceBPlanningItem = PlanningItem::query()->create([
            'name' => 'Service B',
            'interval_km' => 10000,
            'interval_days' => 90,
        ]);
        $serviceBPlanning = UnitPlanning::query()->create([
            'unit_id' => $serviceA->workOrder->unit_id,
            'planning_item_id' => $serviceBPlanningItem->id,
            'last_done_km' => 0,
            'last_done_date' => null,
            'next_due_km' => 10000,
            'next_due_date' => today()->addDays(30)->toDateString(),
        ]);
        $serviceB = WorkOrderItem::query()->create([
            'work_order_id' => $serviceA->work_order_id,
            'unit_planning_id' => $serviceBPlanning->id,
            'planning_item_id' => $serviceBPlanningItem->id,
            'status' => 'replace',
            'action' => 'replace',
            'reason' => 'Alasan dari Planner Area.',
            'submitted_by' => $serviceA->submitted_by,
        ]);
        $submittedAt = now()->subDays(3)->setMicrosecond(0);

        $serviceA->forceFill(['created_at' => $submittedAt, 'updated_at' => $submittedAt])->save();
        $serviceB->forceFill(['created_at' => $submittedAt, 'updated_at' => $submittedAt])->save();

        $this->actingAs($spv)
            ->get(route('approval-queue.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApprovalQueue/Index')
                ->has('items', 2)
                ->where('items', fn (mixed $items): bool => collect($items)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$serviceA->id, $serviceB->id])->sort()->values()->all())
            );
    }

    public function test_approval_queue_source_uses_collapsible_bottom_drawer_and_verification_list(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/ApprovalQueue/Index.tsx'));
        $odometerSources = collect([
            $pageSource,
            file_get_contents(resource_path('js/Pages/WorkList/Index.tsx')),
            file_get_contents(resource_path('js/Pages/WorkOrders/Show.tsx')),
        ]);

        $this->assertStringContainsString('isActionPanelOpen', $pageSource);
        $this->assertStringContainsString('Lanjutkan →', $pageSource);
        $this->assertStringContainsString('max-h-[70vh] overflow-y-auto', $pageSource);
        $this->assertStringContainsString('Item yang dipilih:', $pageSource);
        $this->assertStringContainsString('{item.plate_number} — {item.item_name}', $pageSource);
        $this->assertStringContainsString('dan {hiddenVerificationCount} lainnya', $pageSource);
        $this->assertStringContainsString('Tanggal Submit', $pageSource);
        $this->assertStringContainsString('Due Date', $pageSource);
        $this->assertStringContainsString('line-through', $pageSource);
        $this->assertStringContainsString('<StatusBadge tone="neutral">Baseline Belum Diisi</StatusBadge>', $pageSource);
        $this->assertStringContainsString('Mekanik & Jadwal', $pageSource);
        $this->assertStringContainsString('Jadwal Pengerjaan', $pageSource);
        $this->assertStringContainsString('Belum ditentukan', $pageSource);
        $this->assertStringContainsString('<Card size="sm"', $pageSource);
        $this->assertStringContainsString("blocked: 'Blocked'", $pageSource);
        $odometerSources->each(function (string $source): void {
            $this->assertStringContainsString('KM saat ini:', $source);
            $this->assertStringContainsString('baseline belum lengkap', $source);
        });
    }

    public function test_colored_action_panels_have_dark_mode_contrast_variants(): void
    {
        $sources = collect([
            file_get_contents(resource_path('js/Pages/WorkOrders/Show.tsx')),
            file_get_contents(resource_path('js/Pages/ApprovalQueue/Index.tsx')),
            file_get_contents(resource_path('js/Pages/Inspections/Create.tsx')),
            file_get_contents(resource_path('js/Pages/Mechanic/Tasks.tsx')),
        ])->implode('\n');

        $this->assertStringContainsString('dark:bg-sky-500/15', $sources);
        $this->assertStringContainsString('dark:bg-orange-500/15', $sources);
        $this->assertStringContainsString('dark:bg-violet-500/15', $sources);
        $this->assertStringContainsString('dark:bg-green-500/15', $sources);
        $this->assertStringContainsString('dark:text-indigo-200', $sources);
    }

    public function test_batch_approve_works_for_multiple_items_across_regions(): void
    {
        [$spv, $replaceItem, $postponeItem] = $this->createApprovalScenario();
        User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($spv)
            ->post(route('approval-queue.store'), [
                'decision' => 'approve',
                'item_ids' => [$replaceItem->id, $postponeItem->id],
            ])
            ->assertRedirect(route('approval-queue.index'));

        $this->assertSame('in_progress', $replaceItem->refresh()->status);
        $this->assertSame('postponed', $postponeItem->refresh()->status);
        $this->assertSame($postponeItem->new_due_km, $postponeItem->unitPlanning->refresh()->next_due_km);
        $this->assertSame(0, WorkOrderItem::query()->whereIn('id', [$replaceItem->id, $postponeItem->id])->whereIn('status', ['replace', 'postpone', 'pending_create'])->count());
    }

    /**
     * Approve tidak melempar item ke mekanik selama item itu belum punya
     * jadwalnya sendiri — approve dan penjadwalan adalah dua langkah terpisah.
     */
    public function test_approved_item_without_schedule_stays_on_hold(): void
    {
        [$spv, $replaceItem] = $this->createApprovalScenario();
        $replaceItem->update(['scheduled_date' => null]);

        $this->actingAs($spv)
            ->post(route('approval-queue.store'), [
                'decision' => 'approve',
                'item_ids' => [$replaceItem->id],
            ])
            ->assertRedirect(route('approval-queue.index'));

        $replaceItem->refresh();

        $this->assertSame('on_hold', $replaceItem->status);
        $this->assertNotNull($replaceItem->approved_at);
    }

    public function test_batch_reject_requires_reason_and_rejects_multiple_items(): void
    {
        [$spv, $replaceItem, $postponeItem] = $this->createApprovalScenario();

        $this->actingAs($spv)
            ->post(route('approval-queue.store'), [
                'decision' => 'reject',
                'item_ids' => [$replaceItem->id, $postponeItem->id],
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($spv)
            ->post(route('approval-queue.store'), [
                'decision' => 'reject',
                'item_ids' => [$replaceItem->id, $postponeItem->id],
                'reason' => 'Data belum lengkap.',
            ])
            ->assertRedirect(route('approval-queue.index'));

        $this->assertSame('rejected', $replaceItem->refresh()->status);
        $this->assertSame('Data belum lengkap.', $replaceItem->notes);
        $this->assertSame('rejected', $postponeItem->refresh()->status);
        $this->assertSame(0, WorkOrderItem::query()->whereIn('id', [$replaceItem->id, $postponeItem->id])->whereIn('status', ['replace', 'postpone', 'pending_create'])->count());
    }

    public function test_work_orders_and_work_list_pages_still_use_existing_components(): void
    {
        [$spv, $workListItem] = $this->createApprovalScenario();
        $workListItem->update(['status' => 'on_hold']);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => Region::query()->first()->id, 'site_id' => null]);

        $this->actingAs($spv)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('WorkOrders/Index')->has('boardColumns'));

        $this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkList/Index')
                ->has('items', 1)
                ->where('items.0.current_odo', 1000)
                ->where('items.0.baseline_incomplete', false)
            );
    }

    /**
     * @return array{0: User, 1: WorkOrderItem, 2: WorkOrderItem}
     */
    private function createApprovalScenario(): array
    {
        $spv = User::factory()->create(['role' => UserRole::SpvHo]);
        $planner = User::factory()->create(['role' => UserRole::PlannerArea]);
        $firstRegion = Region::query()->create(['name' => 'Kalimantan']);
        $secondRegion = Region::query()->create(['name' => 'Sulawesi']);
        $firstSite = Site::query()->create(['name' => 'BPN', 'region' => 'Kalimantan', 'region_id' => $firstRegion->id]);
        $secondSite = Site::query()->create(['name' => 'MKS', 'region' => 'Sulawesi', 'region_id' => $secondRegion->id]);

        $replaceItem = $this->createPendingItem($firstSite, $planner, 'KT 1001 AA', 'Filter Oli', 'replace');
        $postponeItem = $this->createPendingItem($secondSite, $planner, 'DD 2002 BB', 'Brake Pad', 'postpone');

        $mechanic = User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $firstSite->id]);
        $replaceItem->workOrder->update(['assigned_mechanic_id' => $mechanic->id]);
        $replaceItem->update(['scheduled_date' => today()->addDay()->toDateString()]);

        return [$spv, $replaceItem->refresh(), $postponeItem];
    }

    private function createPendingItem(Site $site, User $planner, string $plate, string $itemName, string $status): WorkOrderItem
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
            'last_done_km' => 1000,
            'last_done_date' => today()->subDays(90)->toDateString(),
            'next_due_km' => 10000,
            'next_due_date' => today()->addDays(30)->toDateString(),
        ]);
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id,
            'site_id' => $site->id,
            'trigger_type' => 'normal',
            'status' => 'open',
            'submitted_by' => $planner->id,
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $planning->id,
            'planning_item_id' => $planningItem->id,
            'status' => $status,
            'action' => $status,
            'reason' => 'Alasan dari Planner Area.',
            'new_due_km' => $status === 'postpone' ? 15000 : null,
            'new_due_date' => $status === 'postpone' ? today()->addDays(60)->toDateString() : null,
            'submitted_by' => $planner->id,
        ]);
    }
}
