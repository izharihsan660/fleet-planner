<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'is_estimated',
        'is_excluded',
        'excluded_reason',
        'freeze_start',
    ];

    protected function casts(): array
    {
        return [
            'last_done_date' => 'date',
            'next_due_date' => 'date',
            'is_estimated' => 'boolean',
            'is_excluded' => 'boolean',
            'freeze_start' => 'datetime',
        ];
    }

    /**
     * @param  Builder<UnitPlanning>  $query
     * @return Builder<UnitPlanning>
     */
    public function scopeApplicable(Builder $query): Builder
    {
        return $query->where('is_excluded', false);
    }

    /**
     * @param  Builder<UnitPlanning>  $query
     * @return Builder<UnitPlanning>
     */
    public function scopeMissingBaseline(Builder $query): Builder
    {
        return $query->where(function (Builder $baselineQuery): void {
            $baselineQuery
                ->whereNull($baselineQuery->qualifyColumn('last_done_km'))
                ->orWhere($baselineQuery->qualifyColumn('last_done_km'), 0);
        });
    }

    /**
     * @param  Builder<UnitPlanning>  $query
     * @return Builder<UnitPlanning>
     */
    public function scopeWithBaseline(Builder $query): Builder
    {
        return $query
            ->whereNotNull($query->qualifyColumn('last_done_km'))
            ->where($query->qualifyColumn('last_done_km'), '!=', 0);
    }

    public function isBaselineMissing(): bool
    {
        return $this->last_done_km === null || (int) $this->last_done_km === 0;
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
