<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CompleteBaselineWorkOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $workOrder = $this->route('wo');
        $item = $this->route('item');

        return $user !== null
            && $workOrder instanceof WorkOrder
            && $item instanceof WorkOrderItem
            && $item->work_order_id === $workOrder->id
            && $user->isOneOf([UserRole::Superadmin, UserRole::PlannerArea, UserRole::SpvHo])
            && Gate::forUser($user)->allows('view', $workOrder);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'last_done_km' => ['required', 'integer', 'min:0'],
            'last_done_date' => ['required', 'date', 'before_or_equal:today'],
            'completed_odo' => ['required', 'integer', 'min:0', 'gte:last_done_km'],
            'completed_date' => ['required', 'date', 'after_or_equal:last_done_date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
