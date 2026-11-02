<?php

namespace App\Support;

use App\Enums\UserRole;
use Illuminate\Validation\Rule;

class WorkOrderAssignment
{
    /** @return array<string, array<mixed>> */
    public static function rules(int $siteId, string $dateField = 'scheduled_date'): array
    {
        return [
            'assigned_mechanic_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Mekanik->value)->where('site_id', $siteId),
            ],
            $dateField => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $dateField = 'scheduled_date'): array
    {
        return [
            'assigned_mechanic_id.required' => 'Pilih mekanik penanggung jawab.',
            $dateField.'.required' => 'Tentukan rencana jadwal pengerjaan.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{work_order: array{assigned_mechanic_id: int}, item: array{scheduled_date: string}}
     */
    public static function payload(array $data, string $dateField = 'scheduled_date'): array
    {
        return [
            'work_order' => ['assigned_mechanic_id' => (int) $data['assigned_mechanic_id']],
            'item' => ['scheduled_date' => $data[$dateField]],
        ];
    }
}
