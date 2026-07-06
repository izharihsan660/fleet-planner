<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignWorkOrderMechanicRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('assignMechanic', $this->route('wo')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workOrder = $this->route('wo');

        return [
            'assigned_mechanic_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Mekanik->value)->where('site_id', $workOrder->site_id),
            ],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
