<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningItemOverride extends Model
{
    protected $fillable = [
        'planning_item_id',
        'vehicle_category',
        'interval_km',
        'interval_days',
    ];

    /**
     * @return BelongsTo<PlanningItem, $this>
     */
    public function planningItem(): BelongsTo
    {
        return $this->belongsTo(PlanningItem::class);
    }
}
