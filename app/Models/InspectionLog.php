<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionLog extends Model
{
    protected $fillable = [
        'unit_id',
        'mechanic_id',
        'inspection_date',
        'odometer',
        'previous_odo',
    ];

    protected $appends = [
        'insufficient_data',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mechanic_id');
    }

    public function getInsufficientDataAttribute(): bool
    {
        return (bool) ($this->attributes['insufficient_data'] ?? false);
    }
}
