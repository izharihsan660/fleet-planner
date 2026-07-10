<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitApprovalQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOneOf([UserRole::Superadmin, UserRole::SpvHo]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer', 'distinct', Rule::exists('work_order_items', 'id')],
            'reason' => ['nullable', 'required_if:decision,reject', 'string', 'max:1000'],
        ];
    }
}
