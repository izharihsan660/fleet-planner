<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkOrderIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(['open', 'in_progress', 'complete'])],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'planning_item_ids' => ['sometimes', 'array', 'max:20'],
            'planning_item_ids.*' => ['integer', 'distinct', Rule::exists('planning_items', 'id')],
            'include_incomplete_baseline' => ['sometimes', 'boolean'],
            'sort_by' => ['sometimes', 'string', Rule::in(['priority', 'due_date', 'due_km'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('planning_item_ids') && $this->filled('item_id')) {
            $this->merge([
                'planning_item_ids' => [$this->input('item_id')],
            ]);
        }
    }
}
