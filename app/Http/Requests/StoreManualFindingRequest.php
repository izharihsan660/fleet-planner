<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Support\AccessScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreManualFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $unit = $this->route('unit');

        if (! $user || ! $unit instanceof Unit || ! $user->isOneOf([UserRole::PlannerArea, UserRole::Mekanik])) {
            return false;
        }

        $unit->loadMissing('site:id,region_id');

        return AccessScope::canAccessSite($user, $unit->site_id, $unit->site?->region_id);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'planning_item_ids' => ['required', 'array', 'min:1'],
            'planning_item_ids.*' => ['integer', 'distinct', 'exists:planning_items,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
