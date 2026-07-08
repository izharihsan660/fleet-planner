<?php

namespace App\Http\Requests;

use App\Enums\VehicleCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('unit')) ?? false;
    }

    public function rules(): array
    {
        $unit = $this->route('unit');

        return [
            'site_id' => ['required', 'exists:sites,id'],
            'customer' => ['required', 'string', 'max:255'],
            'current_plate' => ['required', 'string', 'max:255', Rule::unique('units', 'current_plate')->ignore($unit)],
            'type' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'vehicle_category' => ['required', Rule::in(array_column(VehicleCategory::cases(), 'value'))],
            'year' => ['required', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'current_odo' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'max:255'],
        ];
    }
}
