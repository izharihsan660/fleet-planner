<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AccessScope
{
    public static function canAccessAllSites(User $user): bool
    {
        return $user->isOneOf([UserRole::Superadmin, UserRole::SpvHo]);
    }

    public static function canAccessSite(User $user, ?int $siteId, ?int $regionId = null): bool
    {
        if (self::canAccessAllSites($user)) {
            return true;
        }

        if ($user->hasRole(UserRole::Mekanik)) {
            return $siteId !== null && $user->site_id === $siteId;
        }

        if ($user->hasRole(UserRole::PlannerArea)) {
            if ($user->region_id === null) {
                return $siteId !== null && $user->site_id === $siteId;
            }

            return $regionId !== null && $user->region_id === $regionId;
        }

        return true;
    }

    public static function applySiteScope(Builder $query, User $user, string $siteColumn = 'site_id'): Builder
    {
        if (self::canAccessAllSites($user)) {
            return $query;
        }

        if ($user->hasRole(UserRole::Mekanik)) {
            return $query->where($siteColumn, $user->site_id);
        }

        if ($user->hasRole(UserRole::PlannerArea)) {
            if ($user->region_id === null) {
                return $query->where($siteColumn, $user->site_id);
            }

            return $query->whereHas('site', fn (Builder $siteQuery) => $siteQuery->where('region_id', $user->region_id));
        }

        return $query;
    }

    public static function applySiteListScope(Builder $query, User $user): Builder
    {
        if (self::canAccessAllSites($user)) {
            return $query;
        }

        if ($user->hasRole(UserRole::Mekanik)) {
            return $query->whereKey($user->site_id);
        }

        if ($user->hasRole(UserRole::PlannerArea)) {
            if ($user->region_id === null) {
                return $query->whereKey($user->site_id);
            }

            return $query->where('region_id', $user->region_id);
        }

        return $query;
    }
}
