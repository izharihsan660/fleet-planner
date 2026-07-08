<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceImport extends Model
{
    protected $fillable = [
        'type',
        'status',
        'original_filename',
        'stored_path',
        'total_rows',
        'success_rows',
        'failed_rows',
        'estimated_rows',
        'summary',
        'created_by',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
