<?php

namespace App\Services;

use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MaintenanceTriggerService
{
    /**
     * @return array<int, WorkOrderItem>
     */
    public function checkAndTrigger(Unit $unit): array
    {
        return DB::transaction(function () use ($unit): array {
            $unit->loadMissing('unitPlannings.planningItem');

            $warningKm = $this->thresholdValue('warning_km', 500);
            $warningDays = $this->thresholdValue('warning_days', 7);
            $today = CarbonImmutable::today();

            $triggeredPlannings = $unit->unitPlannings
                ->filter(fn (UnitPlanning $unitPlanning): bool => $this->isDueSoon($unit, $unitPlanning, $warningKm, $warningDays, $today))
                ->reject(fn (UnitPlanning $unitPlanning): bool => $this->hasActiveItem($unitPlanning))
                ->values();

            if ($triggeredPlannings->isEmpty()) {
                return [];
            }

            $workOrder = WorkOrder::query()
                ->where('unit_id', $unit->id)
                ->where('status', 'open')
                ->latest('id')
                ->first();

            if (! $workOrder) {
                $workOrder = WorkOrder::query()->create([
                    'unit_id' => $unit->id,
                    'site_id' => $unit->site_id,
                    'trigger_type' => 'normal',
                    'status' => 'open',
                ]);
            }

            return $triggeredPlannings
                ->map(fn (UnitPlanning $unitPlanning): WorkOrderItem => WorkOrderItem::query()->create([
                    'work_order_id' => $workOrder->id,
                    'unit_planning_id' => $unitPlanning->id,
                    'planning_item_id' => $unitPlanning->planning_item_id,
                    'status' => 'on_hold',
                ]))
                ->all();
        });
    }

    private function thresholdValue(string $key, int $default): int
    {
        return (int) (SystemThreshold::query()->where('key', $key)->value('value') ?? $default);
    }

    private function isDueSoon(Unit $unit, UnitPlanning $unitPlanning, int $warningKm, int $warningDays, CarbonImmutable $today): bool
    {
        $isKmDueSoon = $unitPlanning->next_due_km !== null
            && $unit->current_odo >= ($unitPlanning->next_due_km - $warningKm);

        $isDateDueSoon = $unitPlanning->next_due_date !== null
            && $today->greaterThanOrEqualTo(CarbonImmutable::parse($unitPlanning->next_due_date)->subDays($warningDays));

        return $isKmDueSoon || $isDateDueSoon;
    }

    private function hasActiveItem(UnitPlanning $unitPlanning): bool
    {
        return WorkOrderItem::query()
            ->where('unit_planning_id', $unitPlanning->id)
            ->whereNotIn('status', ['complete', 'postponed', 'cancelled'])
            ->exists();
    }
}
