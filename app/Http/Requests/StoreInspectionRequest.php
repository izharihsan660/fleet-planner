<?php

namespace App\Http\Requests;

use App\Models\InspectionLog;
use Illuminate\Foundation\Http\FormRequest;

class StoreInspectionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'inspection_date' => today()->toDateString(),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('store', InspectionLog::class) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'exists:units,id'],
            'odometer' => ['required', 'integer', 'min:0'],
            'inspection_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
