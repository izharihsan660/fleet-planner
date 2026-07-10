<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('role') === UserRole::PlannerArea->value) {
            $this->merge(['site_id' => null]);

            return;
        }

        if ($this->input('role') === UserRole::Mekanik->value) {
            $this->merge(['region_id' => null]);

            return;
        }

        $this->merge(['site_id' => null, 'region_id' => null]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))], 'password' => ['nullable', 'string', 'min:8'], 'role' => ['required', Rule::enum(UserRole::class)], 'site_id' => ['nullable', 'exists:sites,id'], 'region_id' => ['nullable', 'exists:regions,id']];
    }
}
