<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Controllers\Auth\ReturnPath;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Support\AppearanceDraft;
use App\Support\CleanUrls;
use App\Support\SiteAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 fix pass, lane SB's second pass: what the independent review of R2R-05 found on the real folder installs, each
 * kept here with the address it must give (at a domain's root: exactly what 1.2.3 RC2 gave).
 *  - SB-RV-01: the default pictures (a category without its own banner, a video without a picture) carried the folder
 *    twice in og:image, the video sitemap and the JSON-LD (/site/site/images/default_banner.jpg: 404).
 *  - SB-RV-02: Appearance's preview monitor hid the look's screenshot on sites whose links carry /index.php and on every
 *    folder site: the home page's address of the monitor ("/index.php", "/site/") never equalled its picture's page "/".
 *  - SB-RV-04 (orchestrator SB-X3): the canonical-host and the https redirects lost the page's language (public/index.php
 *    takes it off the address Laravel reads) and APP_URL's port.
 *  - SB-RV-10: the comments' Sign in reads the page's own sign-in address (window.AVD_USER.loginUrl), which lane SV makes
 *    ReturnPath::loginUrl(): that address keeps the page's language, in every form.
 */
class SubFolderReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX', 'AVDBCLIP_USED_INDEX_PHP', 'AVDBCLIP_ROOT_FALLBACK', 'REQUEST_URI'] as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }

        config([
            'site.captcha_provider' => 'none',
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
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
        app('url')->useAssetOrigin(null);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_default_pictures_carry_the_folder_once_wherever_they_are_printed(): void
    {
        $category = Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->for(User::factory())->create(['file' => 'sb-rv01.mp4', 'category_id' => $category->id]);

        // A domain's root: the path, as up to 1.2.3 RC2 (byte for byte).
        $this->assertSame('/images/default_banner.jpg', $category->background_url);
        $this->assertSame('/images/placeholders/no-image.svg', $video->thumbnail_url);

        // A folder: the whole address, as the uploaded pictures' (Storage::url(), route()), once.
        $this->siteInFolder();
        $this->assertSame('https://example.test/site/images/default_banner.jpg', $category->background_url);
        $this->assertSame('https://example.test/site/images/placeholders/no-image.svg', $video->thumbnail_url);
        $this->assertSame('https://example.test/site/images/default_banner.jpg', site_asset_url($category->background_url), 'og:image of a category page');
        $this->assertSame('https://example.test/site/images/placeholders/no-image.svg', site_asset_url($video->thumbnail_url), 'og:image, video:thumbnail_loc, JSON-LD');

        $page = (string) $this->visit('GET', '/site/categories/music')->assertOk()->getContent();
        $this->assertStringContainsString('content="https://example.test/site/images/default_banner.jpg"', $page);
        $this->assertStringNotContainsString('/site/site/', $page);

        $sitemap = (string) $this->visit('GET', '/site/sitemap-videos.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<video:thumbnail_loc>https://example.test/site/images/placeholders/no-image.svg</video:thumbnail_loc>', $sitemap);
        $this->assertStringNotContainsString('/site/site/', $sitemap);

        // The site's files served from another address (ASSET_URL, here the project folder's public/ in a folder): theirs.
        app('url')->useAssetOrigin('https://example.test/proj/public');
        $this->assertSame('https://example.test/proj/public/images/default_banner.jpg', $category->background_url);
        $this->assertSame('https://example.test/proj/public/images/default_banner.jpg', site_asset_url($category->background_url));
    }

    public function test_the_appearance_monitor_shows_its_picture_for_the_home_page_in_every_form(): void
    {
        // [the site's folder, whether its links carry /index.php, the monitor's home page address]
        $forms = [
            ['', false, '/'],
            ['', true, '/index.php'],
            ['/site', false, '/site/'],
            ['/site', true, '/site/index.php'],
        ];

        foreach ($forms as [$folder, $indexPhp, $home]) {
            config(['app.url' => 'https://example.test'.$folder, 'app.clean_url_reason' => null, 'app.clean_url_fallback' => false]);
            URL::forceRootUrl('https://example.test'.$folder);
            app('url')->formatPathUsing(fn ($path) => $path);

            if ($indexPhp) {
                $this->indexPhpLinks();
            }

            // the pages as AppearanceController::monitorPages() gives them
            $pages = [];
            foreach (['/' => 'Homepage', '/trend' => 'Trending'] as $path => $label) {
                $pages[AppearanceDraft::previewUrl(CleanUrls::requestForm(site_browser_path($path)), 'token')] = $label;
            }
            $this->assertSame($home, parse_url((string) array_key_first($pages), PHP_URL_PATH), $home);

            $html = Blade::render('<x-admin.monitor :pages="$pages" shot="/shot.png" shot-page="/" />', ['pages' => $pages]);
            $this->assertMatchesRegularExpression('#<img src="/shot\.png"[^>]*data-ad-monitor-shot\s+data-page="'.preg_quote($home, '#').'"\s*>#', $html, $home.': the picture is shown');
            $this->assertMatchesRegularExpression('#<span class="ad-monitor-empty"\s+hidden\s*>#', $html, $home.': no "Show the live preview" text');

            // another page first: the picture is hidden, the text asks for the live preview
            $other = Blade::render('<x-admin.monitor :pages="$pages" :src="$src" shot="/shot.png" shot-page="/" />', ['pages' => $pages, 'src' => array_keys($pages)[1]]);
            $this->assertMatchesRegularExpression('#data-ad-monitor-shot\s+data-page="'.preg_quote($home, '#').'"\s+hidden\s*>#', $other, $home);
        }
    }

    public function test_the_canonical_host_redirect_keeps_the_language_index_php_the_folder_and_the_port(): void
    {
        config(['app.url' => 'http://example.test:8283/site']);
        URL::forceRootUrl('http://example.test:8283/site');

        $this->visit('GET', '/site/fr/trend', 'http://www.example.test:8283')->assertStatus(301)->assertHeader('Location', 'http://example.test:8283/site/fr/trend');
        $this->visit('GET', '/site/index.php/fr/trend?x=1', 'http://www.example.test:8283')->assertStatus(301)->assertHeader('Location', 'http://example.test:8283/site/index.php/fr/trend?x=1');
        $this->visit('GET', '/site/ar/categories?x=1', 'http://www.example.test:8283')->assertStatus(301)->assertHeader('Location', 'http://example.test:8283/site/ar/categories?x=1');
        $this->visit('GET', '/site/vi', 'http://www.example.test:8283')->assertStatus(301)->assertHeader('Location', 'http://example.test:8283/site/vi');
        $this->visit('GET', '/site/', 'http://www.example.test:8283')->assertStatus(301)->assertHeader('Location', 'http://example.test:8283/site/');
        $this->visit('GET', '/site/trend', 'http://www.example.test:8283')->assertStatus(301)->assertHeader('Location', 'http://example.test:8283/site/trend');
    }

    public function test_at_a_domains_root_the_canonical_host_redirect_keeps_the_language(): void
    {
        config(['app.url' => 'https://example.test']);
        URL::forceRootUrl('https://example.test');

        $this->visit('GET', '/fr/trend', 'http://www.example.test', '/index.php')->assertStatus(301)->assertHeader('Location', 'https://example.test/fr/trend');
        $this->visit('GET', '/index.php/vi?x=1', 'http://www.example.test', '/index.php')->assertStatus(301)->assertHeader('Location', 'https://example.test/index.php/vi?x=1');
        $this->visit('GET', '/trend?x=1', 'http://www.example.test', '/index.php')->assertStatus(301)->assertHeader('Location', 'https://example.test/trend?x=1');
        // the same host over https: no redirect
        $this->visit('GET', '/fr/trend', 'https://example.test', '/index.php')->assertOk();
    }

    public function test_the_https_redirect_keeps_the_language_and_stays_on_the_site(): void
    {
        config(['app.url' => 'http://example.test/site', 'site.force_https' => true]);
        URL::forceRootUrl('http://example.test/site');
        $env = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            // (each as a new visitor: the redirect's loop guard remembers a browser that came back over http)
            Cache::flush();
            $this->visit('GET', '/site/fr/trend?x=1', 'http://example.test')->assertStatus(301)->assertHeader('Location', 'https://example.test/site/fr/trend?x=1');
            Cache::flush();
            $this->visit('GET', '/site/index.php/ar/categories', 'http://example.test')->assertStatus(301)->assertHeader('Location', 'https://example.test/site/index.php/ar/categories');
            Cache::flush();
            $this->visit('GET', '/site/de', 'http://example.test')->assertStatus(301)->assertHeader('Location', 'https://example.test/site/de');

            // never another host: "/site//evil.example" is a page of this site
            Cache::flush();
            $location = (string) $this->visit('GET', '/site//evil.example', 'http://example.test')->assertStatus(301)->headers->get('Location');
            $this->assertSame('example.test', parse_url($location, PHP_URL_HOST));
        } finally {
            $this->app['env'] = $env;
        }
    }

    public function test_the_page_sign_in_address_keeps_the_pages_language_in_every_form(): void
    {
        // A domain's root, clean links: what lane SV's layout line (ReturnPath::loginUrl()) prints for the comments.
        $request = $this->pageRequest('https://example.test', '/video/abc?t=3', '/index.php', ['DETECTED_LOCALE' => 'fr', 'LOCALE_PREFIX' => '/fr']);
        app()->instance('request', $request);
        app()->setLocale('fr');
        $this->assertSame('/fr/login?redirect=%2Ffr%2Fvideo%2Fabc%3Ft%3D3', ReturnPath::loginUrl(null, $request), 'as RC2\'s comments built it');

        // A domain's root whose links carry /index.php.
        $this->indexPhpLinks();
        $this->assertSame('/index.php/fr/login?redirect=%2Findex.php%2Ffr%2Fvideo%2Fabc%3Ft%3D3', ReturnPath::loginUrl(null, $request));

        // A folder, both forms.
        app('url')->formatPathUsing(fn ($path) => $path);
        config(['app.clean_url_reason' => null, 'app.clean_url_fallback' => false]);
        $this->siteInFolder();
        $request = $this->pageRequest('https://example.test', '/site/video/abc?t=3', '/site/index.php', ['DETECTED_LOCALE' => 'fr', 'LOCALE_PREFIX' => '/fr']);
        app()->instance('request', $request);
        $this->assertSame('https://example.test/site/fr/login?redirect=%2Fsite%2Ffr%2Fvideo%2Fabc%3Ft%3D3', ReturnPath::loginUrl(null, $request));
        $this->indexPhpLinks();
        $this->assertSame('https://example.test/site/index.php/fr/login?redirect=%2Fsite%2Findex.php%2Ffr%2Fvideo%2Fabc%3Ft%3D3', ReturnPath::loginUrl(null, $request));
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
     * A request as the web server and public/index.php hand it to Laravel (App\Support\SiteAddress: the language and
     * /index.php taken off and marked), sent through the kernel to $origin (scheme, host and port).
     */
    private function visit(string $method, string $address, string $origin = 'https://example.test', string $scriptName = '/site/index.php'): TestResponse
    {
        $server = ['SCRIPT_NAME' => $scriptName, 'PHP_SELF' => $scriptName, 'REQUEST_URI' => $address];
        $this->assertNull(SiteAddress::prepare($server, array_keys((array) config('languages')), public_path(), public_path('index.php')));

        foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX'] as $key) {
            if (isset($server[$key])) {
                $_SERVER[$key] = $server[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }

        $variables = ['SCRIPT_NAME' => $server['SCRIPT_NAME'], 'PHP_SELF' => $server['PHP_SELF'], 'SCRIPT_FILENAME' => public_path('index.php')];
        if (str_starts_with($origin, 'https://')) {
            $variables['HTTPS'] = 'on';
        }

        return $this->withServerVariables($variables)->call($method, $origin.$server['REQUEST_URI']);
    }

    /** @param  array<string, string>  $marks  what public/index.php marked ($_SERVER) */
    private function pageRequest(string $origin, string $address, string $scriptName, array $marks = []): Request
    {
        foreach ($marks as $key => $value) {
            $_SERVER[$key] = $value;
        }

        return Request::create($origin.$address, 'GET', [], [], [], ['SCRIPT_NAME' => $scriptName, 'PHP_SELF' => $scriptName,
            'SCRIPT_FILENAME' => public_path('index.php'), 'HTTPS' => 'on'] + $marks);
    }
}
