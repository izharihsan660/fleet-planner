<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSystemThresholdRequest;
use App\Http\Requests\UpdateSystemThresholdRequest;
use App\Models\SystemThreshold;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SystemThresholdController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', SystemThreshold::class);

        return Inertia::render('SystemThresholds/Index', ['systemThresholds' => SystemThreshold::query()->with('updatedBy:id,name')->orderBy('key')->get()]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SystemThreshold::class);

        return Inertia::render('SystemThresholds/Create');
    }

    public function store(StoreSystemThresholdRequest $request): RedirectResponse
    {
        SystemThreshold::create($request->safe()->merge(['updated_by' => $request->user()?->id])->all());

        return redirect()->route('system-thresholds.index');
    }

    public function edit(SystemThreshold $systemThreshold): Response
    {
        Gate::authorize('update', $systemThreshold);

        return Inertia::render('SystemThresholds/Edit', ['systemThreshold' => $systemThreshold]);
    }

    public function update(UpdateSystemThresholdRequest $request, SystemThreshold $systemThreshold): RedirectResponse
    {
        $systemThreshold->update($request->safe()->merge(['updated_by' => $request->user()?->id])->all());

        return redirect()->route('system-thresholds.index');
    }

    public function destroy(SystemThreshold $systemThreshold): RedirectResponse
    {
        Gate::authorize('delete', $systemThreshold);
        $systemThreshold->delete();

        return redirect()->route('system-thresholds.index');
    }
}
