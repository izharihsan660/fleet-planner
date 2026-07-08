<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\User;
use App\Models\WorkOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BlockedBreakdownService
{
    public function markBlocked(WorkOrderItem $item, User $actor, string $reason): WorkOrderItem
    {
        $item->update([
            'status' => 'blocked',
            'action' => 'blocked',
            'reason' => $reason,
            'submitted_by' => $actor->id,
        ]);

        app(FleetNotificationService::class)->taskSubmitted($item->refresh(), 'blocked');

        return $item->refresh();
    }

    public function markBreakdown(Unit $unit, User $actor, string $reason): void
    {
        DB::transaction(function () use ($unit, $actor, $reason): void {
            $freezeStart = now();

            $unit->update(['status' => 'breakdown']);

            WorkOrderItem::query()
                ->whereIn('status', ['on_hold', 'in_progress', 'overdue'])
                ->whereHas('workOrder', fn ($query) => $query->where('unit_id', $unit->id))
                ->update([
                    'status' => 'breakdown',
                    'action' => 'breakdown',
                    'reason' => $reason,
                    'freeze_start' => $freezeStart,
                    'submitted_by' => $actor->id,
                    'updated_at' => $freezeStart,
                ]);

            $unit->unitPlannings()->update([
                'freeze_start' => $freezeStart,
                'updated_at' => $freezeStart,
            ]);

            app(FleetNotificationService::class)->unitBreakdown($unit->refresh());
        });
    }

    public function unfreezeBreakdown(Unit $unit): void
    {
        DB::transaction(function () use ($unit): void {
            $now = now();
            $today = CarbonImmutable::today();

            $unit->unitPlannings()
                ->whereNotNull('freeze_start')
                ->get()
                ->each(function ($unitPlanning) use ($today, $now): void {
                    $freezeStart = CarbonImmutable::parse($unitPlanning->freeze_start)->startOfDay();
                    $freezeDays = max(0, $freezeStart->diffInDays($today));

                    $unitPlanning->update([
                        'next_due_date' => $unitPlanning->next_due_date?->copy()->addDays($freezeDays)->toDateString(),
                        'freeze_start' => null,
                        'updated_at' => $now,
                    ]);
                });

            WorkOrderItem::query()
                ->where('status', 'breakdown')
                ->whereHas('workOrder', fn ($query) => $query->where('unit_id', $unit->id))
                ->update([
                    'status' => 'on_hold',
                    'freeze_end' => $now,
                    'updated_at' => $now,
                ]);

            $unit->update(['status' => 'active']);
        });
    }
}
