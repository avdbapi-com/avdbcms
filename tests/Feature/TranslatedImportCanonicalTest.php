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
use Tests\TestCase;

/**
 * Round 4 fix pass, R3M-01 (already in 1.2.2): every language's watch page named the default-language page as its
 * canonical (videos/show.blade.php: @section('canonical', $video->route)), while the sitemaps and the hreflang links offer
 * an AVDBAPI import's translated pages as indexable since round 3 (App\Support\VideoLanguages): those pages canonicalized
 * away and were never indexed.
 *
 * The rule: an AVDBAPI import's page in a language other than the default one where it is indexable (its translation's
 * page) names itself as its canonical, exactly as its sitemap entry and the hreflang links name it; every other watch page
 * keeps the default-language page (a member's upload and another source's import: one canonical, as in 1.2.2; an import's
 * page where it is not indexable, which answers noindex). Proved at a domain's root, with /index.php addresses, in a
 * folder (APP_URL https://example.test/site, lane SB's locale_url() rules) and in a folder with /index.php, and with the
 * canonical and hreflang settings off. A channel's page keeps the default-language canonical (the same content in every
 * language).
 *
 * Test data: neutral titles.
 */
class TranslatedImportCanonicalTest extends TestCase
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
        config(['site.default_locale' => 'en', 'cache.limiter' => 'array', 'site.canonical_enabled' => true, 'site.hreflang_enabled' => true]);
        $this->creator = User::factory()->create(['email_verified_at' => now(), 'username' => 'qa_canonical_channel']);
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

    public function test_at_a_domains_root_a_translated_imports_page_is_its_own_canonical(): void
    {
        $this->siteAtRoot();

        $this->assertLayout('http://localhost', '', '/index.php');
    }

    public function test_with_index_php_addresses_the_canonical_keeps_index_php_before_the_language(): void
    {
        $this->siteAtRoot();
        $this->indexPhpLinks();

        $this->assertLayout('http://localhost', '/index.php', '/index.php');
    }

    public function test_in_a_folder_the_canonical_keeps_the_folder_once(): void
    {
        $this->siteInFolder();

        $this->assertLayout('https://example.test', '/site', '/site/index.php');
    }

    public function test_in_a_folder_with_index_php_addresses(): void
    {
        $this->siteInFolder();
        $this->indexPhpLinks();

        $this->assertLayout('https://example.test', '/site/index.php', '/site/index.php');
    }

    public function test_with_the_canonical_or_the_hreflang_setting_off(): void
    {
        $this->siteAtRoot();
        $import = $this->translatedImport();
        $path = app(SeoService::class)->videoPath($import);
        $french = 'http://localhost/fr'.$path;

        // Canonical links off: no canonical link; the language links and og:url still name the page itself
        config(['site.canonical_enabled' => false]);
        $html = $this->html('http://localhost', '/fr'.$path);
        $this->assertNull($this->canonical($html));
        $this->assertSame($french, $this->ogUrl($html));
        $this->assertSame(['en' => 'http://localhost'.$path, 'fr' => $french, 'x-default' => 'http://localhost'.$path], $this->alternates($html));

        // Language links off: no alternate; the canonical is still the page itself
        config(['site.canonical_enabled' => true, 'site.hreflang_enabled' => false]);
        $html = $this->html('http://localhost', '/fr'.$path);
        $this->assertSame($french, $this->canonical($html));
        $this->assertSame([], $this->alternates($html));

        // both off: neither, and the page still opens
        config(['site.canonical_enabled' => false]);
        $html = $this->html('http://localhost', '/fr'.$path);
        $this->assertNull($this->canonical($html));
        $this->assertSame([], $this->alternates($html));
    }

    public function test_a_channels_page_keeps_the_default_language_canonical(): void
    {
        $this->siteAtRoot();
        $this->translatedImport();
        $channel = $this->html('http://localhost', '/fr'.site_path(route('user.show', $this->creator, false)));
        $address = $this->creator->route;

        $this->assertSame($address, $this->canonical($channel), 'the same content in every language: one canonical');
        $this->assertStringNotContainsString('/fr/', (string) $this->canonical($channel));
    }

    /**
     * One install layout: $origin (scheme and host), $base (what the site's addresses start with after the host: the
     * folder and /index.php where the links carry them) and $script (the front controller the web server runs).
     */
    private function assertLayout(string $origin, string $base, string $script): void
    {
        $import = $this->translatedImport();
        $upload = $this->video('QA member upload', ['language' => 'fr']);
        $other = $this->video('QA other source import', ['xvid_id' => 4243]);
        $seo = app(SeoService::class);
        $path = $seo->videoPath($import);
        $home = $origin.$base.$path;

        // its French page (its translation): its own address, the one its sitemap entry and every hreflang link name
        $french = $this->html($origin, $base.'/fr'.$path, $script);
        $this->assertSame($origin.$base.'/fr'.$path, $this->canonical($french), 'the translated page is its own canonical');
        $this->assertSame($this->canonical($french), $this->ogUrl($french), 'og:url follows the canonical');
        $this->assertSame($this->canonical($french), $this->videoObjectUrl($french), 'the VideoObject\'s url too');
        $alternates = ['en' => $home, 'fr' => $origin.$base.'/fr'.$path, 'x-default' => $home];
        $this->assertSame($alternates, $this->alternates($french), 'the language links are unchanged');
        $this->assertSame($this->canonical($french), $this->sitemapLoc($origin, $base, $script, 'fr', $import), 'the French sitemap lists that address');
        $this->assertStringNotContainsString('/site/site/', $french);
        $this->assertDoesNotMatchRegularExpression('#<link rel="canonical" href="[^"]*/fr/index\.php#', $french, '/index.php comes before the language');

        // its English page (the default language): the default-language page, as before
        $english = $this->html($origin, $base.$path, $script);
        $this->assertSame($home, $this->canonical($english));
        $this->assertSame($home, $this->videoObjectUrl($english));
        $this->assertSame($alternates, $this->alternates($english));
        $this->assertSame($home, $this->sitemapLoc($origin, $base, $script, 'en', $import));

        // its German page (no translation): it answers noindex and keeps the default-language page
        $german = $this->page($origin, $base.'/de'.$path, $script);
        $this->assertSame('noindex, follow', $german->headers->get('X-Robots-Tag'));
        $this->assertSame($home, $this->canonical((string) $german->getContent()));

        // a member's upload (even one in French) and another source's import: one canonical, the default-language page
        foreach ([$upload, $other] as $video) {
            $videoPath = $seo->videoPath($video);

            foreach (['/fr', '/de', ''] as $prefix) {
                $html = $this->html($origin, $base.$prefix.$videoPath, $script);
                $this->assertSame($origin.$base.$videoPath, $this->canonical($html), $video->title.' '.($prefix ?: 'default language'));
                $this->assertSame($origin.$base.$videoPath, $this->videoObjectUrl($html), $video->title.' '.($prefix ?: 'default language'));
            }
        }
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

    /**
     * The site at http://localhost: its links start with APP_URL, as EnvironmentServiceProvider sets up an installed site
     * (the language links are written from APP_URL, the canonical from the URL generator: on a site both are its address).
     */
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
        app()->setLocale('en');

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

    /** The <loc> of the video in the language's video sitemap, asked for as a crawler asks for it in this layout. */
    private function sitemapLoc(string $origin, string $base, string $script, string $locale, Video $video): ?string
    {
        $xml = $this->page($origin, $base.'/sitemap-videos-'.$locale.'-1.xml', $script);
        $this->assertSame(200, $xml->getStatusCode(), 'the '.$locale.' video sitemap');
        preg_match_all('#<loc>([^<]*)</loc>#', (string) $xml->getContent(), $matches);

        foreach ($matches[1] as $loc) {
            if (str_contains($loc, (string) $video->uuid)) {
                return html_entity_decode($loc, ENT_QUOTES | ENT_XML1);
            }
        }

        return null;
    }

    private function canonical(string $html): ?string
    {
        return preg_match('#<link rel="canonical" href="([^"]*)">#', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
    }

    /** The url of the page's VideoObject (its JSON-LD). */
    private function videoObjectUrl(string $html): ?string
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        foreach ($matches[1] as $json) {
            $data = json_decode($json, true);

            if (is_array($data) && ($data['@type'] ?? null) === 'VideoObject') {
                return $data['url'] ?? null;
            }
        }

        return null;
    }

    private function ogUrl(string $html): ?string
    {
        return preg_match('#<meta property="og:url" content="([^"]*)">#', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
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
