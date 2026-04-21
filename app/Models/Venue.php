<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'city', 'country', 'capacity',
        'latitude', 'longitude', 'surface',
        'external_id', 'external_source',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function matches(): HasMany
    {
        return $this->hasMany(RugbyMatch::class, 'venue_id');
    }
}
