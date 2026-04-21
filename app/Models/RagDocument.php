<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RagDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'source_type', 'documentable_type', 'documentable_id',
        'content', 'embedding', 'metadata', 'generated_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
        'generated_at' => 'datetime',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
