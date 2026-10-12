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
            'last_done_km' => ['nullable', 'integer', 'min:0'],
            'last_done_date' => ['nullable', 'required_without:next_due_date', 'date', 'before_or_equal:today'],
            'next_due_date' => ['nullable', 'required_without:last_done_date', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'last_done_date.required_without' => 'Isi Tanggal Terakhir atau Due Date.',
            'next_due_date.required_without' => 'Isi Tanggal Terakhir atau Due Date.',
            'last_done_date.before_or_equal' => 'Tanggal Terakhir tidak boleh di masa depan.',
            'next_due_date.after_or_equal' => 'Due Date tidak boleh di masa lalu.',
        ];
    }
}
