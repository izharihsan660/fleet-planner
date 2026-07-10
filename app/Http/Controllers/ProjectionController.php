<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ProjectionIndexRequest;
use App\Http\Resources\ProjectionResultResource;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Services\ProjectionService;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectionController extends Controller
{
    public function index(ProjectionIndexRequest $request, ProjectionService $service): Response
    {
        Gate::authorize('view-projections');

        $user = $request->user();
        $months = (int) ($request->validated('months') ?? 1);
        $requestedSiteId = $request->validated('site_id');
        $canFilterSite = $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo, UserRole::PlannerArea]);
        $siteId = $user->hasRole(UserRole::Mekanik) || ($user->hasRole(UserRole::PlannerArea) && $user->region_id === null) ? $user->site_id : ($canFilterSite ? $requestedSiteId : null);
        $result = $service->calculate($months, $siteId !== null ? (int) $siteId : null, $user->hasRole(UserRole::PlannerArea) ? $user->region_id : null);

        return Inertia::render('Projections/Index', [
            'projection' => ProjectionResultResource::make($result)->resolve(),
            'sites' => SiteResource::collection(Site::query()
                ->tap(fn (Builder $query) => AccessScope::applySiteListScope($query, $user))
                ->orderBy('name')
                ->get()),
            'filters' => [
                'months' => $months,
                'site_id' => $siteId,
            ],
            'permissions' => [
                'can_filter_site' => $canFilterSite,
                'can_view_unit' => true,
                'can_view_item' => true,
                'can_view_part' => $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]),
                'default_tab' => 'unit',
            ],
        ]);
    }
}
