<?php

namespace App\Observers;

use App\Models\Unit;
use App\Services\UnitPlanningGenerator;
use Illuminate\Support\Carbon;

class UnitObserver
{
    public function created(Unit $unit): void
    {
        $unit->plateHistories()->create([
            'plate_number' => $unit->current_plate,
            'active_from' => Carbon::today(),
        ]);

        app(UnitPlanningGenerator::class)->generateForUnit($unit, Carbon::today());
    }

    public function updated(Unit $unit): void
    {
        if (! $unit->wasChanged('current_plate')) {
            return;
        }

        $today = Carbon::today();
        $oldPlate = $unit->getOriginal('current_plate');

        $unit->plateHistories()
            ->where('plate_number', $oldPlate)
            ->whereNull('active_until')
            ->latest('active_from')
            ->first()
            ?->update(['active_until' => $today]);

        $unit->plateHistories()->create([
            'plate_number' => $unit->current_plate,
            'active_from' => $today,
        ]);
    }
}