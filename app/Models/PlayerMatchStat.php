<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerMatchStat extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'match_id', 'player_id', 'stat_key', 'stat_value',
    ];

    protected $casts = [
        'stat_value' => 'decimal:2',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(RugbyMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
