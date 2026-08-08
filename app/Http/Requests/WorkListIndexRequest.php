<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkListIndexRequest extends FormRequest
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
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'search' => ['nullable', 'string', 'max:100'],
            'planning_item_ids' => ['sometimes', 'array', 'max:20'],
            'planning_item_ids.*' => ['integer', 'distinct', Rule::exists('planning_items', 'id')],
            'include_incomplete_baseline' => ['sometimes', 'boolean'],
            'sort_by' => ['sometimes', 'string', Rule::in(['priority', 'due_date', 'due_km'])],
        ];
    }
}
