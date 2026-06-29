<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('system_threshold')) ?? false;
    }

    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:255', Rule::unique('system_thresholds', 'key')->ignore($this->route('system_threshold'))], 'value' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:255']];
    }
}
