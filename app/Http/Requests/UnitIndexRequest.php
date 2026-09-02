<?php

namespace App\Http\Requests;

use App\Enums\VehicleCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'breakdown'])],
            'vehicle_category' => ['nullable', 'string', Rule::in(array_column(VehicleCategory::options(), 'value'))],
        ];
    }
}
