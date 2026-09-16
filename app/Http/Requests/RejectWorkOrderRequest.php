<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RejectWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('wo')) ?? false;
    }

    /**
     * Alasan penolakan wajib, sama seperti Antrian Approval. Tanpa ini planner
     * hanya melihat itemnya ditolak tanpa tahu apa yang harus diperbaiki.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_ids' => ['sometimes', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
