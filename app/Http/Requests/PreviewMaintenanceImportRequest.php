<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewMaintenanceImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isOneOf([UserRole::Superadmin, UserRole::SpvHo]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['units', 'unit_plannings'])],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
