<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\SubmitHighUsageScheduleRequest;
use App\Http\Requests\TakeHighUsageActionRequest;
use App\Http\Resources\HighUsageFlagResource;
use App\Models\HighUsageFlag;
use App\Services\HighUsageService;
use App\Services\WorkOrderItemGuard;
use Illuminate\Database\Eloquent\Builder;
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
            ->whereHas('unitPlanning', fn (Builder $query) => $query->applicable()->withBaseline())
            ->whereNull('resolved_at')
            ->when($user->hasRole(UserRole::Mekanik), fn (Builder $query) => $query->whereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('site_id', $user->site_id)))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id !== null, fn (Builder $query) => $query->whereHas('unit.site', fn (Builder $siteQuery) => $siteQuery->where('region_id', $user->region_id)))
            ->when($user->hasRole(UserRole::PlannerArea) && $user->region_id === null, fn (Builder $query) => $query->whereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('site_id', $user->site_id)))
            ->latest('flagged_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('HighUsage/Index', [
            'flags' => HighUsageFlagResource::collection($flags),
            'canTakeAction' => $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]),
        ]);
    }

    public function takeAction(TakeHighUsageActionRequest $request, HighUsageFlag $flag, HighUsageService $service, WorkOrderItemGuard $guard): RedirectResponse
    {
        $service->takeAction($flag, $request->user(), $request->validated('action'));

        return $this->actionResponse($flag, $guard, 'Tindak lanjut High Usage berhasil disimpan.');
    }

    public function submitSchedule(SubmitHighUsageScheduleRequest $request, HighUsageFlag $flag, HighUsageService $service, WorkOrderItemGuard $guard): RedirectResponse
    {
        $service->takeAction($flag, $request->user(), 'scheduled', $request->validated());

        return $this->actionResponse($flag, $guard, 'Jadwal baru High Usage berhasil diajukan.');
    }

    private function actionResponse(HighUsageFlag $flag, WorkOrderItemGuard $guard, string $successMessage): RedirectResponse
    {
        if ($flag->refresh()->action_taken !== 'already_active') {
            return back()->with('success', $successMessage);
        }

        $flag->loadMissing('unit', 'planningItem', 'unitPlanning');
        $blockingItem = $guard->findBlockingItem($flag->unitPlanning);
        $message = sprintf(
            'Tidak ada task baru dibuat: %s pada %s sudah punya task aktif (status: %s). Flag High Usage ditandai selesai.',
            $flag->planningItem->name,
            $flag->unit->current_plate,
            $blockingItem?->status ?? 'tidak diketahui',
        );

        return back()->with('warning', $message);
    }
}
