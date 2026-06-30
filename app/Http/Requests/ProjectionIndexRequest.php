<?php

namespace App\Http\Requests;

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
            'months' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
        ];
    }
}
