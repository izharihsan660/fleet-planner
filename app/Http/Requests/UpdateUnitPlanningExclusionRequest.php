<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitPlanningExclusionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $unit = $this->route('unit');
        $unitPlanning = $this->route('unitPlanning');

        return $user?->isOneOf([UserRole::Superadmin, UserRole::SpvHo]) === true
            && $unit instanceof Unit
            && $unitPlanning instanceof UnitPlanning
            && $unitPlanning->unit_id === $unit->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_excluded' => ['required', 'boolean'],
            'excluded_reason' => ['nullable', 'string', 'max:255', 'required_if:is_excluded,true'],
        ];
    }
}
