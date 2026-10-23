<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Language extends Model
{
    protected $fillable = [
        'code', 'label', 'emoji', 'direction', 'is_default', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    protected function isRtl(): Attribute
    {
        return Attribute::get(fn () => $this->direction === 'rtl');
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /**
     * Get the default language record.
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Get all active languages as [code => ['label', 'emoji', 'rtl']] array
     * (same shape as config/languages.php for backwards-compat).
     * Results are cached for 60 minutes.
     */
    public static function toConfigArray(): array
    {
        return Cache::remember('languages_config_array', 3600, function () {
            return static::active()->ordered()->get()
                ->mapWithKeys(fn (self $lang) => [
                    $lang->code => [
                        'label' => $lang->label,
                        'emoji' => $lang->emoji,
                        'rtl'   => $lang->direction === 'rtl',
                    ],
                ])->all();
        });
    }

    /**
     * Get all active languages as a cached Collection of Language objects.
     * Used by AppServiceProvider to populate config('languages') on every request.
     * Cached forever — flushed by flushCache() when admin saves/deletes a language.
     */
    public static function getAllCached(): \Illuminate\Support\Collection
    {
        return Cache::rememberForever('languages_all_collection', function () {
            return static::active()->ordered()->get(['code', 'label', 'emoji', 'direction', 'is_default']);
        });
    }

    /**
     * Flush the languages config cache (call after any save/delete).
     */
    public static function flushCache(): void
    {
        Cache::forget('languages_config_array');
        Cache::forget('languages_all_collection');
        Cache::forget('schema.has_languages_table');
    }
}
