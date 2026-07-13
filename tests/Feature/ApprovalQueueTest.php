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
        $oldItem->forceFill(['updated_at' => now()->subDays(5)])->save();
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
                ->where('items.1.id', $newerItem->id)
            );
    }

    public function test_approval_queue_source_uses_collapsible_bottom_drawer_and_verification_list(): void
    {
        $pageSource = file_get_contents(resource_path('js/Pages/ApprovalQueue/Index.tsx'));

        $this->assertStringContainsString('isActionPanelOpen', $pageSource);
        $this->assertStringContainsString('Lanjutkan →', $pageSource);
        $this->assertStringContainsString('max-h-[70vh] overflow-y-auto', $pageSource);
        $this->assertStringContainsString('Item yang dipilih:', $pageSource);
        $this->assertStringContainsString('{item.plate_number} — {item.item_name}', $pageSource);
        $this->assertStringContainsString('dan {hiddenVerificationCount} lainnya', $pageSource);
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
        [$spv] = $this->createApprovalScenario();
        $planner = User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => Region::query()->first()->id, 'site_id' => null]);

        $this->actingAs($spv)
            ->get(route('work-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('WorkOrders/Index')->has('boardColumns'));

        $this->actingAs($planner)
            ->get(route('work-list.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('WorkList/Index')->has('items'));
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

        return [$spv, $replaceItem, $postponeItem];
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
            'last_done_km' => 0,
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
