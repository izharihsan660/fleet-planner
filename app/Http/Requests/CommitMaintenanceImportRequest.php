<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CommitMaintenanceImportRequest extends FormRequest
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
            'path' => [
                'required',
                'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_string($value) || ! $this->isSafeImportPath($value)) {
                        $fail('File import tidak valid. Upload ulang file import.');
                    }
                },
            ],
            'original_filename' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function isSafeImportPath(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);

        if (! str_starts_with($normalizedPath, 'imports/') || str_contains($normalizedPath, '../') || str_contains($normalizedPath, '/..')) {
            return false;
        }

        return Storage::disk('local')->exists($normalizedPath);
    }
}
