<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referee extends Model
{
    use HasFactory, HasSlug, HasUuids;

    protected $fillable = [
        'first_name', 'last_name', 'slug', 'nationality', 'tier',
        'photo_url', 'external_id', 'external_source',
    ];

    public function slugSource(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function matchOfficials(): HasMany
    {
        return $this->hasMany(MatchOfficial::class);
    }
}
