<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'previous_due_km',
        'previous_due_date',
        'new_due_km',
        'new_due_date',
        'available_date',
        'freeze_start',
        'freeze_end',
        'completed_odo',
        'completed_date',
        'submitted_by',
        'approved_by',
        'approved_at',
        'triggered_by_high_usage',
    ];

    protected function casts(): array
    {
        return [
            'new_due_date' => 'date',
            'available_date' => 'date',
            'previous_due_date' => 'date',
            'freeze_start' => 'datetime',
            'freeze_end' => 'datetime',
            'completed_date' => 'date',
            'approved_at' => 'datetime',
            'triggered_by_high_usage' => 'boolean',
        ];
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
