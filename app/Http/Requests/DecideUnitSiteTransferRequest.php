<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class DecideUnitSiteTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SpvHo) || $this->user()?->hasRole(UserRole::Superadmin) || false;
    }

    public function rules(): array
    {
        return [
            'decision_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
