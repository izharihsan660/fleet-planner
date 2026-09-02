<?php

namespace App\Http\Controllers;

use App\Enums\VehicleCategory;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UnitIndexRequest;
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
    public function index(UnitIndexRequest $request): Response
    {
        Gate::authorize('viewAny', Unit::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        return Inertia::render('Units/Index', [
            'units' => UnitResource::collection(Unit::query()
                ->with(['site', 'plateHistories' => fn ($query) => $query->latest('active_from')])
                ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $request->user()))
                ->when($search !== '', fn (Builder $query) => $this->applySearchScope($query, $search))
                ->when($filters['site_id'] ?? null, fn (Builder $query, string $siteId) => $query->where('site_id', $siteId))
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->when($filters['vehicle_category'] ?? null, fn (Builder $query, string $category) => $query->where('vehicle_category', $category))
                ->orderBy('current_plate')
                ->paginate(25)
                ->withQueryString()),
            'totalUnits' => Unit::query()
                ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $request->user()))
                ->count(),
            'sites' => $this->visibleSites($request),
            'vehicleCategories' => VehicleCategory::options(),
            'filters' => [
                'search' => $search,
                'site_id' => isset($filters['site_id']) ? (string) $filters['site_id'] : '',
                'status' => $filters['status'] ?? '',
                'vehicle_category' => $filters['vehicle_category'] ?? '',
            ],
        ]);
    }

    /**
     * Plat dicocokkan tanpa peduli spasi maupun besar-kecil huruf, supaya
     * "kt8404", "KT 8404", dan "8404" sama-sama ketemu.
     *
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    private function applySearchScope(Builder $query, string $search): Builder
    {
        $needle = '%'.mb_strtolower($search).'%';
        $compactNeedle = '%'.str_replace(' ', '', mb_strtolower($search)).'%';

        return $query->where(fn (Builder $searchQuery): Builder => $searchQuery
            ->whereRaw('lower(current_plate) like ?', [$needle])
            ->orWhereRaw("replace(lower(current_plate), ' ', '') like ?", [$compactNeedle])
            ->orWhereRaw('lower(customer) like ?', [$needle])
            ->orWhereRaw('lower(brand) like ?', [$needle])
            ->orWhereRaw('lower(type) like ?', [$needle]));
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Unit::class);

        return Inertia::render('Units/Create', ['sites' => $this->visibleSites($request), 'vehicleCategories' => VehicleCategory::options()]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $currentOdometer = (int) ($data['current_odo'] ?? 0);

        $data['current_odo'] = $currentOdometer;
        $data['has_odometer_reading'] = $currentOdometer > 0;

        Unit::create($data);

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
