<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataImport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'source', 'entity_type', 'status',
        'records_processed', 'records_created', 'records_updated', 'records_failed',
        'error_log', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'error_log' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
