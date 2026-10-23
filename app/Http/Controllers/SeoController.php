<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoTranslation;
use App\Services\SeoService;
use App\Support\SafeCache;
use App\Support\SeoSitemapCache;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeoController extends Controller
{
    private const SITEMAP_MAX_URLS = 50000;
    private const VIDEO_SITEMAP_MAX_URLS = 1000;

    public function robots(SeoService $seo): Response
    {
        $custom = trim((string) config('site.robots_txt_custom', ''));

        if ($custom !== '') {
            return response($custom, 200)->withHeaders($this->textHeaders());
        }

        $lines = [
            'User-agent: *',
            config('site.robots_noindex') ? 'Disallow: /' : 'Allow: /',
        ];

        if (! config('site.robots_noindex')) {
            $lines = array_merge($lines, [
                'Disallow: /admin/',
                'Disallow: /api/',
                'Disallow: /login',
                'Disallow: /register',
                'Disallow: /update-locale',
                'Disallow: /search',
                'Disallow: /*/search',
                'Disallow: /*/update-locale',
                'Crawl-delay: 2',
            ]);
        }

        if (config('site.sitemap_enabled', true)) {
            $lines[] = 'Sitemap: ' . url('/sitemap.xml');
        }

        return response(implode("\n", $lines) . "\n", 200)->withHeaders($this->textHeaders());
    }

    public function sitemap(): Response
    {
        $this->abortIfSitemapDisabled();

        $version = SeoSitemapCache::version();
        $cacheKey = 'seo.sitemap.index.' . $version;
        $locales = $this->activeLocales();
        $videoPageCounts = [];

        if (config('site.video_sitemap_enabled', true)) {
            foreach ($locales as $locale) {
                $videoPageCounts[$locale] = $this->videoSitemapPageCount($locale);
            }
        }

        $buildIndex = function () use ($locales, $videoPageCounts) {
            $items = [];

            foreach ($locales as $locale) {
                $items[] = [
                    'loc' => $this->baseUrl() . '/sitemap-pages-' . $locale . '.xml',
                    'lastmod' => $this->pagesLastmod(),
                ];
            }

            if (config('site.video_sitemap_enabled', true)) {
                $lastmod = $this->latestActiveVideoUpdatedAt();

                foreach ($locales as $locale) {
                    $pages = $videoPageCounts[$locale] ?? 0;
                    for ($page = 1; $page <= $pages; $page++) {
                        $items[] = [
                            'loc' => $this->baseUrl() . '/sitemap-videos-' . $locale . '-' . $page . '.xml',
                            'lastmod' => $lastmod,
                        ];
                    }
                }
            }

            $profileLastmod = $this->latestProfileUpdatedAt();
            $profilePages = $this->profileSitemapPageCount();
            foreach ($locales as $locale) {
                for ($page = 1; $page <= $profilePages; $page++) {
                    $items[] = [
                        'loc' => $this->baseUrl() . '/sitemap-profiles-' . $locale . '-' . $page . '.xml',
                        'lastmod' => $profileLastmod,
                    ];
                }
            }

            $out = $this->xmlDeclaration();
            $out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

            foreach ($items as $item) {
                $out .= '  <sitemap>' . "\n";
                $out .= '    <loc>' . $this->e($item['loc']) . '</loc>' . "\n";
                if (!empty($item['lastmod'])) {
                    $out .= '    <lastmod>' . $this->e($this->date($item['lastmod'])) . '</lastmod>' . "\n";
                }
                $out .= '  </sitemap>' . "\n";
            }

            $out .= '</sitemapindex>';

            return $out;
        };

        // Do not cache an index while any active locale has no visible videos.
        // A newly published or imported video can then appear immediately even
        // when its write path does not explicitly rotate the sitemap version.
        $hasEmptyVideoLocale = config('site.video_sitemap_enabled', true)
            && in_array(0, $videoPageCounts, true);
        $xml = $hasEmptyVideoLocale
            ? $buildIndex()
            : SafeCache::remember($cacheKey, SeoSitemapCache::ttl(), $buildIndex);

        return $this->xml($xml);
    }

    public function pagesSitemap(): Response
    {
        $this->abortIfSitemapDisabled();

        $locale = $this->requestedLocale();
        $version = SeoSitemapCache::version();
        $cacheKey = 'seo.sitemap.pages.' . ($locale ?: 'all') . '.' . $version;

        $xml = SafeCache::remember($cacheKey, SeoSitemapCache::ttl(), function () use ($locale) {
            $locales = $locale ? [$locale] : $this->activeLocales();

            $out = $this->xmlDeclaration();
            $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

            $activeLocales = $this->activeLocales();
            foreach ($this->pageEntries() as $entry) {
                $alternates = $this->alternateUrlsForPath($entry['path'], $activeLocales);

                foreach ($locales as $loc) {
                    $out .= $this->urlNode(
                        $this->localizedAbsoluteUrl($entry['path'], $loc),
                        $entry['lastmod'],
                        $alternates
                    );
                }
            }

            $out .= '</urlset>';

            return $out;
        });

        return $this->xml($xml);
    }

    public function videoSitemap(SeoService $seo): Response
    {
        $this->abortIfSitemapDisabled();

        if (!config('site.video_sitemap_enabled', true)) {
            abort(404);
        }

        $locale = $this->requestedLocale() ?: $this->defaultLocale();
        $page = $this->requestedPage();
        $limit = $this->sitemapLimit();
        $range = $this->requestedVideoRange($limit, $page);
        $activeLocales = $this->activeLocales();

        $videos = $this->videosVisibleInLocaleQuery($locale)
            ->select([
                'id',
                'uuid',
                'title',
                'slug',
                'description',
                'file',
                'duration',
                'views',
                'embed_code',
                'poster_url',
                'status',
                'scheduled_at',
                'published_at',
                'uploaded_at',
                'created_at',
                'updated_at',
                'user_id',
                'category_id',
                'language',
                'avdb_id',
            ])
            ->whereBetween('id', $range)
            ->limit($limit)
            ->with($this->videoSitemapRelations($locale))
            ->orderBy('id')
            ->get();

        $xml = $this->xmlDeclaration();
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

        foreach ($videos as $video) {
            $locales = $this->videoLocales($video, $activeLocales);
            $alternates = $this->videoAlternateUrls($seo, $video, $locales);

            if ($locale) {
                if (in_array($locale, $locales, true)) {
                    $xml .= $this->videoUrlNode($seo, $video, $locale, $alternates);
                }

                continue;
            }

            foreach ($locales as $videoLocale) {
                $xml .= $this->videoUrlNode($seo, $video, $videoLocale, $alternates);
            }
        }

        $xml .= '</urlset>';

        return $this->xml($xml);
    }

    public function profilesSitemap(): Response
    {
        $this->abortIfSitemapDisabled();

        $locale = $this->requestedLocale() ?: $this->defaultLocale();
        $page = $this->requestedPage();
        $limit = $this->sitemapLimit();
        $activeLocales = $this->activeLocales();
        $userIds = $this->profileSitemapUserIds($page, $limit);

        $users = User::query()
            ->select(['id', 'username', 'slug', 'profile_uid', 'updated_at'])
            ->active()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $xml = $this->xmlDeclaration();
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        foreach ($userIds as $userId) {
            $user = $users->get($userId);
            if (!$user) {
                continue;
            }

            $path = route('user.show', $user, false);
            $xml .= $this->urlNode(
                $this->localizedAbsoluteUrl($path, $locale),
                $user->updated_at,
                $this->alternateUrlsForPath($path, $activeLocales)
            );
        }

        $xml .= '</urlset>';

        return $this->xml($xml);
    }

    public function indexNowKey(string $key): Response
    {
        $configured = (string) config('site.indexnow_key', '');

        if (!config('site.indexnow_enabled') || $configured === '' || $key !== $configured) {
            abort(404);
        }

        return response($configured, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemapStylesheet(): Response
    {
        return response((string) file_get_contents(resource_path('views/seo/sitemap.xsl')), 200)->withHeaders([
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex, follow',
        ]);
    }

    private function abortIfSitemapDisabled(): void
    {
        if (!config('site.sitemap_enabled', true) || config('site.robots_noindex')) {
            abort(404);
        }
    }

    private function sitemapLimit(): int
    {
        return max(100, min(self::VIDEO_SITEMAP_MAX_URLS, (int) config('site.sitemap_video_limit', 1000)));
    }

    private function sourceVideoLimit(): int
    {
        $maxByLocaleCount = max(1, intdiv(self::SITEMAP_MAX_URLS, max(1, count($this->activeLocales()))));

        return max(1, min($this->sitemapLimit(), $maxByLocaleCount));
    }

    private function pagesLastmod(): mixed
    {
        return SafeCache::remember('seo.pages_sitemap_lastmod', SeoSitemapCache::ttl(), function () {
            return collect([
                $this->latestActiveVideoUpdatedAt(),
                Category::query()->max('updated_at'),
            ])->filter()->max();
        });
    }

    private function xml(string $xml): Response
    {
        $empty = $this->isEmptySitemapXml($xml);
        $headers = [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => $empty
                ? 'no-store, no-cache, max-age=0, must-revalidate'
                : 'public, max-age=1800, s-maxage=1800, stale-while-revalidate=86400',
            'X-Robots-Tag' => 'index, follow',
        ];

        if ($empty) {
            $headers['Pragma'] = 'no-cache';
            $headers['Expires'] = '0';
        }

        if ($empty) {
            $headers['X-Sitemap-Empty'] = '1';
        }

        return response($xml, 200)->withHeaders($headers);
    }

    private function isEmptySitemapXml(string $xml): bool
    {
        if (str_contains($xml, '<sitemapindex')) {
            return !str_contains($xml, '<sitemap>');
        }

        if (str_contains($xml, '<urlset')) {
            return !str_contains($xml, '<url>');
        }

        return false;
    }

    private function xmlDeclaration(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<?xml-stylesheet type="text/xsl" href="' . $this->e(url('/sitemap.xsl')) . '"?>' . "\n";
    }

    private function textHeaders(): array
    {
        return [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => 'index, follow',
        ];
    }

    private function activeVideoBounds(): array
    {
        $cacheKey = 'seo.active_video_id_bounds';
        return SafeCache::remember($cacheKey, SeoSitemapCache::ttl(), function () {
            $bounds = $this->fastActiveVideoTableQuery()
                ->selectRaw('MIN(videos.id) as min_id, MAX(videos.id) as max_id')
                ->first();

            return [
                'min' => (int) ($bounds?->min_id ?? 0),
                'max' => (int) ($bounds?->max_id ?? 0),
            ];
        });
    }

    private function latestActiveVideoUpdatedAt(): mixed
    {
        return SafeCache::remember('seo.latest_active_video_updated_at', SeoSitemapCache::ttl(), function () {
            return $this->fastActiveVideoTableQuery()->max('videos.updated_at');
        });
    }

    private function videoSitemapRanges(?int $limit = null): array
    {
        $limit = $limit ?: $this->sitemapLimit();
        $bounds = $this->activeVideoBounds();

        if ($bounds['min'] <= 0 || $bounds['max'] <= 0 || $bounds['min'] > $bounds['max']) {
            return [];
        }

        $lastmod = $this->latestActiveVideoUpdatedAt();
        $ranges = [];
        for ($from = $bounds['min']; $from <= $bounds['max']; $from += $limit) {
            $to = min($bounds['max'], $from + $limit - 1);
            $ranges[] = [
                'from' => $from,
                'to' => $to,
                'lastmod' => $lastmod,
            ];
        }

        return $ranges;
    }

    private function videoSitemapPageCount(?string $locale = null, ?int $limit = null): int
    {
        // Keep the zero check uncached. Importers and other write paths are not
        // all guaranteed to rotate the sitemap cache version immediately.
        if ($locale !== null && ! $this->videosVisibleInLocaleQuery($locale)->exists()) {
            return 0;
        }

        $limit = $limit ?: $this->sitemapLimit();
        $cacheKey = implode('.', [
            'seo.video_sitemap_page_count',
            'ranges',
            $limit,
            SeoSitemapCache::version(),
        ]);

        return SafeCache::remember($cacheKey, SeoSitemapCache::ttl(), function () use ($limit) {
            $bounds = $this->activeVideoBounds();
            if ($bounds['min'] <= 0 || $bounds['max'] < $bounds['min']) {
                return 0;
            }

            return max(1, (int) ceil((($bounds['max'] - $bounds['min']) + 1) / $limit));
        });
    }

    private function profileSitemapPageCount(?string $locale = null): int
    {
        return SafeCache::remember(
            'seo.profile_sitemap_page_count.fast.' . SeoSitemapCache::version(),
            SeoSitemapCache::ttl(),
            function () {
                $count = $this->fastActiveVideoTableQuery()
                    ->whereNotNull('videos.user_id')
                    ->distinct()
                    ->count('videos.user_id');

                return max(1, (int) ceil($count / $this->sitemapLimit()));
            }
        );
    }

    private function latestProfileUpdatedAt(): mixed
    {
        return SafeCache::remember('seo.latest_profile_updated_at', SeoSitemapCache::ttl(), function () {
            return User::query()->active()->max('updated_at');
        });
    }

    private function profileSitemapUserIds(int $page, int $limit): array
    {
        return $this->fastActiveVideoTableQuery()
            ->whereNotNull('videos.user_id')
            ->select('videos.user_id')
            ->distinct()
            ->orderBy('videos.user_id')
            ->forPage($page, $limit)
            ->pluck('videos.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function requestedVideoRange(int $limit, ?int $page = null): array
    {
        $from = max(0, (int) request('from', 0));
        $to = max(0, (int) request('to', 0));

        if ($from > 0 && $to >= $from) {
            return [$from, min($to, $from + $limit - 1)];
        }

        $bounds = $this->activeVideoBounds();
        $page = max(1, $page ?: (int) request('page', 1));
        $from = max(1, $bounds['min'] + (($page - 1) * $limit));

        return [$from, $from + $limit - 1];
    }

    private function requestedPage(?int $page = null): int
    {
        $routePage = request()->route('page');

        return max(1, (int) ($routePage ?: $page ?: request('page', 1)));
    }

    private function requestedLocale(): ?string
    {
        $locale = request()->route('locale');

        if (!$locale) {
            return null;
        }

        $locale = (string) $locale;
        if (!in_array($locale, $this->activeLocales(), true)) {
            abort(404);
        }

        return $locale;
    }

    /**
     * @return array<int, array{path: string, lastmod: mixed}>
     */
    private function pageEntries(): array
    {
        $latestVideoUpdate = $this->latestActiveVideoUpdatedAt();
        $entries = [
            ['path' => '/', 'lastmod' => $latestVideoUpdate],
            ['path' => route('pages.trend', [], false), 'lastmod' => $latestVideoUpdate],
            ['path' => route('pages.terms', [], false), 'lastmod' => null],
            ['path' => route('contact.show', [], false), 'lastmod' => null],
        ];

        Category::query()
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function (Category $category) use (&$entries) {
                $entries[] = [
                    'path' => route('pages.category', $category->slug, false),
                    'lastmod' => $category->updated_at,
                ];
            });

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function activeLocales(): array
    {
        static $locales = null;

        if ($locales !== null) {
            return $locales;
        }

        $configured = array_keys(config('languages', []));
        $configured = array_filter($configured, fn ($locale) => preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale));

        $default = $this->defaultLocale();
        $locales = array_values(array_unique(array_filter(array_merge([$default], $configured))));

        return $locales;
    }

    private function defaultLocale(): string
    {
        return (string) config('site.default_locale', config('app.locale', 'en'));
    }

    /**
     * @return array<int, string>
     */
    private function videoLocales(Video $video, array $activeLocales): array
    {
        if ($video->avdb_id) {
            return $activeLocales;
        }

        if ($video->language && in_array($video->language, $activeLocales, true)) {
            return [$video->language];
        }

        if ($video->language) {
            return [$this->defaultLocale()];
        }

        return $activeLocales;
    }

    private function videoSitemapRelations(string $locale): array
    {
        $relations = [
            'user:id,username,slug,profile_uid',
            'category:id,title',
        ];

        if ($locale !== 'en' && $locale !== config('app.fallback_locale', 'en')) {
            $relations['translations'] = fn ($query) => $query
                ->select(['id', 'video_id', 'locale', 'title', 'description', 'country', 'type_name'])
                ->where('locale', $locale);
        }

        return $relations;
    }

    private function videosVisibleInLocaleQuery(?string $locale = null): Builder
    {
        return $this->applyVideoLocaleVisibility($this->fastActiveVideoEloquentQuery(), $locale);
    }

    private function applyVideoLocaleVisibility(Builder $query, ?string $locale = null): Builder
    {
        if (!$locale || $locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($locale) {
            $outer->where(function (Builder $avdb) {
                $avdb->whereNotNull('avdb_id');
            })
                ->orWhere(function (Builder $native) use ($locale) {
                    $native->whereNull('avdb_id')
                        ->where(function (Builder $language) use ($locale) {
                            $language->where('language', $locale)
                                ->orWhereNull('language');
                        });
                });
        });
    }

    private function fastActiveVideoEloquentQuery(): Builder
    {
        return $this->applyFastActiveVideoFilters(Video::query());
    }

    private function fastActiveVideoTableQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->applyFastActiveVideoFilters(DB::table('videos'));
    }

    private function applyFastActiveVideoFilters(Builder|\Illuminate\Database\Query\Builder $query): Builder|\Illuminate\Database\Query\Builder
    {
        return $query
            ->whereNull('videos.deleted_at')
            ->whereNotNull('videos.uploaded_at')
            ->where(function (Builder|\Illuminate\Database\Query\Builder $query): void {
                $query->where(function (Builder|\Illuminate\Database\Query\Builder $query): void {
                    $query->where('videos.status', VideoStatus::PUBLIC->value)
                        ->where(function (Builder|\Illuminate\Database\Query\Builder $query): void {
                            $query->whereNull('videos.published_at')
                                ->orWhere('videos.published_at', '<=', now());
                        });
                })->orWhere(function (Builder|\Illuminate\Database\Query\Builder $query): void {
                    $query->where('videos.status', VideoStatus::PLANNED->value)
                        ->where('videos.scheduled_at', '<=', now());
                });
            });
    }

    /**
     * @return array<string, string>
     */
    private function alternateUrlsForPath(string $path, array $locales): array
    {
        $alternates = [];

        foreach ($locales as $locale) {
            $alternates[$this->hreflang($locale)] = $this->localizedAbsoluteUrl($path, $locale);
        }

        $default = in_array($this->defaultLocale(), $locales, true) ? $this->defaultLocale() : ($locales[0] ?? $this->defaultLocale());
        $alternates['x-default'] = $this->localizedAbsoluteUrl($path, $default);

        return $alternates;
    }

    /**
     * @return array<string, string>
     */
    private function videoAlternateUrls(SeoService $seo, Video $video, array $locales): array
    {
        return $this->alternateUrlsForPath($seo->videoPath($video), $locales);
    }

    private function baseUrl(): string
    {
        static $base = null;
        if ($base === null) {
            $base = rtrim(url('/'), '/');
        }

        return $base;
    }

    private function localizedAbsoluteUrl(string $path, string $locale): string
    {
        $path = '/' . ltrim($path, '/');
        $path = $path === '//' ? '/' : $path;

        if ($locale === $this->defaultLocale()) {
            return url($path);
        }

        return url('/' . $locale . ($path === '/' ? '' : $path));
    }

    private function urlNode(string $loc, mixed $lastmod = null, array $alternates = []): string
    {
        $xml = '  <url>' . "\n";
        $xml .= '    <loc>' . $this->e($loc) . '</loc>' . "\n";

        if ($lastmod) {
            $xml .= '    <lastmod>' . $this->e($this->date($lastmod)) . '</lastmod>' . "\n";
        }

        foreach ($alternates as $hreflang => $href) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->e($hreflang) . '" href="' . $this->e($href) . '"/>' . "\n";
        }

        $xml .= '  </url>' . "\n";

        return $xml;
    }

    private function videoUrlNode(SeoService $seo, Video $video, string $locale, array $alternates): string
    {
        $loc = $this->localizedAbsoluteUrl($seo->videoPath($video), $locale);
        $translation = $this->videoTranslation($video, $locale);
        $title = $translation?->title ?: $video->getRawOriginal('title');
        $description = trim(strip_tags((string) ($translation?->description ?: $video->getRawOriginal('description'))));
        $description = $description !== '' ? Str::limit($description, 512, '') : $title;

        $xml = '  <url>' . "\n";
        $xml .= '    <loc>' . $this->e($loc) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . $this->e($this->date($video->updated_at)) . '</lastmod>' . "\n";

        foreach ($alternates as $hreflang => $href) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->e($hreflang) . '" href="' . $this->e($href) . '"/>' . "\n";
        }

        $xml .= '    <video:video>' . "\n";
        $xml .= '      <video:thumbnail_loc>' . $this->e(site_asset_url($video->thumbnail_url)) . '</video:thumbnail_loc>' . "\n";
        $xml .= '      <video:title>' . $this->cdata(Str::limit($title, 100, '')) . '</video:title>' . "\n";
        $xml .= '      <video:description>' . $this->cdata($description) . '</video:description>' . "\n";

        if ($video->file_url) {
            $xml .= '      <video:content_loc>' . $this->e($video->file_url) . '</video:content_loc>' . "\n";
        }

        $xml .= '      <video:player_loc allow_embed="yes">' . $this->e($seo->videoPlayerUrl($video)) . '</video:player_loc>' . "\n";

        $duration = (int) $video->getRawOriginal('duration');
        if ($duration > 0 && $duration <= 28800) {
            $xml .= '      <video:duration>' . $duration . '</video:duration>' . "\n";
        }

        $publicationDate = $this->videoPublicationDate($video);
        if ($publicationDate) {
            $xml .= '      <video:publication_date>' . $this->e($this->date($publicationDate)) . '</video:publication_date>' . "\n";
        }

        $category = $translation?->type_name ?: ($video->category?->title ? decode_html_entities($video->category->title) : null);
        if ($category) {
            $xml .= '      <video:category>' . $this->cdata($category) . '</video:category>' . "\n";
        }

        if ((int) $video->views > 0) {
            $xml .= '      <video:view_count>' . (int) $video->views . '</video:view_count>' . "\n";
        }

        $xml .= '      <video:family_friendly>no</video:family_friendly>' . "\n";
        $xml .= '    </video:video>' . "\n";
        $xml .= '  </url>' . "\n";

        return $xml;
    }

    private function videoTranslation(Video $video, string $locale): ?VideoTranslation
    {
        if ($locale === 'en' || $locale === config('app.fallback_locale', 'en')) {
            return null;
        }

        return $video->translations->firstWhere('locale', $locale);
    }

    private function videoPublicationDate(Video $video): mixed
    {
        if ($video->published_at && $video->published_at->lte(now())) {
            return $video->published_at;
        }

        return $video->uploaded_at ?: $video->created_at;
    }

    private function date(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toAtomString();
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->toAtomString();
        }

        return optional($value)->toAtomString() ?: (string) $value;
    }

    private function hreflang(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    private function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function cdata(string $value): string
    {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }
}
