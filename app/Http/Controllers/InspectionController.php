<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreInspectionRequest;
use App\Http\Resources\InspectionLogResource;
use App\Http\Resources\UnitResource;
use App\Models\InspectionLog;
use App\Models\SystemThreshold;
use App\Models\Unit;
use App\Services\InspectionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class InspectionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', InspectionLog::class);

        $filters = $request->only(['unit_id', 'inspection_date']);
        $user = $request->user();

        $logs = InspectionLog::query()
            ->with(['unit.site', 'mechanic:id,name'])
            ->when(
                $user->isOneOf([UserRole::AdminSite, UserRole::Mekanik]),
                fn (Builder $query) => $query->whereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('site_id', $user->site_id)),
            )
            ->when($filters['unit_id'] ?? null, fn (Builder $query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($filters['inspection_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('inspection_date', $date))
            ->latest('inspection_date')
            ->latest('id')
            ->get();

        return Inertia::render('Inspections/Index', [
            'inspectionLogs' => InspectionLogResource::collection($logs),
            'units' => UnitResource::collection($this->visibleUnits($request)),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', InspectionLog::class);

        $minimumInspectionData = (int) (SystemThreshold::query()
            ->where('key', 'min_inspection_data')
            ->value('value') ?? 3);

        return Inertia::render('Inspections/Create', [
            'units' => UnitResource::collection($this->visibleUnits($request)->loadCount('inspectionLogs')),
            'today' => now()->toDateString(),
            'minimumInspectionData' => $minimumInspectionData,
        ]);
    }

    public function store(StoreInspectionRequest $request, InspectionService $inspectionService): RedirectResponse
    {
        $unit = Unit::query()->findOrFail($request->integer('unit_id'));

        if (! $this->userCanAccessUnit($request, $unit)) {
            abort(403);
        }

        try {
            $log = $inspectionService->record(
                $unit,
                $request->integer('odometer'),
                $request->user(),
                Carbon::parse($request->date('inspection_date')),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['odometer' => $exception->getMessage()])->withInput();
        }

        $message = $log->insufficient_data
            ? 'KM harian berhasil disimpan. Data inspeksi masih kurang untuk menghitung rata-rata pemakaian.'
            : 'KM harian berhasil disimpan dan rata-rata pemakaian diperbarui.';

        return redirect()->route('inspections.index')->with('status', $message);
    }

    private function visibleUnits(Request $request)
    {
        $user = $request->user();

        return Unit::query()
            ->with('site:id,name,region')
            ->when(
                $user->isOneOf([UserRole::AdminSite, UserRole::Mekanik]),
                fn (Builder $query) => $query->where('site_id', $user->site_id),
            )
            ->orderBy('current_plate')
            ->get();
    }

    private function userCanAccessUnit(Request $request, Unit $unit): bool
    {
        $user = $request->user();

        if ($user->hasRole(UserRole::Superadmin)) {
            return true;
        }

        if ($user->hasRole(UserRole::Mekanik)) {
            return $unit->site_id === $user->site_id;
        }

        return false;
    }
}
