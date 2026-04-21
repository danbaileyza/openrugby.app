<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Competition extends Model
{
    use HasFactory, HasSlug, HasUuids;

    public function slugSource(): string
    {
        return trim(($this->name ?? '').' '.($this->grade ?? ''));
    }

    public const LEVEL_PROFESSIONAL = 'professional';

    public const LEVEL_CLUB = 'club';

    public const LEVEL_SCHOOL = 'school';

    protected $fillable = [
        'name', 'code', 'slug', 'format', 'level', 'grade', 'has_standings', 'country', 'tier',
        'logo_url', 'external_id', 'external_source',
    ];

    protected $casts = [
        'has_standings' => 'boolean',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function currentSeason()
    {
        return $this->seasons()->where('is_current', true)->first();
    }

    public static function canonicalCodeFromName(string $name): string
    {
        $normalized = self::normalizedName($name);

        $aliases = [
            'united rugby championship' => 'urc',
            'urc' => 'urc',
            'the rugby championship' => 'rugby_championship',
            'rugby championship' => 'rugby_championship',
            'test matchs' => 'test_matches',
            'test matches' => 'test_matches',
            'epcr champions cup' => 'champions_cup',
            'champions cup' => 'champions_cup',
            'epcr challenge cup' => 'challenge_cup',
            'challenge cup' => 'challenge_cup',
        ];

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        return Str::of($normalized)->slug('_')->toString();
    }

    public static function normalizedName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->toString();
    }
}
