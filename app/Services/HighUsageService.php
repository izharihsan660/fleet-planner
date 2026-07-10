<?php

namespace App\Services;

use App\Models\HighUsageFlag;
use App\Models\InspectionLog;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class HighUsageService
{
    /**
     * @return array<int, HighUsageFlag>
     */
    public function detect(Unit $unit): array
    {
        return DB::transaction(function () use ($unit): array {
            $minimumInspectionData = $this->thresholdValue('min_inspection_data', 3);
            $thresholdPercentage = $this->thresholdValue('high_usage_threshold', 20);

            $logs = InspectionLog::query()
                ->where('unit_id', $unit->id)
                ->orderBy('inspection_date')
                ->orderBy('id')
                ->get(['inspection_date', 'odometer']);

            if ($logs->count() < $minimumInspectionData) {
                return [];
            }

            $firstLog = $logs->first();
            $lastLog = $logs->last();
            $days = max(1, CarbonImmutable::parse($firstLog->inspection_date)->diffInDays(CarbonImmutable::parse($lastLog->inspection_date)));
            $averageKmPerDay = round(($lastLog->odometer - $firstLog->odometer) / $days, 2);

            if ($averageKmPerDay <= 0) {
                return [];
            }

            $today = CarbonImmutable::today();

            return $unit->unitPlannings()
                ->with('planningItem:id,name')
                ->get()
                ->filter(fn (UnitPlanning $unitPlanning): bool => $this->shouldFlag($unit, $unitPlanning, $averageKmPerDay, $thresholdPercentage, $today))
                ->reject(fn (UnitPlanning $unitPlanning): bool => $this->hasActiveWorkOrderItem($unitPlanning))
                ->reject(fn (UnitPlanning $unitPlanning): bool => $this->hasActiveFlag($unitPlanning))
                ->map(fn (UnitPlanning $unitPlanning): HighUsageFlag => HighUsageFlag::query()->create([
                    'unit_id' => $unit->id,
                    'planning_item_id' => $unitPlanning->planning_item_id,
                    'unit_planning_id' => $unitPlanning->id,
                    'avg_km_per_day' => $averageKmPerDay,
                    'estimated_due_days' => (int) floor(($unitPlanning->next_due_km - $unit->current_odo) / $averageKmPerDay),
                    'flagged_at' => now(),
                ]))
                ->values()
                ->all();
        });
    }

    /**
     * @param  array{available_date?: string, new_due_km?: int, new_due_date?: string}  $data
     */
    public function takeAction(HighUsageFlag $flag, User $actor, string $action, array $data = []): void
    {
        DB::transaction(function () use ($flag, $actor, $action, $data): void {
            $flag->loadMissing('unit', 'unitPlanning');

            if ($action === 'triggered') {
                $this->createWorkOrderItem($flag, $actor, true);

                $flag->update([
                    'action_taken' => 'triggered',
                    'action_taken_at' => now(),
                    'action_taken_by' => $actor->id,
                    'resolved_at' => now(),
                ]);

                return;
            }

            if ($action === 'deferred') {
                $flag->update([
                    'action_taken' => 'deferred',
                    'action_taken_at' => now(),
                    'action_taken_by' => $actor->id,
                ]);

                return;
            }

            if ($action === 'scheduled') {
                $item = $this->createWorkOrderItem($flag, $actor, true, $data);
                app(FleetNotificationService::class)->taskSubmitted($item, 'postpone');

                $flag->update([
                    'action_taken' => 'triggered',
                    'action_taken_at' => now(),
                    'action_taken_by' => $actor->id,
                    'resolved_at' => now(),
                ]);
            }
        });
    }

    public function checkPendingFlags(): void
    {
        $pendingFlags = HighUsageFlag::query()
            ->whereNull('action_taken')
            ->where('flagged_at', '<=', now()->subDays(5))
            ->get();

        $deferredFlags = HighUsageFlag::query()
            ->where('action_taken', 'deferred')
            ->where('action_taken_at', '<=', now()->subDays(5))
            ->get();

        $pendingFlags->merge($deferredFlags)
            ->each(fn (HighUsageFlag $flag) => app(FleetNotificationService::class)->highUsageSecondWindow($flag));
    }

    private function shouldFlag(Unit $unit, UnitPlanning $unitPlanning, float $averageKmPerDay, int $thresholdPercentage, CarbonImmutable $today): bool
    {
        if ($unitPlanning->next_due_km === null || $unitPlanning->next_due_date === null) {
            return false;
        }

        $remainingNormalDays = $today->diffInDays(CarbonImmutable::parse($unitPlanning->next_due_date), false);

        if ($remainingNormalDays <= 0 || $unitPlanning->next_due_km <= $unit->current_odo) {
            return false;
        }

        $estimatedDueDays = ($unitPlanning->next_due_km - $unit->current_odo) / $averageKmPerDay;
        $changePercentage = (($remainingNormalDays - $estimatedDueDays) / $remainingNormalDays) * 100;

        return $changePercentage > $thresholdPercentage;
    }

    private function hasActiveFlag(UnitPlanning $unitPlanning): bool
    {
        return HighUsageFlag::query()
            ->where('unit_planning_id', $unitPlanning->id)
            ->whereNull('resolved_at')
            ->exists();
    }

    private function hasActiveWorkOrderItem(UnitPlanning $unitPlanning): bool
    {
        return WorkOrderItem::query()
            ->where('unit_planning_id', $unitPlanning->id)
            ->whereNotIn('status', ['complete', 'postponed', 'rejected', 'cancelled'])
            ->exists();
    }

    /**
     * @param  array{available_date?: string, new_due_km?: int, new_due_date?: string}  $data
     */
    private function createWorkOrderItem(HighUsageFlag $flag, User $actor, bool $triggeredByHighUsage, array $data = []): WorkOrderItem
    {
        $workOrder = WorkOrder::query()
            ->where('unit_id', $flag->unit_id)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if (! $workOrder) {
            $workOrder = WorkOrder::query()->create([
                'unit_id' => $flag->unit_id,
                'site_id' => $flag->unit->site_id,
                'trigger_type' => 'high_usage',
                'status' => 'open',
                'submitted_by' => $actor->id,
            ]);
        }

        return WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'unit_planning_id' => $flag->unit_planning_id,
            'planning_item_id' => $flag->planning_item_id,
            'status' => empty($data) ? 'on_hold' : 'postpone',
            'action' => empty($data) ? null : 'postpone',
            'reason' => empty($data) ? null : 'High Usage Window 2: jadwal baru diajukan.',
            'previous_due_km' => $flag->unitPlanning?->next_due_km,
            'previous_due_date' => $flag->unitPlanning?->next_due_date?->toDateString(),
            'submitted_by' => $actor->id,
            'new_due_km' => $data['new_due_km'] ?? null,
            'new_due_date' => $data['new_due_date'] ?? null,
            'available_date' => $data['available_date'] ?? null,
            'triggered_by_high_usage' => $triggeredByHighUsage,
        ]);
    }

    private function thresholdValue(string $key, int $default): int
    {
        return (int) (SystemThreshold::query()->where('key', $key)->value('value') ?? $default);
    }
}
