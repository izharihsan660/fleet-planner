<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Unit;
use App\Models\UnitPlanning;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateUnitPlanningBaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $unit = $this->route('unit');
        $unitPlanning = $this->route('unitPlanning');

        return $user !== null
            && $unit instanceof Unit
            && $unitPlanning instanceof UnitPlanning
            && $unitPlanning->unit_id === $unit->id
            && $user->isOneOf([UserRole::Mekanik, UserRole::PlannerArea, UserRole::SpvHo, UserRole::Superadmin])
            && Gate::forUser($user)->allows('view', $unit);
    }

    /**
     * Planner boleh mengisi dari dua arah: tanggal terakhir diganti kalau
     * riwayatnya diketahui, atau perkiraan jatuh tempo kalau yang ada cuma
     * kira-kira. Keduanya berakhir di kolom yang sama.
     *
     * KM tidak boleh 0. Sebelumnya `min:0` lolos validasi dan form menjawab
     * "berhasil disimpan", padahal isBaselineMissing() menganggap 0 sebagai
     * kosong sehingga itemnya tetap terblokir tanpa penjelasan apa pun.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'last_done_km' => ['required', 'integer', 'min:1'],
            'last_done_date' => ['nullable', 'required_without:next_due_date', 'date', 'before_or_equal:today'],
            'next_due_date' => ['nullable', 'required_without:last_done_date', 'date'],
            'is_estimated' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'last_done_km.min' => 'KM tidak boleh 0. Isi perkiraan KM saat part terakhir diganti.',
            'last_done_date.required_without' => 'Isi tanggal terakhir diganti, atau perkiraan tanggal jatuh tempo.',
            'next_due_date.required_without' => 'Isi perkiraan tanggal jatuh tempo, atau tanggal terakhir diganti.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (filled($this->input('last_done_date')) && filled($this->input('next_due_date'))) {
                    $validator->errors()->add('next_due_date', 'Pilih salah satu: tanggal terakhir diganti atau perkiraan jatuh tempo.');
                }
            },
        ];
    }
}
