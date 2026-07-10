<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Support\AccessScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitSiteTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');
        $user = $this->user();

        if (! $unit instanceof Unit || $user === null) {
            return false;
        }

        $unit->loadMissing('site:id,region_id');

        return $user->hasRole(UserRole::Superadmin)
            || ($user->hasRole(UserRole::PlannerArea) && AccessScope::canAccessSite($user, $unit->site_id, $unit->site?->region_id));
    }

    public function rules(): array
    {
        /** @var Unit $unit */
        $unit = $this->route('unit');
        $user = $this->user();

        return [
            'to_site_id' => [
                'required',
                'integer',
                Rule::exists('sites', 'id'),
                Rule::notIn([$unit->site_id]),
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
