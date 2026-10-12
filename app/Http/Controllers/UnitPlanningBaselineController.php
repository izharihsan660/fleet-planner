<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUnitPlanningBaselineRequest;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Services\UnitPlanningBaselineService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class UnitPlanningBaselineController extends Controller
{
    public function __invoke(
        UpdateUnitPlanningBaselineRequest $request,
        Unit $unit,
        UnitPlanning $unitPlanning,
        UnitPlanningBaselineService $service,
    ): RedirectResponse {
        abort_unless($unitPlanning->unit_id === $unit->id, 404);

        $lastDoneKm = $request->validated('last_done_km');
        $lastDoneDate = $request->validated('last_done_date');
        $nextDueDate = $request->validated('next_due_date');

        $service->set(
            $unitPlanning,
            filled($lastDoneKm) ? (int) $lastDoneKm : null,
            filled($lastDoneDate) ? CarbonImmutable::parse($lastDoneDate) : null,
            filled($nextDueDate) ? CarbonImmutable::parse($nextDueDate) : null,
        );

        return back()->with('status', 'Baseline item berhasil disimpan. Perhitungan due sudah diaktifkan.');
    }
}
