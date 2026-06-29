<?php

namespace App\Http\Requests;

use App\Models\SystemThreshold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSystemThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SystemThreshold::class) ?? false;
    }

    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:255', Rule::unique('system_thresholds', 'key')], 'value' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:255']];
    }
}
