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
        'vehicle_category',
        'year',
        'current_odo',
        'has_odometer_reading',
        'needs_document_verification',
        'avg_km_per_day',
        'status',
    ];

    protected $appends = [
        'is_warranty',
    ];

    protected function casts(): array
    {
        return [
            'has_odometer_reading' => 'boolean',
            'needs_document_verification' => 'boolean',
        ];
    }

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
     * @return HasMany<UnitSiteTransfer, $this>
     */
    public function siteTransfers(): HasMany
    {
        return $this->hasMany(UnitSiteTransfer::class);
    }

    /**
     * @return HasMany<InspectionLog, $this>
     */
    public function inspectionLogs(): HasMany
    {
        return $this->hasMany(InspectionLog::class);
    }

    /**
     * @return HasMany<UnitPlanning, $this>
     */
    public function unitPlannings(): HasMany
    {
        return $this->hasMany(UnitPlanning::class);
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function isWarranty(): Attribute
    {
        return Attribute::get(fn (): bool => $this->current_odo < 50000);
    }
}
