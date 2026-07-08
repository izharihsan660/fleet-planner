<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningItem extends Model
{
    protected $fillable = [
        'name',
        'interval_km',
        'interval_days',
    ];

    /**
     * @return HasMany<UnitPlanning, $this>
     */
    public function unitPlannings(): HasMany
    {
        return $this->hasMany(UnitPlanning::class);
    }

    /**
     * @return HasMany<PlanningItemOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(PlanningItemOverride::class);
    }

    /**
     * @return HasMany<WorkOrderItem, $this>
     */
    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }
}
