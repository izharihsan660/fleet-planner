<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\RecalculateDueDatesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class PlanningItemRecalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_intervals_recalculates_all_related_unit_plannings_and_logs_affected_rows(): void
    {
        Log::spy();

        $site = Site::query()->create(['name' => 'Site Recalculation', 'region' => 'Sulawesi']);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Ganti Oli',
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);
        $otherPlanningItem = PlanningItem::query()->create([
            'name' => 'Filter Oli',
            'interval_km' => 5000,
            'interval_days' => 90,
        ]);
        $firstUnit = $this->unit($site, 'DD 1001 AA');
        $secondUnit = $this->unit($site, 'DD 1002 AB');

        $firstPlanning = $this->planning($firstUnit, $planningItem, 10000, '2026-01-01');
        $secondPlanning = $this->planning($secondUnit, $planningItem, 20000, '2026-02-15');
        $unrelatedPlanning = $this->planning($firstUnit, $otherPlanningItem, 30000, '2026-03-01');
        $admin = User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($admin)
            ->patch(route('planning-items.update', $planningItem), [
                'name' => $planningItem->name,
                'interval_km' => 2500,
                'interval_days' => 45,
            ])
            ->assertRedirect(route('planning-items.index'));

        $this->assertSame(12500, $firstPlanning->refresh()->next_due_km);
        $this->assertSame('2026-02-15', $firstPlanning->next_due_date?->toDateString());
        $this->assertSame(22500, $secondPlanning->refresh()->next_due_km);
        $this->assertSame('2026-04-01', $secondPlanning->next_due_date?->toDateString());
        $this->assertSame(35000, $unrelatedPlanning->refresh()->next_due_km);
        $this->assertSame('2026-05-30', $unrelatedPlanning->next_due_date?->toDateString());

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Planning item due dates recalculated.', [
                'planning_item_id' => $planningItem->id,
                'affected_rows' => 2,
            ]);
    }

    public function test_recalculation_does_not_touch_unit_plannings_with_incomplete_baselines(): void
    {
        $site = Site::query()->create(['name' => 'Site Baseline', 'region' => 'Kalimantan']);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service A',
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);
        $validPlanning = $this->planning($this->unit($site, 'KT 2001 AA'), $planningItem, 5000, '2026-06-01');
        $zeroKmPlanning = UnitPlanning::query()->create([
            'unit_id' => $this->unit($site, 'KT 2002 AB')->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 0,
            'last_done_date' => '2026-06-01',
            'next_due_km' => 9999,
            'next_due_date' => '2030-01-01',
        ]);
        $missingDatePlanning = UnitPlanning::query()->create([
            'unit_id' => $this->unit($site, 'KT 2003 AC')->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => 7000,
            'last_done_date' => null,
            'next_due_km' => 7777,
            'next_due_date' => '2031-01-01',
        ]);
        $admin = User::factory()->create(['role' => UserRole::SpvHo]);

        $this->actingAs($admin)
            ->patch(route('planning-items.update', $planningItem), [
                'name' => $planningItem->name,
                'interval_km' => 2000,
                'interval_days' => 60,
            ])
            ->assertRedirect(route('planning-items.index'));

        $this->assertSame(7000, $validPlanning->refresh()->next_due_km);
        $this->assertSame('2026-07-31', $validPlanning->next_due_date?->toDateString());
        $this->assertSame(9999, $zeroKmPlanning->refresh()->next_due_km);
        $this->assertSame('2030-01-01', $zeroKmPlanning->next_due_date?->toDateString());
        $this->assertSame(7777, $missingDatePlanning->refresh()->next_due_km);
        $this->assertSame('2031-01-01', $missingDatePlanning->next_due_date?->toDateString());
    }

    public function test_recalculation_rolls_back_every_update_when_one_unit_fails(): void
    {
        $site = Site::query()->create(['name' => 'Site Rollback', 'region' => 'Kalimantan']);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service B',
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);
        $firstPlanning = $this->planning($this->unit($site, 'KT 3001 AA'), $planningItem, 10000, '2026-01-01');
        $secondPlanning = $this->planning($this->unit($site, 'KT 3002 AB'), $planningItem, 20000, '2026-02-01');
        $updateAttempts = 0;

        UnitPlanning::updating(function (UnitPlanning $unitPlanning) use (&$updateAttempts): void {
            $updateAttempts++;

            if ($updateAttempts === 2) {
                throw new RuntimeException('Forced recalculation failure.');
            }
        });

        try {
            app(RecalculateDueDatesService::class)->update($planningItem, [
                'name' => $planningItem->name,
                'interval_km' => 4000,
                'interval_days' => 120,
            ]);

            $this->fail('The recalculation should have thrown an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced recalculation failure.', $exception->getMessage());
        }

        $planningItem->refresh();

        $this->assertSame(2, $updateAttempts);
        $this->assertSame(1000, $planningItem->interval_km);
        $this->assertSame(30, $planningItem->interval_days);
        $this->assertSame(11000, $firstPlanning->refresh()->next_due_km);
        $this->assertSame('2026-01-31', $firstPlanning->next_due_date?->toDateString());
        $this->assertSame(21000, $secondPlanning->refresh()->next_due_km);
        $this->assertSame('2026-03-03', $secondPlanning->next_due_date?->toDateString());
    }

    public function test_recalculation_keeps_vehicle_category_override_as_the_effective_interval(): void
    {
        $site = Site::query()->create(['name' => 'Site Override', 'region' => 'Sulawesi']);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service C',
            'interval_km' => 1000,
            'interval_days' => 30,
        ]);
        PlanningItemOverride::query()->create([
            'planning_item_id' => $planningItem->id,
            'vehicle_category' => 'truk_ringan',
            'interval_km' => 1500,
            'interval_days' => 40,
        ]);
        $unit = $this->unit($site, 'DD 4001 AA', 'truk_ringan');
        $unitPlanning = $this->planning($unit, $planningItem, 10000, '2026-01-01');

        app(RecalculateDueDatesService::class)->update($planningItem, [
            'name' => $planningItem->name,
            'interval_km' => 5000,
            'interval_days' => 180,
        ]);

        $this->assertSame(11500, $unitPlanning->refresh()->next_due_km);
        $this->assertSame('2026-02-10', $unitPlanning->next_due_date?->toDateString());
    }

    private function unit(Site $site, string $plate, string $vehicleCategory = 'truk_ringan'): Unit
    {
        return Unit::withoutEvents(fn (): Unit => Unit::query()->create([
            'site_id' => $site->id,
            'customer' => 'PT NAJ',
            'current_plate' => $plate,
            'type' => 'Truck',
            'brand' => 'Hino',
            'vehicle_category' => $vehicleCategory,
            'year' => 2024,
            'current_odo' => 10000,
            'has_odometer_reading' => true,
            'status' => 'active',
        ]));
    }

    private function planning(Unit $unit, PlanningItem $planningItem, int $lastDoneKm, string $lastDoneDate): UnitPlanning
    {
        return UnitPlanning::query()->create([
            'unit_id' => $unit->id,
            'planning_item_id' => $planningItem->id,
            'last_done_km' => $lastDoneKm,
            'last_done_date' => $lastDoneDate,
            'next_due_km' => $lastDoneKm + $planningItem->interval_km,
            'next_due_date' => CarbonImmutable::parse($lastDoneDate)
                ->addDays($planningItem->interval_days)
                ->toDateString(),
        ]);
    }
}
