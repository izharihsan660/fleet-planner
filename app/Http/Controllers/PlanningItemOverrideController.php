<?php

namespace App\Http\Controllers;

use App\Enums\VehicleCategory;
use App\Http\Requests\StorePlanningItemOverrideRequest;
use App\Http\Requests\UpdatePlanningItemOverrideRequest;
use App\Models\PlanningItem;
use App\Models\PlanningItemOverride;
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

    public function store(StorePlanningItemOverrideRequest $request): RedirectResponse
    {
        PlanningItemOverride::query()->updateOrCreate(
            [
                'planning_item_id' => $request->integer('planning_item_id'),
                'vehicle_category' => $request->string('vehicle_category')->toString(),
            ],
            $request->validated(),
        );

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

    public function update(UpdatePlanningItemOverrideRequest $request, PlanningItemOverride $planningItemOverride): RedirectResponse
    {
        $planningItemOverride->update($request->validated());

        return redirect()->route('planning-item-overrides.index');
    }

    public function destroy(PlanningItemOverride $planningItemOverride): RedirectResponse
    {
        Gate::authorize('delete', $planningItemOverride);
        $planningItemOverride->delete();

        return redirect()->route('planning-item-overrides.index');
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
