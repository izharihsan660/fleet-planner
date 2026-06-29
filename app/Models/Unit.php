<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = [
        'site_id',
        'customer',
        'current_plate',
        'type',
        'brand',
        'year',
        'current_odo',
        'status',
    ];

    protected $appends = [
        'is_warranty',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<UnitPlateHistory, $this>
     */
    public function plateHistories(): HasMany
    {
        return $this->hasMany(UnitPlateHistory::class);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isWarranty(): Attribute
    {
        return Attribute::get(fn (): bool => $this->current_odo < 50000);
    }
}
