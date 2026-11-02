<?php

namespace App\Http\Requests;

use App\Enums\ManualProjectionReason;
use App\Models\ManualProjection;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualProjectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unitId = filter_var($this->input('unit_id'), FILTER_VALIDATE_INT);
        $unit = $unitId === false ? null : Unit::query()->with('site:id,region_id')->find($unitId);

        return $this->user()?->can('create', [ManualProjection::class, $unit]) ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'planning_item_id' => [
                'required', 'integer', 'exists:planning_items,id',
                Rule::unique('manual_projections', 'planning_item_id')
                    ->where('unit_id', $this->integer('unit_id'))
                    ->where('status', 'active'),
            ],
            'target_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['required', Rule::enum(ManualProjectionReason::class)],
            'notes' => ['nullable', 'required_if:reason,lainnya', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'planning_item_id.unique' => 'Unit dan item ini sudah memiliki kebutuhan manual aktif.',
            'target_date.after_or_equal' => 'Perkiraan tanggal ganti tidak boleh lampau.',
            'notes.required_if' => 'Catatan wajib diisi jika alasan Lainnya.',
        ];
    }
}
