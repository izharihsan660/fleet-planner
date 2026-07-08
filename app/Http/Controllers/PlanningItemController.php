<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlanningItemRequest;
use App\Http\Requests\UpdatePlanningItemRequest;
use App\Models\PlanningItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlanningItemController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', PlanningItem::class);

        return Inertia::render('PlanningItems/Index', ['planningItems' => PlanningItem::query()->orderBy('name')->paginate(25)->withQueryString()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PlanningItem::class);

        return Inertia::render('PlanningItems/Create');
    }

    public function store(StorePlanningItemRequest $request): RedirectResponse
    {
        PlanningItem::create($request->validated());

        return redirect()->route('planning-items.index');
    }

    public function edit(PlanningItem $planningItem): Response
    {
        Gate::authorize('update', $planningItem);

        return Inertia::render('PlanningItems/Edit', ['planningItem' => $planningItem]);
    }

    public function update(UpdatePlanningItemRequest $request, PlanningItem $planningItem): RedirectResponse
    {
        $planningItem->update($request->validated());

        return redirect()->route('planning-items.index');
    }

    public function destroy(PlanningItem $planningItem): RedirectResponse
    {
        Gate::authorize('delete', $planningItem);
        $planningItem->delete();

        return redirect()->route('planning-items.index');
    }
}
