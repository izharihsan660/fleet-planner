<?php

namespace App\Http\Requests;

use App\Services\ProjectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'months' => ['nullable', 'integer', Rule::in(ProjectionService::SELECTABLE_PERIOD_MONTHS)],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'month' => ['nullable', 'date_format:Y-m'],
            'region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')],
        ];
    }
}
