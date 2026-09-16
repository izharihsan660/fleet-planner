<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('wo')) ?? false;
    }

    /**
     * Approval berlaku per item. `item_ids` boleh dikosongkan hanya bila work
     * order memang cuma punya satu item yang diajukan — controller yang
     * menolak pengosongan pada work order berisi banyak pengajuan, supaya
     * satu klik tidak pernah lagi menyeret item yang tidak dimaksud.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_ids' => ['sometimes', 'array', 'min:1'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
