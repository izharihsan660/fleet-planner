<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateUnitPlanningBaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $unit = $this->route('unit');
        $unitPlanning = $this->route('unitPlanning');

        return $user !== null
            && $unit instanceof Unit
            && $unitPlanning instanceof UnitPlanning
            && $unitPlanning->unit_id === $unit->id
            && $user->isOneOf([UserRole::Mekanik, UserRole::PlannerArea, UserRole::SpvHo, UserRole::Superadmin])
            && Gate::forUser($user)->allows('view', $unit);
    }

    public function rules(): array
    {
        return [
            'last_done_km' => ['required', 'integer', 'min:0'],
            'last_done_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
