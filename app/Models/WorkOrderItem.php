<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id',
        'unit_planning_id',
        'planning_item_id',
        'action',
        'status',
        'reason',
        'notes',
        'baseline_last_done_km',
        'baseline_last_done_date',
        'previous_due_km',
        'previous_due_date',
        'new_due_km',
        'new_due_date',
        'available_date',
        'planned_date',
        'scheduled_date',
        'freeze_start',
        'freeze_end',
        'completed_odo',
        'completed_date',
        'is_backdated',
        'backdated_days',
        'backdate_override_by',
        'submitted_by',
        'approved_by',
        'approved_at',
        'triggered_by_high_usage',
    ];

    protected function casts(): array
    {
        return [
            'new_due_date' => 'date',
            'baseline_last_done_date' => 'date',
            'available_date' => 'date',
            'planned_date' => 'date',
            'scheduled_date' => 'date',
            'previous_due_date' => 'date',
            'freeze_start' => 'datetime',
            'freeze_end' => 'datetime',
            'completed_date' => 'date',
            'approved_at' => 'datetime',
            'triggered_by_high_usage' => 'boolean',
            'is_backdated' => 'boolean',
        ];
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    public function scopeApplicable(Builder $query): Builder
    {
        return $query->whereHas('unitPlanning', fn (Builder $planningQuery): Builder => $planningQuery->applicable());
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    public function scopeMissingBaseline(Builder $query): Builder
    {
        return $query->whereHas('unitPlanning', fn (Builder $planningQuery): Builder => $planningQuery->missingBaseline());
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    public function scopeWithBaseline(Builder $query): Builder
    {
        return $query->whereHas('unitPlanning', fn (Builder $planningQuery): Builder => $planningQuery->withBaseline());
    }

    /**
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    public function scopeActiveForBaselineUpdate(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('status'), ['cancelled', 'complete']);
    }

    /**
     * Hide a cancelled historical row only when the same work order already
     * contains an active replacement for the same planning item.
     *
     * @param  Builder<WorkOrderItem>  $query
     * @return Builder<WorkOrderItem>
     */
    public function scopeForWorkOrderDetail(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $detailQuery) use ($table): void {
            $detailQuery
                ->where($table.'.status', '!=', 'cancelled')
                ->orWhereNotExists(function (QueryBuilder $replacementQuery) use ($table): void {
                    $replacementQuery
                        ->selectRaw('1')
                        ->from($table.' as replacement_items')
                        ->whereColumn('replacement_items.work_order_id', $table.'.work_order_id')
                        ->whereColumn('replacement_items.planning_item_id', $table.'.planning_item_id')
                        ->whereNotIn('replacement_items.status', ['cancelled', 'complete']);
                });
        });
    }

    /**
     * Item siap dikerjakan bila unitnya sudah punya penanggung jawab dan item
     * ini sudah punya tanggalnya sendiri. Mekanik menempel pada WO, tanggal
     * menempel pada item — jadi satu unit bisa dijadwalkan bertahap.
     */
    public function isScheduled(): bool
    {
        return $this->scheduled_date !== null
            && $this->workOrder?->assigned_mechanic_id !== null;
    }

    public function workHasStarted(): bool
    {
        return $this->isScheduled()
            && CarbonImmutable::parse($this->scheduled_date)->lessThanOrEqualTo(CarbonImmutable::today());
    }

    public function isBaselineReplaceSubmission(): bool
    {
        return ($this->unitPlanning?->isBaselineMissing() ?? true)
            && $this->action === 'replace'
            && in_array($this->status, ['replace', 'in_progress'], true);
    }

    public function isApprovedBaselineReplace(): bool
    {
        return $this->isBaselineReplaceSubmission()
            && $this->status === 'in_progress'
            && $this->approved_at !== null;
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<UnitPlanning, $this>
     */
    public function unitPlanning(): BelongsTo
    {
        return $this->belongsTo(UnitPlanning::class);
    }

    /**
     * @return BelongsTo<PlanningItem, $this>
     */
    public function planningItem(): BelongsTo
    {
        return $this->belongsTo(PlanningItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
