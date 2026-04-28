<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Favourite extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'favouritable_type', 'favouritable_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favouritable(): MorphTo
    {
        return $this->morphTo();
    }
}
