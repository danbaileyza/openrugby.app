<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchTeam extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'match_id', 'team_id', 'side', 'score', 'ht_score',
        'tries', 'conversions', 'penalties_kicked', 'drop_goals',
        'bonus_points', 'is_winner',
    ];

    protected $casts = [
        'is_winner' => 'boolean',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(RugbyMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
