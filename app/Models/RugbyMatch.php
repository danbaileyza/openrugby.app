<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Named RugbyMatch to avoid collision with PHP's Match keyword.
 * Maps to the 'matches' table.
 */
class RugbyMatch extends Model
{
    use HasFactory, HasSlug, HasUuids;

    public function slugSource(): string
    {
        // Matches need the teams loaded; called by HasSlug during saving.
        $home = $this->matchTeams->firstWhere('side', 'home')?->team?->name;
        $away = $this->matchTeams->firstWhere('side', 'away')?->team?->name;
        $date = $this->kickoff?->format('Y-m-d');

        return trim(($date ? $date.' ' : '').Str::slug((string) $home).'-vs-'.Str::slug((string) $away));
    }

    protected $table = 'matches';

    public const SOURCE_IMPORTED = 'imported';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_TEAM_USER = 'team_user';

    protected $fillable = [
        'season_id', 'venue_id', 'kickoff', 'status', 'round', 'stage',
        'attendance', 'weather_conditions',
        'score_source', 'captured_by_user_id', 'captured_at', 'live_started_at',
        'external_id', 'external_source',
    ];

    protected $casts = [
        'kickoff' => 'datetime',
        'captured_at' => 'datetime',
        'live_started_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function matchTeams(): HasMany
    {
        return $this->hasMany(MatchTeam::class, 'match_id');
    }

    public function homeTeam()
    {
        return $this->matchTeams()->where('side', 'home')->first();
    }

    public function awayTeam()
    {
        return $this->matchTeams()->where('side', 'away')->first();
    }

    public function events(): HasMany
    {
        return $this->hasMany(MatchEvent::class, 'match_id');
    }

    public function lineups(): HasMany
    {
        return $this->hasMany(MatchLineup::class, 'match_id');
    }

    public function officials(): HasMany
    {
        return $this->hasMany(MatchOfficial::class, 'match_id');
    }

    public function matchStats(): HasMany
    {
        return $this->hasMany(MatchStat::class, 'match_id');
    }

    public function playerMatchStats(): HasMany
    {
        return $this->hasMany(PlayerMatchStat::class, 'match_id');
    }

    public function ragDocuments(): MorphMany
    {
        return $this->morphMany(RagDocument::class, 'documentable');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
