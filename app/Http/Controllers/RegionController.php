<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegionRequest;
use App\Http\Requests\UpdateRegionRequest;
use App\Models\Region;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RegionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Site::class);

        return Inertia::render('Regions/Index', [
            'regions' => Region::query()->withCount('sites')->latest()->paginate(25)->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Site::class);

        return Inertia::render('Regions/Create');
    }

    public function store(StoreRegionRequest $request): RedirectResponse
    {
        Region::create($request->validated());

        return redirect()->route('regions.index');
    }

    public function edit(Region $region): Response
    {
        Gate::authorize('update', Site::class);

        return Inertia::render('Regions/Edit', ['region' => $region]);
    }

    public function update(UpdateRegionRequest $request, Region $region): RedirectResponse
    {
        $region->update($request->validated());

        return redirect()->route('regions.index');
    }

    public function destroy(Region $region): RedirectResponse
    {
        Gate::authorize('delete', Site::class);
        $region->delete();

        return redirect()->route('regions.index');
    }
}
