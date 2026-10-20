<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\WorkOrderItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPostponeWorkOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->isOneOf([UserRole::Superadmin, UserRole::PlannerArea]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $item = $this->route('item');
        $dateOnly = $item instanceof WorkOrderItem
            && ($item->unitPlanning?->isDueByDate() ?? false)
            && ! ($item->unitPlanning?->isDueByKm() ?? false);

        return [
            'reason' => ['required', 'string', 'max:1000'],
            'new_due_km' => [$dateOnly ? 'nullable' : 'required', 'integer', 'min:0'],
            'new_due_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
