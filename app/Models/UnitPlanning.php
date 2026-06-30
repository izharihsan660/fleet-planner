<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitPlanning extends Model
{
    protected $fillable = [
        'unit_id',
        'planning_item_id',
        'last_done_km',
        'last_done_date',
        'next_due_km',
        'next_due_date',
        'freeze_start',
    ];

    protected function casts(): array
    {
        return [
            'last_done_date' => 'date',
            'next_due_date' => 'date',
            'freeze_start' => 'datetime',
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
     * @return HasMany<WorkOrderItem, $this>
     */
    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }
}
