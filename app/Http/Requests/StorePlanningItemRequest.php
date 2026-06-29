<?php

namespace App\Http\Requests;

use App\Models\PlanningItem;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanningItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PlanningItem::class) ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'interval_km' => ['required', 'integer', 'min:0'], 'interval_days' => ['required', 'integer', 'min:0']];
    }
}
