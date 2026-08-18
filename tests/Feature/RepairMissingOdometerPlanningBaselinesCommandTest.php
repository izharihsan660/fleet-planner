<?php

namespace Tests\Feature;

use App\Models\PlanningItem;
use App\Models\Site;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairMissingOdometerPlanningBaselinesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_affected_units_without_changing_plannings(): void
    {
        $site = Site::query()->create(['name' => 'Site Audit', 'region' => 'Region Test']);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service Audit',
            'interval_km' => 5000,
            'interval_days' => 90,
        ]);
        $unit = Unit::query()->create($this->unitPayload($site->id, 'KT 1000 AA'));
        $unitPlanning = $unit->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();
        $unitPlanning->update([
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => 5000,
        ]);

        $this->artisan('maintenance:repair-missing-odometer-baselines', ['--dry-run' => true])
            ->expectsOutputToContain('MODE: DRY-RUN')
            ->expectsOutputToContain('Unit terdampak: 1')
            ->expectsOutputToContain('Unit planning terdampak: 1')
            ->assertSuccessful();

        $this->assertSame(5000, $unitPlanning->refresh()->next_due_km);
    }

    public function test_execute_clears_only_km_due_baselines_for_units_without_readings(): void
    {
        $site = Site::query()->create(['name' => 'Site Repair', 'region' => 'Region Test']);
        $planningItem = PlanningItem::query()->create([
            'name' => 'Service Repair',
            'interval_km' => 5000,
            'interval_days' => 90,
        ]);
        $unitWithoutReading = Unit::query()->create($this->unitPayload($site->id, 'KT 2000 BB'));
        $affectedPlanning = $unitWithoutReading->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();
        $affectedPlanning->update([
            'last_done_date' => today()->subMonth()->toDateString(),
            'next_due_km' => 5000,
            'next_due_date' => now()->addDays(90)->toDateString(),
        ]);

        $unitWithReading = Unit::query()->create($this->unitPayload($site->id, 'KT 3000 CC', 10000, true));
        $validPlanning = $unitWithReading->unitPlannings()->where('planning_item_id', $planningItem->id)->firstOrFail();

        $this->artisan('maintenance:repair-missing-odometer-baselines', ['--execute' => true])
            ->expectsOutputToContain('MODE: EXECUTE')
            ->expectsOutputToContain('Unit terdampak: 1')
            ->assertSuccessful();

        $this->assertNull($affectedPlanning->refresh()->next_due_km);
        $this->assertNotNull($affectedPlanning->next_due_date);
        $this->assertSame(15000, $validPlanning->refresh()->next_due_km);
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(int $siteId, string $plate, int $currentOdometer = 0, bool $hasOdometerReading = false): array
    {
        return [
            'site_id' => $siteId,
            'customer' => 'Customer Test',
            'current_plate' => $plate,
            'type' => 'Pickup',
            'brand' => 'Toyota',
            'year' => 2024,
            'current_odo' => $currentOdometer,
            'has_odometer_reading' => $hasOdometerReading,
            'status' => 'active',
        ];
    }
}
