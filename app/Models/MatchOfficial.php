<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchOfficial extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'match_id', 'referee_id', 'role',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(RugbyMatch::class, 'match_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Referee::class);
    }
}
