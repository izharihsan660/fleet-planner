<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Site;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Unit::class);

        return Inertia::render('Units/Index', [
            'units' => Unit::query()->with(['site', 'plateHistories' => fn ($query) => $query->latest('active_from')])->latest()->get(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Unit::class);

        return Inertia::render('Units/Create', ['sites' => Site::query()->orderBy('name')->get(['id', 'name', 'region'])]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        Unit::create($request->validated());

        return redirect()->route('units.index');
    }

    public function edit(Unit $unit): Response
    {
        $this->authorize('update', $unit);

        return Inertia::render('Units/Edit', ['unit' => $unit->load('plateHistories'), 'sites' => Site::query()->orderBy('name')->get(['id', 'name', 'region'])]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return redirect()->route('units.index');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $this->authorize('delete', $unit);
        $unit->delete();

        return redirect()->route('units.index');
    }
}
