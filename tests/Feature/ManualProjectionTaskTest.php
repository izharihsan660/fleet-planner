<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ManualProjection;
use App\Models\Notification;
use App\Models\PlanningItem;
use App\Models\Region;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceTriggerService;
use App\Services\ProjectionService;
use App\Services\WorkOrderItemGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ManualProjectionTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_create_submits_replace_with_assignment_and_notifies_supervisor_without_changing_due(): void
    {
        [$unit, $planning, $planner, $mechanic, $supervisor] = $this->context();
        $before = $planning->getAttributes();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $task = $manual->workOrderItem;

        $this->assertSame('replace', $task->status);
        $this->assertSame('replace', $task->action);
        $this->assertSame('Kebutuhan Manual: Rusak — Kampas retak', $task->reason);
        $this->assertSame($planner->id, $task->submitted_by);
        $this->assertNotNull($task->submitted_at);
        $this->assertNull($task->approved_at);
        $this->assertSame('manual', $task->workOrder->trigger_type);
        $this->assertSame($planner->id, $task->workOrder->submitted_by);
        $this->assertSame($mechanic->id, $task->workOrder->assigned_mechanic_id);
        $this->assertSame($mechanic->id, $manual->assigned_mechanic_id);
        $this->assertSame($manual->target_date->toDateString(), $task->scheduled_date->toDateString());
        $this->assertSame($before, $planning->fresh()->getAttributes());
        $this->assertTrue($manual->ownsTask($task));
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_items', 1);

        $notification = Notification::query()->where('user_id', $supervisor->id)->where('type', 'task_submitted')->sole();
        $this->assertSame($task->id, $notification->data['work_order_item_id']);
        $this->assertSame('replace', $notification->data['action']);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);

        $this->get(route('projections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('manual_projections.data.0.task_status_label', 'Menunggu Approval')
            ->where('manual_projections.data.0.work_order_id', $task->work_order_id)
            ->where('manual_projections.data.0.assigned_mechanic_name', $mechanic->name));

        $this->actingAs($supervisor)->post(route('work-orders.approve', $task->workOrder))->assertRedirect()->assertSessionHasNoErrors();
        $line = app(ProjectionService::class)->calculate(1)['by_unit'][0]['items'][0];
        $this->assertSame('manual', $line['trigger']);
        $this->assertSame('2026-11-09', $line['estimated_due_date']);
        $this->get(route('projections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('manual_projections.data.0.task_status_label', 'Disetujui'));
    }

    #[DataProvider('activeStatuses')]
    public function test_existing_active_task_is_linked_without_creation_or_changes(string $status): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $task = $this->existingTask($unit, $planning, $mechanic, $status);
        $before = $task->getAttributes();
        $workOrderBefore = $task->workOrder->getAttributes();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);

        $this->assertSame($task->id, $manual->work_order_item_id);
        $this->assertFalse($manual->ownsTask($task));
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame($workOrderBefore, $task->workOrder->fresh()->getAttributes());
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_items', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    /** @return array<string, array{string}> */
    public static function activeStatuses(): array
    {
        $statuses = ['on_hold', 'overdue', 'pending_create', 'replace', 'postpone', 'in_progress', 'blocked', 'breakdown', 'postponed'];

        return array_combine($statuses, array_map(fn (string $status): array => [$status], $statuses));
    }

    public function test_rejected_task_is_ignored_only_by_manual_creation_and_keeps_system_trigger_blocked(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $planning->update(['next_due_km' => $unit->current_odo]);
        $rejected = $this->existingTask($unit, $planning, $mechanic, 'rejected');
        $guard = app(WorkOrderItemGuard::class);
        $this->assertSame($rejected->id, $guard->findBlockingItem($planning)->id);
        $this->assertSame([], app(MaintenanceTriggerService::class)->checkAndTrigger($unit->fresh()));
        $this->assertDatabaseCount('work_order_items', 1);

        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $this->assertNotSame($rejected->id, $manual->work_order_item_id);
        $this->assertSame('replace', $manual->workOrderItem->status);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertDatabaseCount('work_order_items', 2);

        $this->patch(route('projections.manual.cancel', $manual))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($rejected->id, $guard->findBlockingItem($planning)->id);
        $this->assertSame([], app(MaintenanceTriggerService::class)->checkAndTrigger($unit->fresh()));
        $this->assertDatabaseCount('work_order_items', 2);
        $this->assertContains('rejected', WorkOrderItem::BLOCKING_STATUSES);
    }

    public function test_excluded_item_is_rejected_without_creating_work_or_notifications(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $planning->update(['is_excluded' => true]);
        $before = $planning->fresh()->getAttributes();

        $this->actingAs($planner)->postJson(route('projections.manual.store'), $this->payload($unit, $planning, $mechanic))
            ->assertUnprocessable()->assertJsonPath('errors.planning_item_id.0', 'Item ini dikecualikan untuk unit tersebut');
        $this->assertSame($before, $planning->fresh()->getAttributes());
        $this->assertDatabaseCount('manual_projections', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_mechanic_from_another_site_and_non_mechanic_user_are_rejected(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        [, , , $otherMechanic] = $this->context();

        foreach ([$otherMechanic->id, $planner->id] as $invalidMechanicId) {
            $this->actingAs($planner)->postJson(route('projections.manual.store'), [
                ...$this->payload($unit, $planning, $mechanic), 'assigned_mechanic_id' => $invalidMechanicId,
            ])->assertUnprocessable()->assertJsonValidationErrors('assigned_mechanic_id');
        }

        $this->assertDatabaseCount('manual_projections', 0);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_site_without_mechanics_has_empty_options_and_cannot_save(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $mechanic->delete();
        $this->actingAs($planner)->get(route('projections.index'))->assertInertia(fn (Assert $page) => $page
            ->has('manual_mechanics', 0)
            ->where('manual_units.0.site_id', $unit->site_id)
            ->where('manual_units.0.site_name', $unit->site->name));

        foreach ([null, $mechanic->id] as $mechanicId) {
            $this->postJson(route('projections.manual.store'), [
                ...$this->payload($unit, $planning, $mechanic), 'assigned_mechanic_id' => $mechanicId,
            ])->assertUnprocessable()->assertJsonValidationErrors('assigned_mechanic_id');
        }

        $this->assertDatabaseCount('manual_projections', 0);
        $this->assertDatabaseCount('work_order_items', 0);
    }

    public function test_unit_and_mechanic_options_follow_region_scope_and_include_site_metadata(): void
    {
        [$unit, , $planner, $mechanic] = $this->context();
        [$secondUnit, , , $secondMechanic] = $this->context();
        $this->context('Other Region');

        $this->actingAs($planner)->get(route('projections.index'))->assertInertia(fn (Assert $page) => $page
            ->has('manual_units', 2)->has('manual_mechanics', 2)
            ->where('manual_units', fn ($options): bool => collect($options)->pluck('site_id')->sort()->values()->all() === collect([$unit->site_id, $secondUnit->site_id])->sort()->values()->all())
            ->where('manual_mechanics', fn ($options): bool => collect($options)->pluck('id')->sort()->values()->all() === collect([$mechanic->id, $secondMechanic->id])->sort()->values()->all()));
    }

    public function test_unapproved_manual_does_not_override_an_existing_system_projection(): void
    {
        [$unit, $planning, $planner, $mechanic, $supervisor] = $this->context();
        $planning->update(['next_due_date' => '2026-11-20']);
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $items = app(ProjectionService::class)->calculate(1)['by_unit'][0]['items'];
        $this->assertCount(1, $items);
        $this->assertSame('date', $items[0]['trigger']);
        $this->assertSame('2026-11-20', $items[0]['estimated_due_date']);
        $this->assertFalse($items[0]['has_manual']);

        $this->actingAs($supervisor)->post(route('work-orders.approve', $manual->workOrderItem->workOrder))->assertRedirect();
        $approvedItems = app(ProjectionService::class)->calculate(1)['by_unit'][0]['items'];
        $this->assertCount(1, $approvedItems);
        $this->assertSame('manual', $approvedItems[0]['trigger']);
        $this->assertSame('2026-11-09', $approvedItems[0]['estimated_due_date']);
    }

    #[DataProvider('taskOrigins')]
    public function test_rejection_cancels_linked_manual_with_supervisor_audit_and_allows_a_new_task(bool $existing): void
    {
        [$unit, $planning, $planner, $mechanic, $supervisor] = $this->context();

        if ($existing) {
            $this->existingTask($unit, $planning, $mechanic, 'replace');
        }

        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $task = $manual->workOrderItem;

        $this->actingAs($supervisor)->post(route('work-orders.reject', $task->workOrder))->assertRedirect();
        $this->assertSame('rejected', $task->fresh()->status);
        $this->assertSame('cancelled', $manual->refresh()->status);
        $this->assertSame($supervisor->id, $manual->cancelled_by);
        $this->assertNotNull($manual->cancelled_at);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);

        $replacement = $this->createManual($unit, $planning, $planner, $mechanic);
        $this->assertNotSame($task->id, $replacement->work_order_item_id);
        $this->assertDatabaseCount('work_order_items', 2);
    }

    /** @return array<string, array{bool}> */
    public static function taskOrigins(): array
    {
        return ['new task' => [false], 'linked existing task' => [true]];
    }

    public function test_completion_fulfills_the_linked_manual_and_removes_projection(): void
    {
        [$unit, $planning, $planner, $mechanic, $supervisor] = $this->context();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic, ['target_date' => '2026-11-02']);
        $task = $manual->workOrderItem;
        $this->actingAs($supervisor)->post(route('work-orders.approve', $task->workOrder))->assertRedirect();
        $this->assertCount(1, app(ProjectionService::class)->calculate(1)['by_unit']);
        $this->get(route('projections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('manual_projections.data.0.task_status_label', 'Dikerjakan'));

        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$task->workOrder, $task]), [
            'completed_odo' => 1350, 'completed_date' => '2026-11-02',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('fulfilled', $manual->refresh()->status);
        $this->assertNotNull($manual->fulfilled_at);
        $this->assertSame([], app(ProjectionService::class)->calculate(1)['by_unit']);
    }

    public function test_completion_of_a_different_task_for_the_same_planning_does_not_fulfill_a_linked_manual(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $otherTask = $this->existingTask($unit, $planning, $mechanic, 'in_progress');
        $this->actingAs($mechanic)->post(route('work-orders.items.complete', [$otherTask->workOrder, $otherTask]), [
            'completed_odo' => 1350, 'completed_date' => '2026-11-02',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $manual->fresh()->status);
        $this->assertNull($manual->fresh()->fulfilled_at);
    }

    #[DataProvider('cancellableStatuses')]
    public function test_cancelling_an_owned_pending_task_cancels_the_task_and_work_order(string $status): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $task = $manual->workOrderItem;
        $task->update(['status' => $status]);

        $this->patch(route('projections.manual.cancel', $manual))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $manual->refresh()->status);
        $this->assertSame($planner->id, $manual->cancelled_by);
        $this->assertNotNull($manual->cancelled_at);
        $this->assertSame('cancelled', $task->refresh()->status);
        $this->assertSame('Kebutuhan manual dibatalkan', $task->reason);
        $this->assertSame('cancelled', $task->workOrder->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function cancellableStatuses(): array
    {
        return ['pending approval' => ['replace'], 'on hold' => ['on_hold']];
    }

    #[DataProvider('approvedStatuses')]
    public function test_approved_owned_task_prevents_manual_cancellation(string $status): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $task = $manual->workOrderItem;
        $task->update(['status' => $status, 'approved_at' => now()]);

        $this->patchJson(route('projections.manual.cancel', $manual))->assertUnprocessable()
            ->assertJsonPath('errors.manual_projection.0', 'Task sudah disetujui, selesaikan atau batalkan lewat Work Order');
        $this->assertSame('active', $manual->fresh()->status);
        $this->assertSame($status, $task->fresh()->status);
        $this->assertNull($manual->fresh()->cancelled_at);
    }

    /** @return array<string, array{string}> */
    public static function approvedStatuses(): array
    {
        return ['approved on hold' => ['on_hold'], 'in progress' => ['in_progress']];
    }

    #[DataProvider('existingCancellationStatuses')]
    public function test_cancelling_a_manual_linked_to_an_existing_task_does_not_touch_the_task(string $status): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $task = $this->existingTask($unit, $planning, $mechanic, $status);
        $task->update(['approved_at' => now()]);
        $before = $task->getAttributes();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);

        $this->patch(route('projections.manual.cancel', $manual))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $manual->fresh()->status);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame('open', $task->workOrder->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function existingCancellationStatuses(): array
    {
        return [...self::cancellableStatuses(), 'in progress' => ['in_progress']];
    }

    public function test_failure_in_reused_notification_rolls_back_manual_and_task_creation(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $this->mock(FleetNotificationService::class)->shouldReceive('taskSubmitted')->once()->andThrow(new RuntimeException('notification failed'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($planner)->post(route('projections.manual.store'), $this->payload($unit, $planning, $mechanic));
            $this->fail('Expected the submission failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('notification failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('manual_projections', 0);
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_items', 0);
        $this->assertSame(10000, $planning->fresh()->next_due_km);
    }

    public function test_new_foreign_keys_are_nullable_when_linked_records_are_deleted(): void
    {
        [$unit, $planning, $planner, $mechanic] = $this->context();
        $manual = $this->createManual($unit, $planning, $planner, $mechanic);
        $manual->workOrderItem->delete();
        $mechanic->delete();

        $this->assertNull($manual->fresh()->work_order_item_id);
        $this->assertNull($manual->fresh()->assigned_mechanic_id);
    }

    /** @return array{Unit, UnitPlanning, User, User, User} */
    private function context(string $regionName = 'Own Region'): array
    {
        $region = Region::query()->firstOrCreate(['name' => $regionName]);
        $site = Site::query()->create(['name' => fake()->unique()->word(), 'region_id' => $region->id, 'region' => $regionName]);
        $unit = Unit::withoutEvents(fn (): Unit => Unit::query()->create([
            'site_id' => $site->id, 'customer' => 'Test', 'current_plate' => fake()->unique()->bothify('KT #### ??'),
            'brand' => 'Toyota', 'type' => 'TOYOTA HILUX DC', 'year' => 2024, 'current_odo' => 1300,
            'status' => 'active', 'has_odometer_reading' => false,
        ]));
        $item = PlanningItem::query()->create(['name' => fake()->unique()->word(), 'interval_km' => 5000, 'interval_days' => 365]);
        $planning = UnitPlanning::query()->create([
            'unit_id' => $unit->id, 'planning_item_id' => $item->id, 'last_done_km' => 1000,
            'last_done_date' => '2026-10-01', 'next_due_km' => 10000, 'next_due_date' => '2027-10-01',
        ]);

        return [
            $unit, $planning->refresh(),
            User::factory()->create(['role' => UserRole::PlannerArea, 'region_id' => $region->id]),
            User::factory()->create(['role' => UserRole::Mekanik, 'site_id' => $site->id]),
            User::factory()->create(['role' => UserRole::SpvHo]),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Unit $unit, UnitPlanning $planning, User $mechanic): array
    {
        return [
            'unit_id' => $unit->id, 'planning_item_id' => $planning->planning_item_id,
            'assigned_mechanic_id' => $mechanic->id, 'target_date' => '2026-11-09',
            'reason' => 'rusak', 'notes' => 'Kampas retak',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function createManual(Unit $unit, UnitPlanning $planning, User $planner, User $mechanic, array $attributes = []): ManualProjection
    {
        $this->actingAs($planner)->from(route('projections.index'))->post(route('projections.manual.store'), [
            ...$this->payload($unit, $planning, $mechanic), ...$attributes,
        ])->assertRedirect(route('projections.index'))->assertSessionHasNoErrors();

        return ManualProjection::query()->active()->where('unit_id', $unit->id)->sole();
    }

    private function existingTask(Unit $unit, UnitPlanning $planning, User $mechanic, string $status): WorkOrderItem
    {
        $workOrder = WorkOrder::query()->create([
            'unit_id' => $unit->id, 'site_id' => $unit->site_id, 'trigger_type' => 'normal',
            'status' => 'open', 'assigned_mechanic_id' => $mechanic->id, 'notes' => 'Task yang sudah ada',
        ]);

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id, 'unit_planning_id' => $planning->id,
            'planning_item_id' => $planning->planning_item_id, 'status' => $status, 'action' => 'replace',
            'scheduled_date' => '2026-11-02', 'reason' => 'Task lama',
        ])->refresh();
    }
}
