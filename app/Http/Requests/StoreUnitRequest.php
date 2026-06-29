<?php

namespace App\Http\Requests;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Unit::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required', 'exists:sites,id'],
            'customer' => ['required', 'string', 'max:255'],
            'current_plate' => ['required', 'string', 'max:255', Rule::unique('units', 'current_plate')],
            'type' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'current_odo' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'max:255'],
        ];
    }
}
