<?php

namespace App\Http\Requests;

use App\Enums\VehicleCategory;
use App\Models\PlanningItemOverride;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanningItemOverrideRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', PlanningItemOverride::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'planning_item_id' => ['required', 'exists:planning_items,id'],
            'vehicle_category' => ['required', Rule::in(array_column(VehicleCategory::cases(), 'value'))],
            'interval_km' => ['nullable', 'integer', 'min:0'],
            'interval_days' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
