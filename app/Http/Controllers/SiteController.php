<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Region;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Site::class);

        return Inertia::render('Sites/Index', ['sites' => Site::query()->with('area:id,name')->withCount(['units', 'users'])->latest()->paginate(25)->withQueryString()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Site::class);

        return Inertia::render('Sites/Create', $this->formOptions());
    }

    public function store(StoreSiteRequest $request): RedirectResponse
    {
        Site::create($request->validated());

        return redirect()->route('sites.index');
    }

    public function edit(Site $site): Response
    {
        Gate::authorize('update', $site);

        return Inertia::render('Sites/Edit', [...$this->formOptions(), 'site' => $site->load('area:id,name')]);
    }

    public function update(UpdateSiteRequest $request, Site $site): RedirectResponse
    {
        $site->update($request->validated());

        return redirect()->route('sites.index');
    }

    public function destroy(Site $site): RedirectResponse
    {
        Gate::authorize('delete', $site);
        $site->delete();

        return redirect()->route('sites.index');
    }

    private function formOptions(): array
    {
        return ['regions' => Region::query()->orderBy('name')->get(['id', 'name'])];
    }
}
