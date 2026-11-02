<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $guarded = ['id'];

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'video_tags');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(TagTranslation::class);
    }

    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: function () {
                $locale = app()->getLocale();
                if ($locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
                    return $this->name;
                }

                $translation = $this->relationLoaded('translations')
                    ? $this->translations->firstWhere('locale', $locale)
                    : $this->translations()->where('locale', $locale)->first();

                $translatedName = trim((string) ($translation?->name ?? ''));
                $jsonName = __($this->name);

                // 1.2.3: never a translation that uses a word that is always blocked (App\Support\ContentSafety: a child,
                // a school-age minor) where the tag's own name does not; the tag's own name then
                if (! \App\Support\ContentSafety::hides($this->name)) {
                    $translatedName = \App\Support\ContentSafety::hides($translatedName) ? '' : $translatedName;
                    $jsonName = \App\Support\ContentSafety::hides($jsonName) ? $this->name : $jsonName;
                }

                if ($jsonName !== $this->name && ($translatedName === '' || preg_match('/^[\x00-\x7F]+$/', $translatedName))) {
                    return $jsonName;
                }

                return $translatedName !== '' && $translatedName !== $this->name
                    ? $translatedName
                    : $jsonName;
            }
        );
    }
}
