<?php

namespace App\Http\Controllers;

use App\Enums\VehicleCategory;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Site;
use App\Models\Unit;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Unit::class);

        return Inertia::render('Units/Index', [
            'units' => UnitResource::collection(Unit::query()
                ->with(['site', 'plateHistories' => fn ($query) => $query->latest('active_from')])
                ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $request->user()))
                ->latest()
                ->paginate(25)
                ->withQueryString()),
            'totalUnits' => Unit::query()
                ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $request->user()))
                ->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Unit::class);

        return Inertia::render('Units/Create', ['sites' => $this->visibleSites($request), 'vehicleCategories' => VehicleCategory::options()]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        Unit::create($request->validated());

        return redirect()->route('units.index');
    }

    public function edit(Request $request, Unit $unit): Response
    {
        Gate::authorize('update', $unit);

        return Inertia::render('Units/Edit', ['unit' => $unit->load('plateHistories'), 'sites' => $this->visibleSites($request), 'vehicleCategories' => VehicleCategory::options()]);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return redirect()->route('units.index');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);
        $unit->delete();

        return redirect()->route('units.index');
    }

    private function visibleSites(Request $request)
    {
        return Site::query()
            ->tap(fn (Builder $query) => AccessScope::applySiteListScope($query, $request->user()))
            ->orderBy('name')
            ->get(['id', 'name', 'region']);
    }
}
