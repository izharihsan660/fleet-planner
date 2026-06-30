<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\SubmitHighUsageScheduleRequest;
use App\Http\Requests\TakeHighUsageActionRequest;
use App\Http\Resources\HighUsageFlagResource;
use App\Models\HighUsageFlag;
use App\Services\HighUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HighUsageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', HighUsageFlag::class);

        $user = $request->user();

        $flags = HighUsageFlag::query()
            ->with(['unit.site', 'planningItem', 'unitPlanning', 'actionTakenBy'])
            ->whereNull('resolved_at')
            ->when(! $user->isOneOf([UserRole::Superadmin, UserRole::PlannerHo]), fn ($query) => $query->whereHas('unit', fn ($unitQuery) => $unitQuery->where('site_id', $user->site_id)))
            ->latest('flagged_at')
            ->get();

        return Inertia::render('HighUsage/Index', [
            'flags' => HighUsageFlagResource::collection($flags),
            'canTakeAction' => $user->isOneOf([UserRole::Superadmin, UserRole::AdminSite]),
        ]);
    }

    public function takeAction(TakeHighUsageActionRequest $request, HighUsageFlag $flag, HighUsageService $service): RedirectResponse
    {
        $service->takeAction($flag, $request->user(), $request->validated('action'));

        return back()->with('success', 'Tindak lanjut High Usage berhasil disimpan.');
    }

    public function submitSchedule(SubmitHighUsageScheduleRequest $request, HighUsageFlag $flag, HighUsageService $service): RedirectResponse
    {
        $service->takeAction($flag, $request->user(), 'scheduled', $request->validated());

        return back()->with('success', 'Jadwal baru High Usage berhasil diajukan.');
    }
}
