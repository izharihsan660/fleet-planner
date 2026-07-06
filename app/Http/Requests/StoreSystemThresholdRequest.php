<?php

namespace App\Http\Requests;

use App\Models\SystemThreshold;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSystemThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SystemThreshold::class) ?? false;
    }

    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:255', Rule::unique('system_thresholds', 'key')], 'value' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:255']];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validatePreviewThresholdOrder($validator);
        }];
    }

    private function validatePreviewThresholdOrder(Validator $validator): void
    {
        $values = SystemThreshold::query()->pluck('value', 'key')->map(fn (string $value): int => (int) $value)->all();
        $values[$this->string('key')->toString()] = $this->integer('value');

        $this->validateOrderedGroup($validator, $values, 'days', 'upcoming_days', 'ancang_ancang_days', 'warning_days');
        $this->validateOrderedGroup($validator, $values, 'km', 'upcoming_km', 'ancang_ancang_km', 'warning_km');
    }

    /**
     * @param  array<string, int>  $values
     */
    private function validateOrderedGroup(Validator $validator, array $values, string $label, string $upcomingKey, string $preparationKey, string $warningKey): void
    {
        if (! isset($values[$upcomingKey], $values[$preparationKey], $values[$warningKey])) {
            return;
        }

        if ($values[$upcomingKey] <= $values[$preparationKey] || $values[$preparationKey] <= $values[$warningKey]) {
            $validator->errors()->add('value', "Urutan threshold {$label} harus: upcoming > ancang-ancang > warning.");
        }
    }
}
