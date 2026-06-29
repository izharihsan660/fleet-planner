<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanningItem extends Model
{
    protected $fillable = [
        'name',
        'interval_km',
        'interval_days',
    ];
}
