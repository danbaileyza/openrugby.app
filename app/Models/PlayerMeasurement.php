<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerMeasurement extends Model
{
    use HasFactory, HasUuids;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_ON_EDIT = 'on_edit';

    public const SOURCE_IMPORTED = 'imported';

    protected $fillable = [
        'player_id', 'height_cm', 'weight_kg',
        'recorded_at', 'source', 'notes', 'captured_by_user_id',
    ];

    protected $casts = [
        'recorded_at' => 'date',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
