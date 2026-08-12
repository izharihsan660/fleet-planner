<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\CompletionBackdatePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Koreksi Tanggal Selesai — jalur khusus Superadmin untuk pekerjaan yang
 * mundurnya melewati backdate_max_days. Batas maksimum tidak berlaku di sini,
 * tapi alasan rinci wajib dan hasilnya ditandai sebagai override.
 */
class BackdateWorkOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workOrder = $this->route('wo');
        $item = $this->route('item');

        return $user !== null
            && $workOrder instanceof WorkOrder
            && $item instanceof WorkOrderItem
            && $item->work_order_id === $workOrder->id
            && $user->hasRole(UserRole::Superadmin);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'completed_odo' => ['required', 'integer', 'min:0'],
            'completed_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['required', 'string', 'min:'.CompletionBackdatePolicy::EXTENDED_NOTE_MIN_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notes.required' => 'Alasan koreksi wajib diisi.',
            'notes.min' => 'Alasan koreksi harus rinci, minimal :min karakter.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('completed_date')) {
                return;
            }

            $item = $this->route('item');
            $lastDoneDate = $item instanceof WorkOrderItem ? $item->unitPlanning?->last_done_date : null;

            if ($lastDoneDate === null) {
                return;
            }

            $completedDate = CarbonImmutable::parse($this->validated('completed_date'))->startOfDay();

            if ($completedDate->greaterThanOrEqualTo(CarbonImmutable::parse($lastDoneDate)->startOfDay())) {
                return;
            }

            $validator->errors()->add('completed_date', sprintf(
                'Tanggal selesai tidak boleh lebih awal dari penyelesaian sebelumnya (%s).',
                CarbonImmutable::parse($lastDoneDate)->toDateString(),
            ));
        }];
    }
}
