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

    /**
     * Enforce the "only one current season per competition" invariant.
     * When a season is saved with is_current=true, unflag every sibling
     * season on the same competition so we never end up with duplicates.
     * (MySQL has no partial unique indexes, so the guard lives here.)
     */
    protected static function booted(): void
    {
        static::saving(function (self $season) {
            if (! $season->is_current || ! $season->competition_id) {
                return;
            }
            // Don't trigger on every update — only when the flag is being flipped on
            // or when it was already true but we want to enforce uniqueness defensively.
            static::where('competition_id', $season->competition_id)
                ->where('is_current', true)
                ->when($season->exists, fn ($q) => $q->where('id', '!=', $season->id))
                ->update(['is_current' => false]);
        });
    }

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
