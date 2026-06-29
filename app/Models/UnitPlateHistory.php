<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitPlateHistory extends Model
{
    protected $fillable = [
        'unit_id',
        'plate_number',
        'active_from',
        'active_until',
    ];

    protected function casts(): array
    {
        return [
            'active_from' => 'date',
            'active_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
