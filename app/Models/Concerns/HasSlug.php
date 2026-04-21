<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Adds slug-based route binding and auto-generation on create/update.
 *
 * Models using this trait should implement:
 *   - slugSource(): string   — raw text to turn into a slug
 *
 * Resolution prefers slug; UUID lookup is the fallback (so bookmarked
 * /competitions/<uuid> links keep working).
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::saving(function ($model) {
            $source = trim((string) $model->slugSource());

            // Only (re)generate when missing or the source changed meaningfully.
            if (blank($model->slug) || $model->shouldRegenerateSlug()) {
                $base = Str::slug($source) ?: 'item';
                $model->slug = $model->uniqueSlug($base);
            }
        });
    }

    /**
     * Use the slug for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Fall back to UUID lookup when the slug doesn't match. Keeps old
     * bookmarked UUID URLs working.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        // Try slug first, then UUID
        return $this->where('slug', $value)->first()
            ?? $this->where('id', $value)->first();
    }

    /**
     * Override in a model to control when the slug should regenerate.
     * Default: never regenerate once assigned (stable permalinks).
     */
    public function shouldRegenerateSlug(): bool
    {
        return false;
    }

    /**
     * Returns a slug unique across the model's table. Appends -2, -3, … on collision.
     */
    protected function uniqueSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($this->exists, fn ($q) => $q->where('id', '!=', $this->id))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
