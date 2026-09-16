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

        $lastDoneDate = $request->validated('last_done_date');
        $targetDueDate = $request->validated('next_due_date');
        $isEstimated = $request->boolean('is_estimated');

        $service->set(
            $unitPlanning,
            $request->integer('last_done_km'),
            filled($lastDoneDate) ? CarbonImmutable::parse($lastDoneDate) : null,
            filled($targetDueDate) ? CarbonImmutable::parse($targetDueDate) : null,
            $isEstimated,
        );

        return back()->with('status', $isEstimated
            ? 'Perkiraan tersimpan dan ditandai sebagai estimasi. Ganti dengan data asli begitu tersedia.'
            : 'Baseline item berhasil disimpan. Perhitungan due sudah diaktifkan.');
    }
}
