<?php

namespace App\Http\Requests;

use App\Models\SystemThreshold;
use App\Services\CompletionBackdatePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSystemThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('system_threshold')) ?? false;
    }

    public function rules(): array
    {
        return ['key' => ['required', 'string', 'max:255', Rule::unique('system_thresholds', 'key')->ignore($this->route('system_threshold'))], 'value' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:255']];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $values = $this->thresholdValuesWithSubmitted();

            $this->validateOrderedGroup($validator, $values, 'days', 'upcoming_days', 'ancang_ancang_days', 'warning_days');
            $this->validateOrderedGroup($validator, $values, 'km', 'upcoming_km', 'ancang_ancang_km', 'warning_km');
            $this->validateBackdateWindow($validator, $values);
        }];
    }

    /**
     * Nilai threshold yang sedang aktif, ditimpa nilai yang sedang diajukan —
     * jadi kombinasinya dinilai sebagai hasil akhir, bukan per baris.
     *
     * @return array<string, int>
     */
    private function thresholdValuesWithSubmitted(): array
    {
        $values = SystemThreshold::query()->pluck('value', 'key')->map(fn (string $value): int => (int) $value)->all();
        $values[$this->string('key')->toString()] = $this->integer('value');

        return $values;
    }

    /**
     * Batas isi sendiri di atas batas maksimal tidak pernah terpakai: backdate
     * sejauh itu sudah ditolak lebih dulu oleh batas maksimal. Dicek dua arah,
     * jadi menurunkan batas maksimal ke bawah batas isi sendiri juga ditolak.
     *
     * @param  array<string, int>  $values
     */
    private function validateBackdateWindow(Validator $validator, array $values): void
    {
        $selfServiceKey = CompletionBackdatePolicy::SELF_SERVICE_DAYS_KEY;
        $maxKey = CompletionBackdatePolicy::MAX_DAYS_KEY;

        if (! isset($values[$selfServiceKey], $values[$maxKey])) {
            return;
        }

        if ($values[$selfServiceKey] > $values[$maxKey]) {
            $validator->errors()->add('value', 'Batas isi sendiri tidak boleh lebih besar dari batas maksimal.');
        }
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
