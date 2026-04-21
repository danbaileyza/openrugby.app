<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'competition_id', 'label', 'start_date', 'end_date',
        'expected_matches',
        'is_current', 'external_id', 'external_source',
        'completeness_score', 'completeness_audit', 'is_verified', 'verified_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'completeness_audit' => 'array',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(RugbyMatch::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_season')
            ->withPivot('pool')
            ->withTimestamps();
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function playerSeasonStats(): HasMany
    {
        return $this->hasMany(PlayerSeasonStat::class);
    }
}
