<?php

namespace App\Models;

use App\Enums\ManualProjectionReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualProjection extends Model
{
    protected $fillable = [
        'unit_id', 'planning_item_id', 'unit_planning_id', 'target_date', 'reason',
        'notes', 'status', 'created_by', 'cancelled_by', 'cancelled_at', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'reason' => ManualProjectionReason::class,
            'cancelled_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<ManualProjection>  $query
     * @return Builder<ManualProjection>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<PlanningItem, $this> */
    public function planningItem(): BelongsTo
    {
        return $this->belongsTo(PlanningItem::class);
    }

    /** @return BelongsTo<UnitPlanning, $this> */
    public function unitPlanning(): BelongsTo
    {
        return $this->belongsTo(UnitPlanning::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
