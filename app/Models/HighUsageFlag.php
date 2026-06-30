<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HighUsageFlag extends Model
{
    protected $fillable = [
        'unit_id',
        'planning_item_id',
        'unit_planning_id',
        'avg_km_per_day',
        'estimated_due_days',
        'flagged_at',
        'action_taken',
        'action_taken_at',
        'action_taken_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'avg_km_per_day' => 'decimal:2',
            'flagged_at' => 'datetime',
            'action_taken_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<PlanningItem, $this>
     */
    public function planningItem(): BelongsTo
    {
        return $this->belongsTo(PlanningItem::class);
    }

    /**
     * @return BelongsTo<UnitPlanning, $this>
     */
    public function unitPlanning(): BelongsTo
    {
        return $this->belongsTo(UnitPlanning::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actionTakenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_taken_by');
    }
}
