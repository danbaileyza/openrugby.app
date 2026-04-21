<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Team extends Model
{
    use HasFactory, HasSlug, HasUuids;

    protected $fillable = [
        'name', 'slug', 'short_name', 'country', 'type',
        'logo_url', 'primary_color', 'secondary_color',
        'founded_year', 'external_id', 'external_source',
    ];

    public function slugSource(): string
    {
        return (string) $this->name;
    }

    public function seasons(): BelongsToMany
    {
        return $this->belongsToMany(Season::class, 'team_season')
            ->withPivot('pool')
            ->withTimestamps();
    }

    public function playerContracts(): HasMany
    {
        return $this->hasMany(PlayerContract::class);
    }

    public function currentPlayers()
    {
        return $this->playerContracts()->where('is_current', true)->with('player');
    }

    public function matchTeams(): HasMany
    {
        return $this->hasMany(MatchTeam::class);
    }

    public function matchEvents(): HasMany
    {
        return $this->hasMany(MatchEvent::class);
    }

    public function matchStats(): HasMany
    {
        return $this->hasMany(MatchStat::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function ragDocuments(): MorphMany
    {
        return $this->morphMany(RagDocument::class, 'documentable');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withTimestamps();
    }

    protected function countryDisplay(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $country = $this->country;

                if (! is_string($country) || $country === '') {
                    return '';
                }

                $decoded = json_decode($country, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (! empty($decoded['name'])) {
                        return (string) $decoded['name'];
                    }

                    if (! empty($decoded['code'])) {
                        return (string) $decoded['code'];
                    }

                    // All values are null/empty — don't return raw JSON
                    return '';
                }

                return $country;
            }
        );
    }
}
