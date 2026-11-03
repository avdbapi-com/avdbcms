<?php

namespace App\Http\Controllers;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\ContentSafety;
use App\Support\RobotsTxt;
use App\Support\SafeCache;
use App\Support\SeoSitemapCache;
use App\Support\VideoLanguages;
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

    public function robots(): Response
    {
        // The text the site also writes into public/robots.txt (App\Support\RobotsTxt, round 4 R3M-02), which web servers
        // serve before this route: the route answers only where that file is missing and the server hands /robots.txt on.
        return response(RobotsTxt::text(), 200)->withHeaders($this->textHeaders());
    }

    public function sitemap(): Response
    {
        $this->abortIfSitemapDisabled();

        $version = SeoSitemapCache::version();
        // Per address form (clean or /index.php/...): a site that switches keeps no sitemap of the other form for the
        // cache's half hour (round 1b review BWR-01).
        $cacheKey = 'seo.sitemap.index.' . $version . '.' . (index_php_links() ? 'i' : 'c');
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

            // The channel pages are offered in the languages whose page is its own canonical: the default language only
            // (VideoLanguages::channelCanonicalIn(); 1.2.3 refresh, CLF-SEO-01: every language's profile sitemap listed
            // every channel while each of those pages named the default-language page as its canonical).
            $profileLastmod = $this->latestProfileUpdatedAt();
            $profilePages = $this->profileSitemapPageCount();
            foreach (VideoLanguages::channelCanonicalIn() as $locale) {
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
        $cacheKey = 'seo.sitemap.pages.' . ($locale ?: 'all') . '.' . $version . '.' . (index_php_links() ? 'i' : 'c');

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
            // the translations this sitemap reads are its own (videoSitemapRelations()), whatever the request's language
            ->withoutGlobalScope('currentTranslation')
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
        // 1.2.3 round 3 (R3-017): the languages of the AVDBAPI videos' translations, one query for the page
        $translatedIn = VideoLanguages::translatedIn($videos);

        foreach ($videos as $video) {
            $locales = $this->videoLocales($video, $activeLocales, $translatedIn[(int) $video->id] ?? null);
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
        // The languages whose channel page is its own canonical (VideoLanguages::channelCanonicalIn(): the default
        // language only; 1.2.3 refresh, CLF-SEO-01): the channels are listed there, each with that page as its one
        // alternate; another language's profile sitemap lists nothing (the index names none; a page in that language
        // names the default-language page as its canonical), like a video sitemap of a language with no such page.
        $channelLocales = VideoLanguages::channelCanonicalIn();
        $userIds = in_array($locale, $channelLocales, true) ? $this->profileSitemapUserIds($page, $limit) : [];

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

            $path = site_path(route('user.show', $user, false));
            $xml .= $this->urlNode(
                $this->localizedAbsoluteUrl($path, $locale),
                $user->updated_at,
                $this->alternateUrlsForPath($path, $channelLocales)
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
        if ($locale !== null && ! $this->videosIndexableInLocale($locale)) {
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
            // paths inside the site (without the folder of a site installed in one: url() puts it in front)
            ['path' => site_path(route('pages.trend', [], false)), 'lastmod' => $latestVideoUpdate],
            ['path' => site_path(route('pages.terms', [], false)), 'lastmod' => null],
            ['path' => site_path(route('contact.show', [], false)), 'lastmod' => null],
        ];

        // 1.2.3: no category named with a word that is always blocked (App\Support\ContentSafety)
        \App\Support\ContentSafety::visibleCategories(Category::query()
            ->orderBy('id')
            ->get(['slug', 'title', 'updated_at']))
            ->each(function (Category $category) use (&$entries) {
                $entries[] = [
                    'path' => site_path(route('pages.category', $category->slug, false)),
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
     * @param  list<string>|null  $translatedIn  the languages of the video's stored translations (VideoLanguages::translatedIn())
     * @return array<int, string>
     */
    private function videoLocales(Video $video, array $activeLocales, ?array $translatedIn = null): array
    {
        // The languages whose page names itself as its canonical (App\Support\VideoLanguages::canonicalIn(), the rule
        // of the watch page's canonical and hreflang links): an AVDBAPI import where its page is indexable (1.2.3 round
        // 3 R3-017: English, the default language, its translations; round 4 R3M-01: that page is its own canonical),
        // every other video in the default language only (1.2.3 refresh, CLF-SEO-01: its page in another language
        // names the default-language page, so every other language's sitemap offered pages that canonicalize away).
        return VideoLanguages::canonicalIn($video, $activeLocales, $translatedIn);
    }

    private function videoSitemapRelations(string $locale): array
    {
        $relations = [
            'user:id,username,slug,profile_uid',
            'category:id,title',
        ];

        // The stored translation of the sitemap's language and the English one, which the watch page falls back on for the
        // type (Video::titleIn(), descriptionIn(), typeNameIn() read these, no query per video).
        $relations['translations'] = fn ($query) => $query
            ->select(['id', 'video_id', 'locale', 'title', 'description', 'country', 'type_name'])
            ->whereIn('locale', array_values(array_unique([$locale, 'en'])));

        return $relations;
    }

    private function videosVisibleInLocaleQuery(?string $locale = null): Builder
    {
        return $this->applyVideoLocaleVisibility($this->fastActiveVideoEloquentQuery(), $locale);
    }

    /**
     * Whether videosVisibleInLocaleQuery($locale) has a video: the index's per-language check, on every request (see
     * videoSitemapPageCount()). The same videos, read as two EXISTS that each stop at the first one (1.2.3 round 3,
     * R3-017): the videos without an AVDBAPI number (avdb_id, its unique index) and the AVDBAPI imports with their
     * translation of the language (the translations' (locale, video_id) index). One EXISTS with the translation lookup
     * per video read every imported video of the site for a language none of them is translated into, on each request.
     */
    private function videosIndexableInLocale(string $locale): bool
    {
        // The same videos as videosVisibleInLocaleQuery($locale) (VideoLanguages::constrainCanonical()): every video in
        // the default language; in another language the AVDBAPI imports whose page is indexable there (1.2.3 refresh,
        // CLF-SEO-01: a member's upload or another source's import is listed in the default language only, where its
        // page is its own canonical).
        if ($locale === $this->defaultLocale()) {
            return $this->fastActiveVideoTableQuery()->exists();
        }

        if (! VideoLanguages::needsTranslation($locale)) {
            // English on a site whose default language is another one: an import's English page needs no translation
            return $this->fastActiveVideoTableQuery()->where('videos.avdb_id', '>', 0)->exists();
        }

        return $this->applyFastActiveVideoFilters(
            DB::table('video_translations')->join('videos', 'videos.id', '=', 'video_translations.video_id')
        )
            ->where('video_translations.locale', $locale)
            ->where('videos.avdb_id', '>', 0)
            ->exists();
    }

    private function applyVideoLocaleVisibility(Builder $query, ?string $locale = null): Builder
    {
        if (!$locale) {
            return $query;
        }

        // The videos whose page in this language is its own canonical (App\Support\VideoLanguages::constrainCanonical():
        // every video in the default language; elsewhere an AVDBAPI import where its page is indexable, 1.2.3 round 3
        // R3-017 and the decision of 29 Oct): the index lists no sitemap of a language where no such page is, and a
        // sitemap no page that answers noindex or names another page as its canonical (1.2.3 refresh, CLF-SEO-01).
        VideoLanguages::constrainCanonical($query, $locale);

        return $query;
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

    /** The site root of this request's sitemap links, worked out once per controller (that is, per request). */
    private ?string $baseUrl = null;

    private function baseUrl(): string
    {
        // Kept on the controller, not in a function-level static: a static outlives the request in a long-running
        // worker (and in the test suite), so the first request's site address would end up in every later sitemap.
        return $this->baseUrl ??= rtrim(url('/'), '/');
    }

    private function localizedAbsoluteUrl(string $path, string $locale): string
    {
        $path = '/' . ltrim($path, '/');
        $path = $path === '//' ? '/' : $path;
        // route(..., false) already carries /index.php on a site whose links keep it, and url() adds it again in front
        // of the language: /index.php/fr/index.php/trend (R1B-007). The path goes in without it.
        if ($path === '/index.php' || str_starts_with($path, '/index.php/')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
        }

        if ($locale === $this->defaultLocale()) {
            $url = url($path);

            // the home page of a site installed in a folder: the folder with its slash, as the page's canonical (R2R-05)
            return $path === '/' && site_folder() !== '' && str_ends_with($url, site_folder()) ? $url.'/' : $url;
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
        // 1.2.3 round 3 (R3-003): the title, description and type the watch page shows in this language (Video::titleIn()
        // and the others: never a stored translation that uses a word that is always blocked, App\Support\ContentSafety,
        // the video's own text instead); a description that still uses such a word is left out (the title stands in)
        $title = $video->titleIn($locale);
        $description = trim(strip_tags((string) $video->descriptionIn($locale)));
        $description = $description !== '' && ContentSafety::hardMatch($description) === null ? Str::limit($description, 512, '') : $title;

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

        // never a category visitors never see (its name uses a word that is always blocked): the video is listed without it
        $category = $video->typeNameIn($locale)
            ?: ($video->category?->title && ! ContentSafety::hides((string) $video->category->title) ? decode_html_entities($video->category->title) : null);
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
