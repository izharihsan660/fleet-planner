<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubmitWorkListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.site_id' => ['required', 'integer', Rule::exists('sites', 'id')],
            'groups.*.action' => ['required', 'string', Rule::in(['replace', 'postpone', 'blocked'])],
            'groups.*.item_ids' => ['required', 'array', 'min:1'],
            'groups.*.item_ids.*' => ['required', 'integer', 'distinct', Rule::exists('work_order_items', 'id')],
            'groups.*.assigned_mechanic_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Mekanik->value)],
            'groups.*.scheduled_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('groups', []) as $index => $group) {
                    if (($group['action'] ?? null) !== 'replace') {
                        continue;
                    }

                    if (blank($group['assigned_mechanic_id'] ?? null)) {
                        $validator->errors()->add("groups.{$index}.assigned_mechanic_id", 'Pilih mekanik untuk Ajukan Ganti.');

                        continue;
                    }

                    $mechanicMatchesSite = User::query()
                        ->whereKey($group['assigned_mechanic_id'])
                        ->where('role', UserRole::Mekanik->value)
                        ->where('site_id', $group['site_id'] ?? null)
                        ->exists();

                    if (! $mechanicMatchesSite) {
                        $validator->errors()->add("groups.{$index}.assigned_mechanic_id", 'Mekanik harus sesuai dengan site yang dipilih.');
                    }
                }
            },
        ];
    }
}
