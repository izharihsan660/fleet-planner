<?php

namespace App\Http\Requests;

use App\Models\WorkOrderItem;
use App\Services\CompletionBackdatePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompleteWorkOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('complete', $this->route('wo')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $item = $this->route('item');
        $minimumCompletedOdometer = $item instanceof WorkOrderItem && $item->isApprovedBaselineReplace() ? 1 : 0;

        return [
            'completed_odo' => ['required', 'integer', 'min:'.$minimumCompletedOdometer],
            'completed_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['completed_date'])) {
                return;
            }

            $this->validateBackdate($validator, app(CompletionBackdatePolicy::class));
        }];
    }

    private function validateBackdate(Validator $validator, CompletionBackdatePolicy $policy): void
    {
        $completedDate = CarbonImmutable::parse($this->validated('completed_date'))->startOfDay();
        $daysBackdated = $policy->daysBackdated($completedDate);

        if ($policy->exceedsMaxDays($daysBackdated)) {
            $validator->errors()->add('completed_date', sprintf(
                'Tanggal selesai mundur %d hari, melewati batas %d hari. Minta Superadmin mencatatnya lewat Koreksi Tanggal Selesai.',
                $daysBackdated,
                $policy->maxDays(),
            ));

            return;
        }

        $this->validateNotBeforeLastDone($validator, $completedDate);
    }

    private function validateNotBeforeLastDone(Validator $validator, CarbonImmutable $completedDate): void
    {
        $item = $this->route('item');

        if (! $item instanceof WorkOrderItem) {
            return;
        }

        $lastDoneDate = $item->unitPlanning?->last_done_date;

        if ($lastDoneDate === null || $completedDate->greaterThanOrEqualTo(CarbonImmutable::parse($lastDoneDate)->startOfDay())) {
            return;
        }

        $validator->errors()->add('completed_date', sprintf(
            'Tanggal selesai tidak boleh lebih awal dari penyelesaian sebelumnya (%s).',
            CarbonImmutable::parse($lastDoneDate)->toDateString(),
        ));
    }
}
