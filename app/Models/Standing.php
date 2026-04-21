<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Standing extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'season_id', 'team_id', 'pool', 'position',
        'played', 'won', 'drawn', 'lost',
        'points_for', 'points_against', 'tries_for', 'tries_against',
        'bonus_points', 'total_points', 'point_differential',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
