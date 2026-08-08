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

        $service->set(
            $unitPlanning,
            $request->integer('last_done_km'),
            CarbonImmutable::parse($request->validated('last_done_date')),
        );

        return back()->with('status', 'Baseline item berhasil disimpan. Perhitungan due sudah diaktifkan.');
    }
}
