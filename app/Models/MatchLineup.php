<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchLineup extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'match_id', 'player_id', 'team_id', 'jersey_number',
        'role', 'position', 'captain', 'minutes_played',
    ];

    protected $casts = [
        'captain' => 'boolean',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(RugbyMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
