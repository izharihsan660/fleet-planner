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
use App\Support\AccessScope;
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
            ->when($user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->whereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('site_id', $user->site_id)))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id !== null, fn (Builder $query) => $query->whereHas('unit.site', fn (Builder $siteQuery) => $siteQuery->where('region_id', $user->region_id)))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id === null, fn (Builder $query) => $query->whereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('site_id', $user->site_id)))
            ->when($filters['unit_id'] ?? null, fn (Builder $query, string $unitId) => $query->where('unit_id', $unitId))
            ->when($filters['inspection_date'] ?? null, fn (Builder $query, string $date) => $query->whereDate('inspection_date', $date))
            ->latest('inspection_date')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

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
            'units' => UnitResource::collection($this->visibleUnits($request, true)->loadCount('inspectionLogs')),
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

        if ($unit->status === 'breakdown') {
            return back()->withErrors(['unit_id' => 'Unit sedang Breakdown. Gunakan form inspeksi breakdown untuk mengembalikan unit ke aktif.'])->withInput();
        }

        if (InspectionLog::query()->where('unit_id', $unit->id)->whereDate('inspection_date', $request->date('inspection_date'))->exists()) {
            return back()->withErrors(['unit_id' => 'Unit ini sudah diinput hari ini. Batalkan dulu kalau mau mengulang.'])->withInput();
        }

        try {
            $log = $inspectionService->record(
                $unit,
                $request->integer('odometer'),
                $request->user(),
                Carbon::parse($request->date('inspection_date')),
            );
        } catch (InvalidArgumentException) {
            return back()->withErrors(['odometer' => 'KM harus lebih besar dari '.$unit->current_odo.'. Coba cek lagi ya.'])->withInput();
        }

        $message = $log->insufficient_data
            ? 'KM harian berhasil disimpan. Data inspeksi masih kurang untuk menghitung rata-rata pemakaian.'
            : 'KM harian berhasil disimpan dan rata-rata pemakaian diperbarui.';

        if ($request->user()->hasRole(UserRole::Mekanik)) {
            return redirect()->route('inspections.create')->with('status', 'Berhasil disimpan');
        }

        return redirect()->route('inspections.index')->with('status', $message);
    }

    public function cancelToday(Request $request, InspectionLog $inspectionLog, InspectionService $inspectionService): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->hasRole(UserRole::Mekanik), 403);
        abort_unless($inspectionLog->mechanic_id === $user->id, 403);
        abort_unless($inspectionLog->inspection_date?->isSameDay(now()), 403);

        $inspectionService->cancelToday($inspectionLog);

        return redirect()->route('inspections.index')->with('status', 'Input hari ini berhasil dibatalkan. Unit bisa diinput ulang.');
    }

    private function visibleUnits(Request $request, bool $hideTodayInput = false)
    {
        $user = $request->user();

        return Unit::query()
            ->with('site:id,name,region')
            ->tap(fn (Builder $query) => AccessScope::applySiteScope($query, $user))
            ->when($hideTodayInput && $user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->whereDoesntHave('inspectionLogs', fn (Builder $logQuery) => $logQuery->whereDate('inspection_date', now()->toDateString())))
            ->orderBy('current_plate')
            ->get();
    }

    private function userCanAccessUnit(Request $request, Unit $unit): bool
    {
        $user = $request->user();

        $unit->loadMissing('site:id,region_id');

        if (AccessScope::canAccessAllSites($user)) {
            return true;
        }

        return AccessScope::canAccessSite($user, $unit->site_id, $unit->site?->region_id);
    }
}
