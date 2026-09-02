<?php

namespace App\Http\Controllers;

use App\Enums\VehicleCategory;
use App\Http\Requests\StorePlanningItemOverrideRequest;
use App\Http\Requests\UpdatePlanningItemOverrideRequest;
use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
use App\Services\RecalculateDueDatesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlanningItemOverrideController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', PlanningItemOverride::class);

        return Inertia::render('PlanningItemOverrides/Index', [
            'overrides' => PlanningItemOverride::query()
                ->with('planningItem:id,name')
                ->latest()
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PlanningItemOverride::class);

        return Inertia::render('PlanningItemOverrides/Create', $this->formProps());
    }

    public function store(StorePlanningItemOverrideRequest $request, RecalculateDueDatesService $recalculateDueDates): RedirectResponse
    {
        $override = PlanningItemOverride::query()->updateOrCreate(
            [
                'planning_item_id' => $request->integer('planning_item_id'),
                'vehicle_category' => $request->string('vehicle_category')->toString(),
            ],
            $request->validated(),
        );

        $this->recalculate($override, $recalculateDueDates);

        return redirect()->route('planning-item-overrides.index');
    }

    public function edit(PlanningItemOverride $planningItemOverride): Response
    {
        Gate::authorize('update', $planningItemOverride);

        return Inertia::render('PlanningItemOverrides/Edit', [
            ...$this->formProps(),
            'override' => $planningItemOverride,
        ]);
    }

    public function update(UpdatePlanningItemOverrideRequest $request, PlanningItemOverride $planningItemOverride, RecalculateDueDatesService $recalculateDueDates): RedirectResponse
    {
        $planningItemOverride->update($request->validated());

        $this->recalculate($planningItemOverride, $recalculateDueDates);

        return redirect()->route('planning-item-overrides.index');
    }

    public function destroy(PlanningItemOverride $planningItemOverride, RecalculateDueDatesService $recalculateDueDates): RedirectResponse
    {
        Gate::authorize('delete', $planningItemOverride);
        $planningItemOverride->delete();

        // Menghapus override mengembalikan kategori ini ke interval dasar, jadi
        // due-nya perlu dihitung ulang persis seperti saat override diubah.
        $this->recalculate($planningItemOverride, $recalculateDueDates);

        return redirect()->route('planning-item-overrides.index');
    }

    private function recalculate(PlanningItemOverride $override, RecalculateDueDatesService $recalculateDueDates): void
    {
        $planningItem = PlanningItem::query()->find($override->planning_item_id);

        if ($planningItem === null) {
            return;
        }

        $recalculateDueDates->recalculateForVehicleCategory($planningItem, $override->vehicle_category);
    }

    /**
     * @return array{planningItems: mixed, vehicleCategories: array<int, array{value: string, label: string}>}
     */
    private function formProps(): array
    {
        return [
            'planningItems' => PlanningItem::query()->orderBy('name')->get(['id', 'name']),
            'vehicleCategories' => VehicleCategory::options(),
        ];
    }
}
