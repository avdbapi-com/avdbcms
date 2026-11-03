<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\ContentSafety;
use App\Support\SeoSitemapCache;
use App\Support\SiteAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 1.2.3 refresh, CLF-SEO-01 (seen on the demo sites after the rollout): every language's video sitemap listed every
 * video that is not an AVDBAPI import (a member's upload, another source's import) with 18 language alternates, while
 * each of those /<lang>/video/... pages names the default-language page as its canonical (round 4 R3M-01 gave an
 * AVDBAPI import's translated page its own canonical; every other page kept the default-language one, as in 1.2.2):
 * 16 x 694 sitemap entries on clfcms.com pointed search engines at pages that point elsewhere.
 *
 * The rule (App\Support\VideoLanguages::canonicalIn(), the sitemaps and the watch page's hreflang links follow it): a
 * video is offered in the languages whose page is its own canonical - an AVDBAPI import where it is indexable (as
 * before), any other video in the site's default language only. Its page in another language still opens (the lists
 * show it there) and still names the default-language page. Proved at a domain's root, with /index.php addresses, in a
 * folder and in a folder with /index.php (lane SB's locale_url() rules), and on a site whose default language is not
 * English.
 *
 * Test data: neutral titles.
 */
class SitemapCanonicalLanguagesTest extends TestCase
{
    use RefreshDatabase;

    private const MARKS = ['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX', 'AVDBCLIP_USED_INDEX_PHP', 'AVDBCLIP_ROOT_FALLBACK', 'REQUEST_URI'];

    /** @var array<string, mixed> */
    private array $server = [];

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MARKS as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }

        Cache::flush();
        ContentSafety::forget();
        SeoSitemapCache::clear();
        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'app.fallback_locale' => 'en', 'cache.limiter' => 'array',
            'site.canonical_enabled' => true, 'site.hreflang_enabled' => true, 'site.sitemap_enabled' => true, 'site.video_sitemap_enabled' => true]);
        $this->creator = User::factory()->create(['email_verified_at' => now(), 'username' => 'qa_sitemap_channel']);
    }

    protected function tearDown(): void
    {
        foreach ($this->server as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        URL::forceRootUrl(null);
        app('url')->formatPathUsing(fn ($path) => $path);
        app()->setLocale('en');

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool}> origin, base, script, folder, index.php links */
    public static function layouts(): array
    {
        return [
            'at a domain\'s root' => ['http://localhost', '', '/index.php', false, false],
            'with /index.php addresses' => ['http://localhost', '/index.php', '/index.php', false, true],
            'in a folder' => ['https://example.test', '/site', '/site/index.php', true, false],
            'in a folder with /index.php addresses' => ['https://example.test', '/site/index.php', '/site/index.php', true, true],
        ];
    }

    #[DataProvider('layouts')]
    public function test_a_video_without_translations_is_offered_in_the_default_language_only(string $origin, string $base, string $script, bool $folder, bool $indexPhp): void
    {
        $folder ? $this->siteInFolder() : $this->siteAtRoot();

        if ($indexPhp) {
            $this->indexPhpLinks();
        }

        $seo = app(SeoService::class);
        $videos = [
            'a member\'s upload' => $this->video('QA member upload'),
            'a member\'s upload in French' => $this->video('QA member upload in French', ['language' => 'fr']),
            'another source\'s import' => $this->video('QA other source import', ['xvid_id' => 4243, 'language' => 'en']),
        ];

        foreach ($videos as $what => $video) {
            $path = $seo->videoPath($video);
            $home = $origin.$base.$path;

            // the default language's sitemap lists its default-language page, with that page as its only alternate
            $this->assertSame($home, $this->sitemapLoc($origin, $base, $script, 'en', $video), $what.': the English sitemap lists the default-language page');
            $this->assertSame(['en' => $home, 'x-default' => $home], $this->sitemapAlternates($origin, $base, $script, 'en', $video), $what.': no other page as an alternate');

            // no other language's sitemap lists it (its page there names the default-language page as its canonical)
            foreach (['fr', 'de'] as $locale) {
                $this->assertNull($this->sitemapLoc($origin, $base, $script, $locale, $video), $what.': not in the '.$locale.' sitemap');
            }

            // its pages: every one opens, is indexable, names the default-language page and offers that page only
            foreach (['', '/fr', '/de'] as $prefix) {
                $page = $this->page($origin, $base.$prefix.$path, $script);
                $this->assertSame(200, $page->getStatusCode(), $what.' '.($prefix ?: 'default language'));
                $this->assertNull($page->headers->get('X-Robots-Tag'), $what.' '.($prefix ?: 'default language').': indexable');
                $html = (string) $page->getContent();
                $this->assertSame($home, $this->canonical($html), $what.' '.($prefix ?: 'default language').': the canonical');
                $this->assertSame(['en' => $home, 'x-default' => $home], $this->alternates($html), $what.' '.($prefix ?: 'default language').': the language links');
                $this->assertStringNotContainsString('/site/site/', $html);
            }
        }

        // an AVDBAPI import with a French translation is as in round 4 (R3M-01): its French page is its own canonical,
        // listed in the French sitemap, named by both pages' alternates; its German page answers noindex and is listed nowhere
        $import = $this->translatedImport();
        $path = $seo->videoPath($import);
        $home = $origin.$base.$path;
        $french = $origin.$base.'/fr'.$path;
        $this->assertSame($french, $this->sitemapLoc($origin, $base, $script, 'fr', $import));
        $this->assertSame($home, $this->sitemapLoc($origin, $base, $script, 'en', $import));
        $this->assertNull($this->sitemapLoc($origin, $base, $script, 'de', $import));
        $this->assertSame(['en' => $home, 'fr' => $french, 'x-default' => $home], $this->sitemapAlternates($origin, $base, $script, 'fr', $import));
        $this->assertSame($french, $this->canonical($this->html($origin, $base.'/fr'.$path, $script)));
        $this->assertSame(['en' => $home, 'fr' => $french, 'x-default' => $home], $this->alternates($this->html($origin, $base.$path, $script)));
        $this->assertSame('noindex, follow', $this->page($origin, $base.'/de'.$path, $script)->headers->get('X-Robots-Tag'));
    }

    /**
     * The same rule for the channel pages (/@name; second pass, verifier WEB-V1-01): users/show.blade.php names
     * $user->route, the default-language address, as the canonical in every language, so a channel is listed in the
     * default language's profile sitemap only, with that page as its one alternate, and its page in every language
     * offers that page only. Up to 1.2.3 every language's profile sitemap listed every channel with 18 alternates
     * (sitemap-profiles-fr-1.xml named /fr/@admin while that page's canonical was /@admin).
     */
    #[DataProvider('layouts')]
    public function test_a_channel_page_is_offered_in_the_default_language_only(string $origin, string $base, string $script, bool $folder, bool $indexPhp): void
    {
        $folder ? $this->siteInFolder() : $this->siteAtRoot();

        if ($indexPhp) {
            $this->indexPhpLinks();
        }

        // a channel of a member's upload and of a translated AVDBAPI import alike: the page's canonical is the same
        $this->video('QA member upload');
        $this->translatedImport();
        $path = $this->channelPath();
        $home = $origin.$base.$path;

        // the default language's profile sitemap lists the default-language page, with that page as its only alternate
        $this->assertSame($home, $this->profileSitemapLoc($origin, $base, $script, 'en'), 'the English profile sitemap lists the default-language page');
        $this->assertSame(['en' => $home, 'x-default' => $home], $this->profileSitemapAlternates($origin, $base, $script, 'en'), 'no other page as an alternate');

        // no other language's profile sitemap lists it (its page there names the default-language page as its canonical)
        foreach (['fr', 'de'] as $locale) {
            $this->assertNull($this->profileSitemapLoc($origin, $base, $script, $locale), 'not in the '.$locale.' profile sitemap');
            $this->assertStringNotContainsString('<url>', $this->profileSitemap($origin, $base, $script, $locale), 'the '.$locale.' profile sitemap lists nothing');
        }

        // its pages: every one opens, is indexable, names the default-language page and offers that page only
        foreach (['', '/fr', '/de'] as $prefix) {
            $page = $this->page($origin, $base.$prefix.$path, $script);
            $this->assertSame(200, $page->getStatusCode(), 'the channel page '.($prefix ?: 'in the default language'));
            $this->assertNull($page->headers->get('X-Robots-Tag'), ($prefix ?: 'default language').': indexable');
            $html = (string) $page->getContent();
            $this->assertSame($home, $this->canonical($html), ($prefix ?: 'default language').': the canonical');
            $this->assertSame(['en' => $home, 'x-default' => $home], $this->alternates($html), ($prefix ?: 'default language').': the language links');
            $this->assertStringNotContainsString('/site/site/', $html);
        }

        // the index names the default language's profile sitemap only
        $index = (string) $this->page($origin, $base.'/sitemap.xml', $script)->getContent();
        $this->assertStringContainsString($origin.$base.'/sitemap-profiles-en-1.xml', $index);
        $this->assertSame(1, preg_match_all('#/sitemap-profiles-[a-z]{2}-#', $index), 'one language\'s profile sitemaps');
    }

    public function test_on_a_site_whose_default_language_is_not_english_the_channel_pages_follow_it(): void
    {
        $this->siteAtRoot();
        config(['site.default_locale' => 'vi', 'app.locale' => 'vi', 'app.fallback_locale' => 'vi']);
        app()->setLocale('vi');
        $this->video('QA member upload');
        $path = $this->channelPath();

        // the Vietnamese (default-language, no prefix) page only, in the Vietnamese profile sitemap only
        $this->assertSame('http://localhost'.$path, $this->profileSitemapLoc('http://localhost', '', '/index.php', 'vi'));
        $this->assertSame(['vi' => 'http://localhost'.$path, 'x-default' => 'http://localhost'.$path], $this->profileSitemapAlternates('http://localhost', '', '/index.php', 'vi'));
        $this->assertNull($this->profileSitemapLoc('http://localhost', '', '/index.php', 'en'), 'its English page names the Vietnamese page');
        $this->assertNull($this->profileSitemapLoc('http://localhost', '', '/index.php', 'fr'));

        $html = $this->html('http://localhost', '/en'.$path);
        $this->assertSame('http://localhost'.$path, $this->canonical($html));
        $this->assertSame(['vi' => 'http://localhost'.$path, 'x-default' => 'http://localhost'.$path], $this->alternates($html));

        $index = (string) $this->page('http://localhost', '/sitemap.xml')->getContent();
        $this->assertStringContainsString(url('/sitemap-profiles-vi-1.xml'), $index);
        $this->assertStringNotContainsString('/sitemap-profiles-en-', $index);
        $this->assertStringNotContainsString('/sitemap-profiles-fr-', $index);
    }

    public function test_the_index_names_no_video_sitemap_of_a_language_whose_pages_all_point_elsewhere(): void
    {
        $this->siteAtRoot();
        $this->video('QA member upload');
        $this->video('QA member upload in French', ['language' => 'fr']);
        $this->video('QA other source import', ['xvid_id' => 4244]);

        $index = (string) $this->get(route('seo.sitemap'))->assertOk()->getContent();
        $this->assertStringContainsString(url('/sitemap-videos-en-1.xml'), $index);

        foreach (['fr', 'de', 'vi'] as $locale) {
            $this->assertStringNotContainsString(url('/sitemap-videos-'.$locale.'-'), $index, $locale.': no sitemap of pages that canonicalize away');
            $this->assertStringNotContainsString('<url>', (string) $this->get(route('seo.sitemap.videos.locale.page', ['locale' => $locale, 'page' => 1]))->assertOk()->getContent(), $locale.': its sitemap lists nothing');
            $this->assertStringNotContainsString(url('/sitemap-profiles-'.$locale.'-'), $index, $locale.': no profile sitemap of pages that canonicalize away');
        }

        $this->assertStringContainsString(url('/sitemap-profiles-en-1.xml'), $index);

        // the pages sitemaps of every language stay (the lists show every video in every language)
        $this->assertStringContainsString(url('/sitemap-pages-fr.xml'), $index);

        // an import's French translation brings the French video sitemap, with that one entry
        $this->translatedImport();
        SeoSitemapCache::clear();
        Cache::flush();
        $index = (string) $this->get(route('seo.sitemap'))->assertOk()->getContent();
        $this->assertStringContainsString(url('/sitemap-videos-fr-1.xml'), $index);
        $this->assertStringNotContainsString(url('/sitemap-videos-de-'), $index);
        $this->assertSame(1, substr_count((string) $this->get(route('seo.sitemap.videos.locale.page', ['locale' => 'fr', 'page' => 1]))->assertOk()->getContent(), '<url>'));
    }

    public function test_on_a_site_whose_default_language_is_not_english_the_default_language_is_the_one(): void
    {
        $this->siteAtRoot();
        config(['site.default_locale' => 'vi', 'app.locale' => 'vi', 'app.fallback_locale' => 'vi']);
        app()->setLocale('vi');
        $seo = app(SeoService::class);
        $upload = $this->video('QA member upload');
        $import = $this->video('QA import without translations', ['avdb_id' => 77791]);
        $uploadPath = $seo->videoPath($upload);
        $importPath = $seo->videoPath($import);

        // the member's upload: the Vietnamese (default-language, no prefix) page only, in the Vietnamese sitemap only
        $this->assertSame('http://localhost'.$uploadPath, $this->sitemapLoc('http://localhost', '', '/index.php', 'vi', $upload));
        $this->assertNull($this->sitemapLoc('http://localhost', '', '/index.php', 'en', $upload), 'its English page names the Vietnamese page');
        $this->assertNull($this->sitemapLoc('http://localhost', '', '/index.php', 'fr', $upload));
        $this->assertSame(['vi' => 'http://localhost'.$uploadPath, 'x-default' => 'http://localhost'.$uploadPath], $this->sitemapAlternates('http://localhost', '', '/index.php', 'vi', $upload));

        // the import: indexable in Vietnamese (the default) and in English (its own texts), nowhere else: listed in both,
        // and its English page is its own canonical (R3M-01)
        $this->assertSame('http://localhost'.$importPath, $this->sitemapLoc('http://localhost', '', '/index.php', 'vi', $import));
        $this->assertSame('http://localhost/en'.$importPath, $this->sitemapLoc('http://localhost', '', '/index.php', 'en', $import));
        $this->assertNull($this->sitemapLoc('http://localhost', '', '/index.php', 'fr', $import));
        $this->assertSame(['en' => 'http://localhost/en'.$importPath, 'vi' => 'http://localhost'.$importPath, 'x-default' => 'http://localhost'.$importPath],
            $this->sitemapAlternates('http://localhost', '', '/index.php', 'vi', $import));

        $index = (string) $this->page('http://localhost', '/sitemap.xml')->getContent();
        $this->assertStringContainsString(url('/sitemap-videos-vi-1.xml'), $index);
        $this->assertStringContainsString(url('/sitemap-videos-en-1.xml'), $index);
        $this->assertStringNotContainsString(url('/sitemap-videos-fr-'), $index);
    }

    /** An AVDBAPI import with a French translation (indexable in English, the default language, and in French). */
    private function translatedImport(): Video
    {
        $import = $this->video('QA French translated import', ['avdb_id' => 77790]);
        DB::table('video_translations')->insert([
            'video_id' => $import->id, 'locale' => 'fr', 'title' => 'QA import traduit', 'description' => null,
            'type_name' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $import;
    }

    /** A public video of the test channel. */
    private function video(string $title, array $attributes = []): Video
    {
        return Video::factory()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->creator->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => $title.' description.',
            'category_id' => null,
            'status' => VideoStatus::PUBLIC->value,
            'published_at' => now()->subDays(2),
            'uploaded_at' => now()->subDays(2),
            'language' => null,
            'embed_code' => null,
        ], $attributes));
    }

    /** The site at http://localhost, as EnvironmentServiceProvider sets up an installed site. */
    private function siteAtRoot(): void
    {
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://localhost');
    }

    /** The site at https://example.test/site, as EnvironmentServiceProvider sets up a site whose APP_URL is not a local one. */
    private function siteInFolder(): void
    {
        config(['app.url' => 'https://example.test/site']);
        URL::forceRootUrl('https://example.test/site');
        app('url')->forceScheme('https');
    }

    /** The site's links carry /index.php, as EnvironmentServiceProvider::applyCleanUrlFallback() sets it. */
    private function indexPhpLinks(): void
    {
        config(['app.clean_url_reason' => 'setting_off', 'app.clean_url_fallback' => true]);
        app('url')->formatPathUsing(function ($path) {
            if ($path === '' || $path === '/') {
                return '/index.php';
            }

            return str_starts_with($path, '/index.php') ? $path : '/index.php'.(str_starts_with($path, '/') ? $path : '/'.$path);
        });
    }

    /**
     * A request as the web server and public/index.php hand it to Laravel (App\Support\SiteAddress: the folder, /index.php
     * and the language read off the address and marked), sent through the kernel, as a visitor in a fresh state.
     */
    private function page(string $origin, string $address, string $script = '/index.php'): TestResponse
    {
        $this->flushSession();
        $this->flushHeaders();
        app()->setLocale((string) config('site.default_locale', 'en'));

        foreach (self::MARKS as $key) {
            unset($_SERVER[$key]);
        }

        $server = ['SCRIPT_NAME' => $script, 'PHP_SELF' => $script, 'REQUEST_URI' => $address];
        $this->assertNull(SiteAddress::prepare($server, array_keys((array) config('languages')), public_path(), public_path('index.php')), $address);

        foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX'] as $key) {
            if (isset($server[$key])) {
                $_SERVER[$key] = $server[$key];
            }
        }

        $variables = ['SCRIPT_NAME' => $server['SCRIPT_NAME'], 'PHP_SELF' => $server['PHP_SELF'], 'SCRIPT_FILENAME' => public_path('index.php')];

        if (str_starts_with($origin, 'https://')) {
            $variables['HTTPS'] = 'on';
        }

        try {
            return $this->withServerVariables($variables)->call('GET', $origin.$server['REQUEST_URI']);
        } finally {
            foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX'] as $key) {
                unset($_SERVER[$key]);
            }
        }
    }

    private function html(string $origin, string $address, string $script = '/index.php'): string
    {
        $response = $this->page($origin, $address, $script);
        $this->assertSame(200, $response->getStatusCode(), $address);

        return (string) $response->getContent();
    }

    /** The video's entry in the language's video sitemap, asked for as a crawler asks for it in this layout ('' when absent). */
    private function sitemapEntry(string $origin, string $base, string $script, string $locale, Video $video): string
    {
        SeoSitemapCache::clear();
        $xml = $this->page($origin, $base.'/sitemap-videos-'.$locale.'-1.xml', $script);
        $this->assertSame(200, $xml->getStatusCode(), 'the '.$locale.' video sitemap');

        foreach (explode('<url>', (string) $xml->getContent()) as $entry) {
            if (preg_match('#<loc>[^<]*'.preg_quote((string) $video->uuid, '#').'</loc>#', $entry) === 1) {
                return $entry;
            }
        }

        return '';
    }

    private function sitemapLoc(string $origin, string $base, string $script, string $locale, Video $video): ?string
    {
        $entry = $this->sitemapEntry($origin, $base, $script, $locale, $video);

        return preg_match('#<loc>([^<]*)</loc>#', $entry, $match) === 1 ? html_entity_decode($match[1], ENT_QUOTES | ENT_XML1) : null;
    }

    /** @return array<string, string> hreflang => address of the video's entry, sorted by hreflang */
    private function sitemapAlternates(string $origin, string $base, string $script, string $locale, Video $video): array
    {
        preg_match_all('#<xhtml:link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/>#', $this->sitemapEntry($origin, $base, $script, $locale, $video), $matches, PREG_SET_ORDER);
        $links = [];

        foreach ($matches as $match) {
            $links[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_XML1);
        }

        ksort($links);

        return $links;
    }

    /** The test channel's path inside the site (/@slug-uid, without the folder and without /index.php), as the sitemaps print it. */
    private function channelPath(): string
    {
        $path = site_path(route('user.show', $this->creator, false));

        return str_starts_with($path, '/index.php/') ? substr($path, strlen('/index.php')) : $path;
    }

    /** The language's profile sitemap, asked for as a crawler asks for it in this layout. */
    private function profileSitemap(string $origin, string $base, string $script, string $locale): string
    {
        SeoSitemapCache::clear();
        $xml = $this->page($origin, $base.'/sitemap-profiles-'.$locale.'-1.xml', $script);
        $this->assertSame(200, $xml->getStatusCode(), 'the '.$locale.' profile sitemap');

        return (string) $xml->getContent();
    }

    /** The test channel's entry in the language's profile sitemap ('' when absent). */
    private function profileSitemapEntry(string $origin, string $base, string $script, string $locale): string
    {
        foreach (explode('<url>', $this->profileSitemap($origin, $base, $script, $locale)) as $entry) {
            if (preg_match('#<loc>[^<]*'.preg_quote($this->channelPath(), '#').'</loc>#', $entry) === 1) {
                return $entry;
            }
        }

        return '';
    }

    private function profileSitemapLoc(string $origin, string $base, string $script, string $locale): ?string
    {
        $entry = $this->profileSitemapEntry($origin, $base, $script, $locale);

        return preg_match('#<loc>([^<]*)</loc>#', $entry, $match) === 1 ? html_entity_decode($match[1], ENT_QUOTES | ENT_XML1) : null;
    }

    /** @return array<string, string> hreflang => address of the channel's entry, sorted by hreflang */
    private function profileSitemapAlternates(string $origin, string $base, string $script, string $locale): array
    {
        preg_match_all('#<xhtml:link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/>#', $this->profileSitemapEntry($origin, $base, $script, $locale), $matches, PREG_SET_ORDER);
        $links = [];

        foreach ($matches as $match) {
            $links[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_XML1);
        }

        ksort($links);

        return $links;
    }

    private function canonical(string $html): ?string
    {
        return preg_match('#<link rel="canonical" href="([^"]*)">#', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
    }

    /** @return array<string, string> hreflang => address, sorted by hreflang */
    private function alternates(string $html): array
    {
        preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]*)">#', $html, $matches, PREG_SET_ORDER);
        $links = [];

        foreach ($matches as $match) {
            $links[$match[1]] = html_entity_decode($match[2]);
        }

        ksort($links);

        return $links;
    }
}
