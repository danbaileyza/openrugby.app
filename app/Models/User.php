<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TEAM_USER = 'team_user';

    protected $fillable = [
        'name', 'email', 'password', 'role',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')->withTimestamps();
    }

    public function favourites(): HasMany
    {
        return $this->hasMany(Favourite::class);
    }

    public function favouriteTeams(): MorphToMany
    {
        return $this->morphedByMany(Team::class, 'favouritable', 'favourites')->withTimestamps();
    }

    public function favouritePlayers(): MorphToMany
    {
        return $this->morphedByMany(Player::class, 'favouritable', 'favourites')->withTimestamps();
    }

    public function favouriteCompetitions(): MorphToMany
    {
        return $this->morphedByMany(Competition::class, 'favouritable', 'favourites')->withTimestamps();
    }

    /**
     * Has this user favourited the given model?
     */
    public function hasFavourited(\Illuminate\Database\Eloquent\Model $model): bool
    {
        return $this->favourites()
            ->where('favouritable_type', $model->getMorphClass())
            ->where('favouritable_id', $model->getKey())
            ->exists();
    }

    public function toggleFavourite(\Illuminate\Database\Eloquent\Model $model): bool
    {
        $existing = $this->favourites()
            ->where('favouritable_type', $model->getMorphClass())
            ->where('favouritable_id', $model->getKey())
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        $this->favourites()->create([
            'favouritable_type' => $model->getMorphClass(),
            'favouritable_id' => $model->getKey(),
        ]);

        return true;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canCaptureForTeam(Team $team): bool
    {
        return $this->isAdmin() || $this->teams()->where('teams.id', $team->id)->exists();
    }

    public function canCaptureForMatch(RugbyMatch $match): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $teamIds = $match->matchTeams->pluck('team_id');

        return $this->teams()->whereIn('teams.id', $teamIds)->exists();
    }

    /**
     * Can this user manage the given player (edit bio, record measurements)?
     * Admins can do any player. Team users can only manage players whose
     * current contract is with one of their linked teams.
     */
    public function canManagePlayer(Player $player): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $currentTeamId = $player->contracts->where('is_current', true)->first()?->team_id
            ?? $player->contracts()->where('is_current', true)->value('team_id');

        if (! $currentTeamId) {
            return false;
        }

        return $this->teams()->where('teams.id', $currentTeamId)->exists();
    }
}
