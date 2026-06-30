<?php

namespace Database\Seeders;

use App\Models\PlanningItem;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Database\Seeder;

class UnitPlanningSeeder extends Seeder
{
    public function run(): void
    {
        $planningItems = PlanningItem::query()->get(['id', 'interval_km']);

        Unit::query()->get(['id'])->each(function (Unit $unit) use ($planningItems): void {
            $planningItems->each(function (PlanningItem $planningItem) use ($unit): void {
                UnitPlanning::query()->updateOrCreate(
                    [
                        'unit_id' => $unit->id,
                        'planning_item_id' => $planningItem->id,
                    ],
                    [
                        'last_done_km' => 0,
                        'last_done_date' => null,
                        'next_due_km' => $planningItem->interval_km,
                        'next_due_date' => null,
                    ],
                );
            });
        });
    }
}
