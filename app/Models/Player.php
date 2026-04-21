<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Player extends Model
{
    use HasFactory, HasSlug, HasUuids;

    protected $fillable = [
        'first_name', 'last_name', 'slug', 'dob', 'nationality',
        'position', 'position_group', 'height_cm', 'weight_kg',
        'photo_url', 'is_active', 'external_id', 'external_source',
    ];

    public function slugSource(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    protected $casts = [
        'dob' => 'date',
        'is_active' => 'boolean',
    ];

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(PlayerContract::class);
    }

    public function currentContract()
    {
        return $this->contracts()->where('is_current', true)->first();
    }

    public function currentTeam()
    {
        return $this->currentContract()?->team;
    }

    public function matchLineups(): HasMany
    {
        return $this->hasMany(MatchLineup::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(PlayerMeasurement::class);
    }

    public function matchEvents(): HasMany
    {
        return $this->hasMany(MatchEvent::class);
    }

    public function playerMatchStats(): HasMany
    {
        return $this->hasMany(PlayerMatchStat::class);
    }

    public function seasonStats(): HasMany
    {
        return $this->hasMany(PlayerSeasonStat::class);
    }

    public function ragDocuments(): MorphMany
    {
        return $this->morphMany(RagDocument::class, 'documentable');
    }
}
