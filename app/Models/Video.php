<?php

namespace App\Models;

use App\Data\UploadSettings;
use App\Enums\ThumbnailStatus;
use App\Enums\VideoStatus;
use App\Models\Interfaces\Likeable;
use App\Models\Interfaces\Reportable;
use App\Models\Traits\Filterable;
use App\Models\Traits\HasLike;
use App\Models\Traits\HasReport;
use App\Parsers\LinkParser;
use App\Parsers\Parser;
use App\Parsers\TimeCodeParser;
use App\Services\SeoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Symfony\Component\Intl\Languages;

/**
 * @mixin IdeHelperVideo
 */
class Video extends Model implements Likeable, Reportable
{
    use Filterable, HasFactory, HasLike, HasReport, Searchable, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'allow_comments' => 'boolean',
        'show_likes' => 'boolean',
        'show_ad' => 'boolean',
        'status' => VideoStatus::class,
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'banned_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'avdb_release_date' => 'date',
        'avdb_updated_at' => 'datetime',
        'avdb_duration_sync_attempted_at' => 'datetime',
        'xvid_release_date' => 'date',
        'xvid_updated_at' => 'datetime',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array
     */
    protected $with = ['thumbnail'];

    /**
     * Boot: eager-load only the current locale's translation to support
     * the title/description accessors without N+1 queries.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('currentTranslation', function (\Illuminate\Database\Eloquent\Builder $builder) {
            $locale = app()->getLocale();
            if ($locale !== 'en') {
                $builder->with(['translations' => function ($q) use ($locale) {
                    $q->where('locale', $locale);
                }]);
            }
        });
    }

    /**
     * Route pattern for the public video identifier: imported videos use a UUID, videos uploaded
     * through the studio get a 10-digit number (App\Helpers\Number::unique()). Both must resolve.
     */
    public const string UUID_PATTERN = '(?:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9]{10})';

    public const string THUMBNAIL_FOLDER = 'thumbnails';

    public const string VIDEO_FOLDER = 'videos';

    public const string CHUNK_FOLDER = 'chunks';

    public const string MIMETYPE = 'video/mp4';

    public const array AVAILABLE_LANGUAGES = [
        'en',
        'fr',
        'th',
        'id',
        'ko',
        'vi',
        'zh',
        'ja',
        'de',
        'es',
        'pt',
        'ru',
        'ar',
        'tr',
        'hi',
        'ms',
        'tl',
    ];

    public static function languages(): array
    {
        $configured = config('languages', []);

        // Build ['code' => 'Label'] from the site's active languages
        $result = [];
        foreach ($configured as $code => $meta) {
            $label = is_array($meta) ? ($meta['label'] ?? $code) : $meta;
            $result[$code] = $label;
        }

        return $result;
    }

    public static function generatedThumbnailCount(): int
    {
        return UploadSettings::fromConfig()->thumbnailCount;
    }

    /**
     * -------------------- RELATIONS --------------------
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function viewsHistory(): HasMany
    {
        return $this->hasMany(View::class, 'video_id');
    }

    public function viewDailyStats(): HasMany
    {
        return $this->hasMany(VideoViewDailyStat::class, 'video_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'video_id');
    }

    public function pinned_comment(): HasOne
    {
        return $this->hasOne(Comment::class, 'id', 'pinned_comment_id');
    }

    public function comment_interactions(): HasManyThrough
    {
        return $this->hasManyThrough(Interaction::class, Comment::class, 'video_id', 'likeable_id')
            ->where('likeable_type', Comment::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_has_videos', 'video_id', 'playlist_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'video_tags');
    }

    public function thumbnails(): HasMany
    {
        return $this->hasMany(Thumbnail::class, 'video_id');
    }

    public function thumbnail(): HasOne
    {
        return $this->hasOne(Thumbnail::class, 'video_id')->where(['is_active' => true]);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(VideoTranslation::class, 'video_id');
    }

    /**
     * Get the title in the user's current locale (with fallback to primary title).
     */
    public function getTranslatedTitleAttribute(): string
    {
        $locale = app()->getLocale();
        $baseTitle = $this->getRawOriginal('title') ?? ($this->attributes['title'] ?? '');

        if ($locale !== 'en') {
            $t = $this->translations->firstWhere('locale', $locale);
            if ($this->translationTitleIsUsable($t, $baseTitle)) {
                return $t->title;
            }
        }

        return (string) $baseTitle;
    }

    /**
     * Get the description in the user's current locale.
     */
    public function getTranslatedDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $baseDescription = $this->getRawOriginal('description') ?? ($this->attributes['description'] ?? null);

        if ($locale !== 'en') {
            $t = $this->translations->firstWhere('locale', $locale);
            if ($this->translationDescriptionIsUsable($t, $baseDescription)) {
                return $t->description;
            }
        }

        return $baseDescription;
    }

    public function uploadedThumbnail(): HasOne
    {
        return $this->hasOne(Thumbnail::class, 'video_id')->where(['status' => ThumbnailStatus::UPLOADED]);
    }

    /**
     * -------------------- ATTRIBUTES --------------------
     */

    /**
     * Title accessor — returns translated title for the current locale.
     * Falls back to the raw DB title if no translation exists.
     * This makes ALL views automatically show translated titles.
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $locale = app()->getLocale();
                if ($locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
                    return $value; // English = raw DB value
                }
                $t = $this->getCachedTranslation($locale);

                return $this->translationTitleIsUsable($t, $value) ? $t->title : $value;
            }
        );
    }

    /**
     * Description accessor — returns translated description for the current locale.
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $locale = app()->getLocale();
                if ($locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
                    return $value;
                }
                $t = $this->getCachedTranslation($locale);

                return $this->translationDescriptionIsUsable($t, $value) ? $t->description : $value;
            }
        );
    }

    protected function localizedTypeName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->translatedMetaValue('type_name')
        );
    }

    protected function localizedCountry(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->translatedMetaValue('country')
        );
    }

    /**
     * Cache the translation lookup per-locale per-request to avoid N+1 queries.
     * The translations relation is eager-loaded via $with or manually.
     */
    private array $cachedTranslations = [];

    private function getCachedTranslation(string $locale): ?VideoTranslation
    {
        if (! array_key_exists($locale, $this->cachedTranslations)) {
            // Use the already-loaded relation if available (avoids extra queries)
            if ($this->relationLoaded('translations')) {
                $this->cachedTranslations[$locale] = $this->translations->firstWhere('locale', $locale);
            } else {
                $this->cachedTranslations[$locale] = $this->translations()->where('locale', $locale)->first();
            }
        }

        return $this->cachedTranslations[$locale];
    }

    private function translatedMetaValue(string $field): ?string
    {
        $locale = app()->getLocale();

        if ($locale !== 'en' && $locale !== config('app.fallback_locale', 'en')) {
            $translation = $this->getCachedTranslation($locale);
            if (! empty($translation->{$field})) {
                return $translation->{$field};
            }
        }

        $translation = $this->getCachedTranslation('en');
        if ($translation && ! empty($translation->{$field})) {
            return $translation->{$field};
        }

        return null;
    }

    private function translationTitleIsUsable(?VideoTranslation $translation, mixed $baseTitle = null): bool
    {
        if (! $translation || empty($translation->title)) {
            return false;
        }

        $title = $this->cleanComparableTitle($translation->title);
        if ($title === '') {
            return false;
        }

        $baseTitle = $this->cleanComparableTitle($baseTitle);
        return $baseTitle === '' || ! hash_equals($title, $baseTitle);
    }

    private function translationDescriptionIsUsable(?VideoTranslation $translation, mixed $baseDescription = null): bool
    {
        if (! $translation || empty($translation->description)) {
            return false;
        }

        $description = $this->cleanComparableTitle($translation->description);
        $baseDescription = $this->cleanComparableTitle($baseDescription);

        return $description !== '' && ($baseDescription === '' || ! hash_equals($description, $baseDescription));
    }

    private function cleanComparableTitle(mixed $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::lower(trim($value));
    }

    protected function fileUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Upload18 videos don't have a local file URL
                if ($this->embed_code) {
                    return null;
                }

                return route('video.file', $this);
            }
        );
    }

    /**
     * Get the Upload18 embed iframe URL.
     */
    protected function embedUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (empty($this->embed_code)) {
                    return null;
                }

                $embedCode = trim((string) $this->embed_code);

                // If embed_code is already a full URL, only render an approved
                // Upload18 provider host. This keeps sandboxed provider frames
                // from becoming an arbitrary same-origin script surface.
                if (preg_match('#^https?://#i', $embedCode)) {
                    $parts = parse_url($embedCode);
                    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
                    $allowedHosts = [
                        'upload18.org', 'upload18.net', 'upload18.com', 'upload18.cc',
                        'helvid.com', '321watch.workers.dev',
                    ];
                    $allowed = false;
                    foreach ($allowedHosts as $allowedHost) {
                        if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                            $allowed = true;
                            break;
                        }
                    }

                    return $allowed ? $embedCode : null;
                }

                // Legacy: embed_code is just a slug/ID
                return "https://upload18.org/play/index/{$embedCode}";
            }
        );
    }

    /**
     * Check if this video is hosted on Upload18.
     */
    protected function isUpload18(): Attribute
    {
        return Attribute::make(
            get: fn () => ! empty($this->embed_code)
        );
    }

    /**
     * Check if this video is imported from XVIDAPI.
     */
    protected function isXvidapi(): Attribute
    {
        return Attribute::make(
            get: fn () => ! empty($this->xvid_id)
        );
    }

    /**
     * Check if this video is imported from an external provider (AVDB or XVIDAPI).
     */
    protected function isImported(): Attribute
    {
        return Attribute::make(
            get: fn () => ! empty($this->avdb_id) || ! empty($this->xvid_id)
        );
    }

    protected function thumbnailUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->thumbnail) {
                    return route('video.thumbnail', $this);
                }
                if (! empty($this->poster_url)) {
                    return $this->poster_url;
                }

                return '/images/placeholders/no-image.svg';
            }
        );
    }

    protected function interactionsCount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) (($this->attributes['likes_count'] ?? 0) + ($this->attributes['dislikes_count'] ?? 0))
        );
    }

    protected function previewThumbnails(): Attribute
    {
        return Attribute::make(
            get: function () {
                $thumbs = $this->relationLoaded('thumbnails')
                    ? $this->thumbnails->filter(fn ($t) => !empty($t->file))
                    : $this->thumbnails()->whereNotNull('file')->where('file', '!=', '')->limit(3)->get();

                if ($thumbs->isNotEmpty()) {
                    return $thumbs->map(fn ($t) => route('video.thumbnails', [$this, $t]))->values()->all();
                }

                return [$this->thumbnail_url];
            }
        );
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => ! $this->is_uploading &&
                (
                    (
                        $this->status === VideoStatus::PUBLIC &&
                        ($this->published_at === null || $this->published_at->lte(now()))
                    ) ||
                    ($this->status === VideoStatus::PLANNED && $this->scheduled_at->lte(now()))
                )

        );
    }

    protected function isPublic(): Attribute
    {
        return Attribute::make(
            get: fn () => ! $this->is_uploading &&
                (
                    (
                        $this->status === VideoStatus::PUBLIC &&
                        ($this->published_at === null || $this->published_at->lte(now()))
                    ) ||
                    ($this->status === VideoStatus::PLANNED && $this->scheduled_at->lte(now())) ||
                    $this->status === VideoStatus::UNLISTED
                )

        );
    }

    protected function isCreated(): Attribute
    {
        return Attribute::make(
            get: fn () => ! in_array($this->status, [VideoStatus::DRAFT, VideoStatus::FAILED]) && ! $this->is_uploading
        );
    }

    protected function isPlanned(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::PLANNED && $this->scheduled_at->gt(now())

        );
    }

    protected function isUnlisted(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::UNLISTED

        );
    }

    protected function isPrivate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::PRIVATE

        );
    }

    protected function isDraft(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::DRAFT

        );
    }

    protected function isBanned(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::BANNED
        );
    }

    protected function isUploading(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->uploaded_at)
        );
    }

    protected function isUploaded(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status !== VideoStatus::FAILED && ! $this->is_uploading
        );
    }

    protected function isFailed(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::FAILED
        );
    }

    protected function duration(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (! is_numeric($value) || (int) $value <= 0) {
                    return '';
                }

                $value = (int) $value;

                if ($value >= 3600) {
                    return floor($value / 3600).':'.sprintf('%02d', floor(($value % 3600) / 60)).':'.sprintf('%02d', ($value % 3600) % 60);
                } else {
                    return floor($value / 60).':'.sprintf('%02d', $value % 60);
                }
            }
        );
    }

    protected function shortDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => Parser::apply(Str::limit($this->description, 200), [LinkParser::class, TimeCodeParser::class])
        );
    }

    protected function parsedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => Parser::apply($this->description, [LinkParser::class, TimeCodeParser::class])
        );
    }

    protected function descriptionIsLong(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::length($this->description) > 247,
        );
    }

    protected function shortTitle(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::limit($this->title, '69', '...')
        );
    }

    protected function realStatus(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === VideoStatus::PUBLIC || ($this->status === VideoStatus::PLANNED && $this->scheduled_at->lte(now())) ? VideoStatus::PUBLIC : $this->status
        );
    }

    protected function route(): Attribute
    {
        return Attribute::make(
            get: fn () => app(SeoService::class)->videoUrl($this),
        );
    }

    public function routeWithParams(array $params = []): string
    {
        return app(SeoService::class)->videoUrl($this, $params);
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => 'video',
        );
    }

    protected function isPinned(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user->pinned_video?->is($this)
        );
    }

    protected function languageName(): Attribute
    {
        return Attribute::make(
            get: function () {
                $langs = static::languages();
                if (isset($langs[$this->language])) {
                    return $langs[$this->language];
                }

                // Fallback to Symfony Intl
                return ucfirst(Languages::getName($this->language, app()->getLocale()));
            },
        );
    }

    protected function date(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->is_private || $this->is_unlisted || $this->is_draft || $this->is_failed) {
                    return $this->created_at;
                }

                if ($this->is_planned) {
                    return $this->scheduled_at ?? $this->created_at;
                }

                return $this->published_at ?? $this->created_at;
            }
        );
    }

    /**
     * -------------------- SCOPES --------------------
     */

    /**
     * Scope a query to only include active videos.
     */
    public function scopeActive(QueryBuilder|Builder $query): QueryBuilder|Builder
    {
        return $query->where(function (QueryBuilder|Builder $query) {
            $query->where(function (QueryBuilder|Builder $query) {
                $query->where('status', VideoStatus::PUBLIC)
                    ->whereNotNull('uploaded_at')
                    ->where(function (QueryBuilder|Builder $query) {
                        $query->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    });
            })->orWhere(function (QueryBuilder|Builder $query) {
                $query->where('status', VideoStatus::PLANNED)
                    ->where('scheduled_at', '<=', now())
                    ->whereNotNull('uploaded_at');
            });
        })->whereHas('user', fn (QueryBuilder|Builder $q) => $q->active());
    }

    /**
     * Scope a query to only include public videos.
     */
    public function scopePublic(Builder $query, bool $includeAuthVideo = false): Builder
    {
        return $query->where(function (Builder $query) use ($includeAuthVideo) {
            $query->where(function (Builder $query) {
                $query->where('status', VideoStatus::PUBLIC)
                    ->whereNotNull('uploaded_at')
                    ->where(function (Builder $query) {
                        $query->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    });
            })->orWhere(function (Builder $query) {
                $query->where('status', VideoStatus::UNLISTED)
                    ->whereNotNull('uploaded_at');
            })->orWhere(function (Builder $query) use ($includeAuthVideo) {
                $query->where('status', VideoStatus::PLANNED)
                    ->whereNotNull('uploaded_at')
                    ->where(function (Builder $query) use ($includeAuthVideo) {
                        $query->where('scheduled_at', '<=', now())
                            ->when($includeAuthVideo, fn (Builder $query) => $query->orWhere('user_id', Auth::id()));
                    });
            });
        });
    }

    /**
     * Scope a query to only include not active videos.
     */
    public function scopeNotActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereIn('status', [VideoStatus::PRIVATE, VideoStatus::BANNED, VideoStatus::DRAFT, VideoStatus::FAILED])
                ->orWhere(function (Builder $query) {
                    $query->where('status', VideoStatus::PLANNED)
                        ->where('scheduled_at', '>', now());
                })
                ->orWhere(function (Builder $query) {
                    $query->where('status', VideoStatus::PUBLIC)
                        ->where('published_at', '>', now());
                })
                ->orWhereNull('uploaded_at');
        });
    }

    /**
     * Scope a query to filter videos by the current locale.
     *
     * - Videos with a language matching the locale are shown.
     * - Videos with no language (null) are shown on ALL locales.
     *
     * @param  string|null  $locale  Override locale (defaults to app locale)
     */
    public function scopeForLocale(Builder $query, ?string $locale = null): Builder
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale !== 'en' && $locale !== config('app.fallback_locale', 'en')) {
            return $query->where(function (Builder $outer) use ($locale) {
                $outer->where(function (Builder $imported) {
                    $imported->whereNotNull('avdb_id')->orWhereNotNull('xvid_id');
                })->orWhere(function (Builder $native) use ($locale) {
                    $native->whereNull('avdb_id')
                        ->whereNull('xvid_id')
                        ->where(function (Builder $q) use ($locale) {
                            $q->where('language', $locale)->orWhereNull('language');
                        });
                });
            });
        }

        return $query->where(function (Builder $q) use ($locale) {
            $q->where('language', $locale)
                ->orWhereNull('language');
        });
    }

    /**
     * Scope a query to only include valid videos.
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNot('status', VideoStatus::FAILED);
    }

    /**
     * Scope videos whose string-backed duration has no usable seconds value.
     */
    public function scopeMissingDuration(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('duration')
                ->orWhereIn('duration', ['', '0']);
        });
    }

    /**
     * Scope a query to only include valid videos.
     */
    public function scopeWithoutShorts(Builder $query): Builder
    {
        return $query->where('is_short', false);
    }

    /**
     * -------------------- LARAVEL SCOUT --------------------
     */

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'videos';
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->active()
            ->with([
                'user:id,username',
                'category:id,title',
                'tags:id,name,slug',
                'tags.translations',
            ]);
    }

    /**
     * Modify the collection of models being made searchable.
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models
            ->load([
                'user:id,username',
                'category:id,title',
                'tags:id,name,slug',
                'tags.translations',
            ]);
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category?->title,
            'actor' => $this->avdb_actor,
            'director' => $this->avdb_director,
            'tags' => $this->relationLoaded('tags')
                ? $this->tags
                    ->flatMap(function (Tag $tag): array {
                        $names = [$tag->name, $tag->slug];

                        if ($tag->relationLoaded('translations')) {
                            $names = array_merge($names, $tag->translations->pluck('name')->all());
                        }

                        return $names;
                    })
                    ->filter(fn ($name): bool => is_string($name) && trim($name) !== '')
                    ->map(fn (string $name): string => trim($name))
                    ->unique()
                    ->values()
                    ->all()
                : [],
            'user' => $this->user->username,
            'views' => $this->views,
            'duration' => (int) $this->getRawOriginal('duration'),
            'formated_duration' => $this->duration,
            'url' => $this->route,
            'thumbnail' => $this->thumbnail_url,
            'published_at' => $this->published_at->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_active;
    }
}
