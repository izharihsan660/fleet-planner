<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUnitPlanningExclusionRequest;
use App\Models\Unit;
use App\Models\UnitPlanning;
use App\Services\UnitPlanningExclusionService;
use Illuminate\Http\RedirectResponse;

class UnitPlanningExclusionController extends Controller
{
    public function __invoke(
        UpdateUnitPlanningExclusionRequest $request,
        Unit $unit,
        UnitPlanning $unitPlanning,
        UnitPlanningExclusionService $service,
    ): RedirectResponse {
        $service->update(
            $unitPlanning,
            $request->boolean('is_excluded'),
            $request->validated('excluded_reason'),
        );

        return back()->with('status', $request->boolean('is_excluded')
            ? 'Planning item ditandai Tidak Berlaku.'
            : 'Planning item diaktifkan kembali.');
    }
}
