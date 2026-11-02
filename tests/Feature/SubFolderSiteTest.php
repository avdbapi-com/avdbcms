<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ReturnPath;
use App\Http\Controllers\User\ProfileController;
use App\Models\User;
use App\Notifications\Support\MemberMail;
use App\Support\ServerChecks;
use App\Support\SiteAddress;
use App\Support\SiteUrlGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Round 3 fix pass, R2R-05 (a 1.2.3 regression for signing in): a site installed in a folder of its domain
 * (APP_URL https://example.test/site: Apache "Alias /site", nginx "location /site", the project folder unpacked into the
 * web root's site/) works end to end with one rule for every address it builds, with and without rewrite rules and
 * /index.php:
 *  - the front controllers read the address inside the folder (App\Support\SiteAddress): /index.php, the language and the
 *    trailing-slash redirect as at a domain's root, and Laravel gets the folder in front of the page, so its own root is
 *    https://example.test/site;
 *  - locale_url() gives the whole address in a folder (a path with the folder got it a second time from
 *    redirect()->to() and url(): /site/site/login, the 1.2.3 RC2 sign-in round trip; /fr/site/... in another language);
 *  - the sign-in round trip, the return address (?redirect=), the language switch, the error pages, the head's canonical
 *    and language alternates, the mail links, the installer's suggested address and its own addresses, relative route
 *    addresses (the admin's lists, the updater's steps: App\Support\SiteUrlGenerator), the session cookie's path;
 *  - at a domain's root every address is exactly what it was up to 1.2.3 RC2.
 * The real sub-folder installs (Windows: PHP's server under /site, clean and /index.php only; Linux: nginx
 * "location /site", Apache "Alias /site" and the project folder in a folder) are in the lane's runs.
 */
class SubFolderSiteTest extends TestCase
{
    use RefreshDatabase;

    private const LANGUAGES = ['en', 'fr', 'vi', 'ar', 'de'];

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
            'site.login_rate_limit' => 1000,
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
        app()->setLocale('en');

        parent::tearDown();
    }

    /**
     * $_SERVER of a request => what public/index.php hands to Laravel.
     *
     * @return array<string, array{0: array<string, string>, 1: array<string, mixed>, 2: ?string}>
     */
    public static function addresses(): array
    {
        return [
            // a domain's root: as up to 1.2.3 RC2
            'root, a page in a language' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/fr/trend?x=1'],
                ['REQUEST_URI' => '/trend?x=1', 'PATH_INFO' => '/trend', 'DETECTED_LOCALE' => 'fr', 'LOCALE_PREFIX' => '/fr', 'SCRIPT_NAME' => '/index.php'], null],
            'root, through /index.php' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/index.php/fr/trend'],
                ['REQUEST_URI' => '/trend', 'DETECTED_LOCALE' => 'fr', 'AVDBCLIP_INDEX_PHP_PREFIX' => true], null],
            'root, a plain page' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/trend'], ['REQUEST_URI' => '/trend', 'DETECTED_LOCALE' => null], null],
            'root, the home page' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/'], ['REQUEST_URI' => '/'], null],
            'root, the .htaccess of the project folder' => [['SCRIPT_NAME' => '/public/index.php', 'REQUEST_URI' => '/fr/trend'],
                ['REQUEST_URI' => '/trend', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'AVDBCLIP_ROOT_FALLBACK' => true, 'DETECTED_LOCALE' => 'fr'], null],
            'root, a trailing slash' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/trend/?a=1'], [], '/trend?a=1'],
            'root, a language home with a slash' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/vi/'], [], '/vi'],
            'root, /index.php and a slash' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/index.php/vi/trend/'], [], '/index.php/vi/trend'],
            'root, never another site' => [['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/index.php//evil.example/'], [], '/index.php/evil.example'],

            // a folder: the same, inside the folder, and Laravel gets the folder
            'folder, a page in a language' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/fr/trend?x=1'],
                ['REQUEST_URI' => '/site/trend?x=1', 'PATH_INFO' => '/trend', 'DETECTED_LOCALE' => 'fr', 'LOCALE_PREFIX' => '/fr', 'SCRIPT_NAME' => '/site/index.php'], null],
            'folder, through /index.php' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/index.php/vi/profile/videos?status=public'],
                ['REQUEST_URI' => '/site/profile/videos?status=public', 'DETECTED_LOCALE' => 'vi', 'AVDBCLIP_INDEX_PHP_PREFIX' => true], null],
            'folder, a plain page' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/trend'], ['REQUEST_URI' => '/site/trend', 'DETECTED_LOCALE' => null], null],
            'folder, its home without the slash' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site?x=1'], ['REQUEST_URI' => '/site/?x=1', 'PATH_INFO' => '/'], null],
            'folder, its home' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/'], ['REQUEST_URI' => '/site/'], null],
            'folder, its /index.php' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/index.php'], ['REQUEST_URI' => '/site/', 'AVDBCLIP_INDEX_PHP_PREFIX' => true], null],
            'folder, the language home' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/ar'], ['REQUEST_URI' => '/site/', 'DETECTED_LOCALE' => 'ar'], null],
            'folder, the .htaccess of the project folder' => [['SCRIPT_NAME' => '/site/public/index.php', 'REQUEST_URI' => '/site/fr/trend'],
                ['REQUEST_URI' => '/site/trend', 'SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'AVDBCLIP_ROOT_FALLBACK' => true, 'DETECTED_LOCALE' => 'fr'], null],
            'folder, a trailing slash' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/trend/?a=1'], [], '/site/trend?a=1'],
            'folder, a language home with a slash' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/fr/'], [], '/site/fr'],
            'folder, /index.php and a slash' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/index.php/fr/trend/'], [], '/site/index.php/fr/trend'],
            'folder, never another site' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site//evil.example/'], [], '/site/evil.example'],
            'folder, never another site through /index.php' => [['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/index.php//evil.example/'], [], '/site/index.php/evil.example'],
            'folder, two levels' => [['SCRIPT_NAME' => '/apps/site/index.php', 'REQUEST_URI' => '/apps/site/vi/library'],
                ['REQUEST_URI' => '/apps/site/library', 'DETECTED_LOCALE' => 'vi'], null],
            'a folder named like a page of the site' => [['SCRIPT_NAME' => '/video/index.php', 'REQUEST_URI' => '/video/fr/video/abc'],
                ['REQUEST_URI' => '/video/video/abc', 'DETECTED_LOCALE' => 'fr'], null],
            'a script in a folder serving the domain\'s root' => [['SCRIPT_NAME' => '/site/public/index.php', 'REQUEST_URI' => '/fr/trend'],
                ['REQUEST_URI' => '/trend', 'SCRIPT_NAME' => '/index.php', 'DETECTED_LOCALE' => 'fr'], null],
            'a script name that is no plain folder' => [['SCRIPT_NAME' => '/a/../b/index.php', 'REQUEST_URI' => '/a/../b/trend'], ['REQUEST_URI' => '/a/../b/trend'], null],
        ];
    }

    /**
     * @param  array<string, string>  $server
     * @param  array<string, mixed>  $expected  null: the key is not set
     */
    #[DataProvider('addresses')]
    public function test_the_front_controller_reads_the_address_inside_the_sites_folder(array $server, array $expected, ?string $redirect): void
    {
        $server += ['PHP_SELF' => $server['SCRIPT_NAME']];

        $this->assertSame($redirect, SiteAddress::prepare($server, self::LANGUAGES, public_path(), public_path('index.php')));

        foreach ($expected as $key => $value) {
            if ($value === null) {
                $this->assertArrayNotHasKey($key, $server, $key);
            } else {
                $this->assertSame($value, $server[$key] ?? null, $key);
            }
        }
    }

    public function test_laravel_finds_the_folder_in_the_address_public_index_php_hands_over(): void
    {
        foreach (['/site/fr/trend?x=1' => ['/site', '/trend'], '/site' => ['/site', '/'], '/site/index.php/de/library' => ['/site', '/library'],
            '/fr/trend' => ['', '/trend']] as $address => [$base, $page]) {
            $server = ['SCRIPT_NAME' => str_starts_with($address, '/site') ? '/site/index.php' : '/index.php', 'REQUEST_URI' => $address];
            $server['PHP_SELF'] = $server['SCRIPT_NAME'];
            SiteAddress::prepare($server, self::LANGUAGES, public_path(), public_path('index.php'));
            $request = Request::create('http://example.test'.$server['REQUEST_URI'], 'GET', [], [], [], $server + ['SCRIPT_FILENAME' => public_path('index.php')]);

            $this->assertSame($base, $request->getBaseUrl(), $address);
            $this->assertSame($page, $request->getPathInfo(), $address);
        }
    }

    public function test_in_a_folder_every_address_the_site_builds_keeps_the_folder_once(): void
    {
        $this->siteInFolder();

        $this->assertSame('https://example.test/site', site_root());
        $this->assertSame('/site', site_folder());
        $this->assertSame('https://example.test/site/trend', locale_url('/trend'));
        $this->assertSame('https://example.test/site/', locale_url('/'));
        $this->assertSame('https://example.test/site/login', locale_url(route('login')));
        $this->assertSame('https://example.test/site/trend?x=1#c', locale_url('https://example.test/site/trend?x=1#c'), 'an address of the site reads back the same');
        $this->assertSame('/site/admin/users', route('admin.users.index', [], false), 'a relative route address starts at the domain\'s root');
        $this->assertSame('https://example.test/site/admin/users', route('admin.users.index'));
        $this->assertSame('/trend', site_path('/site/trend'));
        $this->assertSame('/', site_path('/site'));
        $this->assertSame('/fr/x?y=1', site_path('/site/fr/x?y=1'));
        $this->assertSame('/other/x', site_path('/other/x'), 'outside the folder: as it is');
        $this->assertSame('/site/fr/x', site_browser_path('/fr/x'));

        app()->setLocale('fr');
        $this->assertSame('https://example.test/site/fr/trend', locale_url('/trend'));
        $this->assertSame('https://example.test/site/fr/login', locale_url(route('login')), 'never /fr/site/login');
        $this->assertSame('https://example.test/site/fr/login', locale_url(locale_url(route('login'))), 'read twice: the same');
        $this->assertSame('https://example.test/site/fr', locale_url('/'));
        $_SERVER['REQUEST_URI'] = '/site/trend?x=1';
        $this->assertSame('https://example.test/site/update-locale?locale=de&redirect=%2Fsite%2Ftrend%3Fx%3D1', locale_switch_url('de'));
        app()->setLocale('en');

        // a redirect, url() and a mail link built from locale_url() keep it once (1.2.3 RC2: /site/site/...)
        app()->setLocale('fr');
        $this->assertSame('https://example.test/site/fr/history', redirect()->to(locale_url('/history'))->getTargetUrl());
        app()->setLocale('en');
        $this->assertSame('https://example.test/site/trend', url(locale_url('/trend')));
        $this->assertSame('https://example.test/site/profile/edit?section=security', ProfileController::sectionUrl('security'));

        // the site's links carry /index.php (a server without rewrite rules): after the folder, never before it
        $this->indexPhpLinks();
        $this->assertSame('https://example.test/site/index.php/trend', locale_url('/trend'));
        $this->assertSame('https://example.test/site/index.php', locale_url('/'));
        $this->assertSame('/site/index.php/admin/users', route('admin.users.index', [], false));
        app()->setLocale('vi');
        $this->assertSame('https://example.test/site/index.php/vi/login', locale_url(route('login')));
        $this->assertSame('https://example.test/site/index.php/update-locale?locale=fr&redirect=%2Fsite%2Findex.php%2Ftrend%3Fx%3D1', locale_switch_url('fr'));
    }

    public function test_at_a_domains_root_every_address_is_as_before(): void
    {
        $this->assertSame('', site_folder());
        $this->assertSame('/trend', locale_url('/trend'));
        $this->assertSame('/', locale_url('/'));
        $this->assertSame('/login', locale_url(route('login')));
        $this->assertSame('/trend?x=1#c', locale_url('/trend?x=1#c'));
        $this->assertSame('/admin/users', route('admin.users.index', [], false));
        $this->assertSame('/site/trend', site_path('/site/trend'), 'no folder: a path stays as it is');
        $this->assertSame('/fr/x', site_browser_path('/fr/x'));

        app()->setLocale('fr');
        $this->assertSame('/fr/trend', locale_url('/trend'));
        $this->assertSame('/fr/login', locale_url(route('login')));
        $this->assertSame('/fr', locale_url('/'));
        $_SERVER['REQUEST_URI'] = '/trend?x=1';
        $this->assertSame('/update-locale?locale=de&redirect=%2Ftrend%3Fx%3D1', locale_switch_url('de'));
        app()->setLocale('en');
        $this->assertSame(url('/profile/edit').'?section=security', url(ProfileController::sectionUrl('security')));

        $this->indexPhpLinks();
        $this->assertSame('/index.php/trend', locale_url('/trend'));
        $this->assertSame('/index.php', locale_url('/'));
        $this->assertSame('/index.php/admin/users', route('admin.users.index', [], false));
        app()->setLocale('vi');
        $this->assertSame('/index.php/vi/login', locale_url(route('login')));
        $this->assertSame('/index.php/update-locale?locale=fr&redirect=%2Findex.php%2Ftrend%3Fx%3D1', locale_switch_url('fr'));
    }

    public function test_the_url_generator_is_the_sites_and_shared(): void
    {
        $this->assertInstanceOf(SiteUrlGenerator::class, app('url'));
        $this->assertSame(app('url'), app('redirect')->getUrlGenerator());
        $this->assertSame(app('url'), URL::getFacadeRoot());

        // a signed address keeps validating in a folder (Laravel signs the whole address)
        $this->siteInFolder();
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), ['id' => 1, 'hash' => 'x']);
        $this->assertStringStartsWith('https://example.test/site/email/verify/1/x?expires=', $signed);
        $this->assertTrue(URL::hasValidSignature($this->folderRequest(substr($signed, strlen('https://example.test')))));
    }

    public function test_a_guest_is_sent_to_the_folders_sign_in_page_and_comes_back(): void
    {
        $this->siteInFolder();
        $member = User::factory()->create(['password' => 'password', 'language' => 'en']);

        // a member page in French: the French sign-in page of the folder (1.2.3 RC2: /site/site/login), and back
        $this->visit('GET', '/site/fr/history?sort=new')->assertRedirect('https://example.test/site/fr/login');
        $this->assertSame('https://example.test/site/fr/history?sort=new', session('url.intended'));
        $this->visit('POST', '/site/fr/login', ['username' => $member->username, 'password' => 'password'])
            ->assertRedirect('https://example.test/site/fr/history?sort=new');
        $this->assertAuthenticatedAs($member);

        // a plain member page: the plain sign-in page
        $this->post('https://example.test/site/logout');
        $this->flushSession();
        $this->visit('GET', '/site/profile/videos?status=public')->assertRedirect('https://example.test/site/login');
    }

    public function test_the_return_address_of_a_sign_in_link_is_the_page_in_the_folder(): void
    {
        $this->siteInFolder();
        $request = $this->folderRequest('/site/video/abc?t=3', ['DETECTED_LOCALE' => 'fr']);
        app()->instance('request', $request);
        app()->setLocale('fr');

        // the page as the browser has it comes and goes: ?redirect=/site/fr/video/abc
        $this->assertSame('/fr/video/abc?t=3', ReturnPath::safePath('/site/fr/video/abc?t=3', $request));
        $this->assertSame('/fr/video/abc?t=3', ReturnPath::safePath('https://example.test/site/fr/video/abc?t=3', $request));
        $this->assertNull(ReturnPath::safePath('/site/login', $request), 'a sign-in page is no page to come back to');
        $this->assertNull(ReturnPath::safePath('//evil.example/site/x', $request));
        $this->assertSame('https://example.test/site/fr/video/abc?t=3', ReturnPath::currentPath($request));
        $this->assertSame('https://example.test/site/fr/login?redirect=%2Fsite%2Ffr%2Fvideo%2Fabc%3Ft%3D3', ReturnPath::loginUrl(null, $request));

        // the sign-in page remembers it as Laravel's intended address, whole and once
        $this->visit('GET', '/site/fr/login?redirect='.rawurlencode('/site/fr/video/abc?t=3'))->assertOk();
        $this->assertSame('https://example.test/site/fr/video/abc?t=3', session('url.intended'));
    }

    public function test_the_language_switch_comes_back_to_the_page_in_the_folder(): void
    {
        $this->siteInFolder();

        $this->visit('GET', '/site/update-locale?locale=de&redirect='.rawurlencode('/site/trend?x=1'))->assertRedirect('https://example.test/site/de/trend?x=1');
        $this->visit('GET', '/site/update-locale?locale=en', [], ['Referer' => 'https://example.test/site/fr/library'])->assertRedirect('https://example.test/site/library');
        $this->visit('GET', '/site/update-locale?locale=ar&redirect='.rawurlencode('/site/index.php/fr/trend'))->assertRedirect('https://example.test/site/index.php/ar/trend');
        $this->visit('GET', '/site/update-locale?locale=fr&redirect='.rawurlencode('//evil.example/x'))->assertRedirect('https://example.test/site/fr');
    }

    public function test_the_page_head_and_the_scripts_name_the_folder(): void
    {
        $this->siteInFolder();

        $page = (string) $this->visit('GET', '/site/categories')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/site/categories">', $page);
        $this->assertStringContainsString('hreflang="en" href="https://example.test/site/categories"', $page);
        $this->assertStringContainsString('hreflang="fr" href="https://example.test/site/fr/categories"', $page);
        $this->assertStringContainsString('hreflang="x-default" href="https://example.test/site/categories"', $page);
        $this->assertStringContainsString('<link rel="manifest" href="/site/manifest.json">', $page);
        $this->assertStringContainsString('window.SITE_BASE = "/site"', $page);
        $this->assertDoesNotMatchRegularExpression('#(href|src|action)="/(?!site[/"?])#', $page, 'no address from the domain\'s root');
        $this->assertStringNotContainsString('/site/site/', $page);

        // (with a query: the test client takes a trailing slash off its address, a browser keeps it)
        $home = (string) $this->visit('GET', '/site/?a=1')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/site/">', $home);
        $this->assertStringContainsString('hreflang="en" href="https://example.test/site/"', $home, 'the home page\'s alternate is its canonical');
        $this->assertStringContainsString('hreflang="fr" href="https://example.test/site/fr"', $home);
    }

    public function test_the_error_pages_try_again_is_the_page_in_the_folder(): void
    {
        $this->siteInFolder();
        $request = $this->folderRequest('/site/trend?x=1', ['DETECTED_LOCALE' => 'fr']);
        app()->instance('request', $request);
        app()->setLocale('fr');

        $this->assertSame('https://example.test/site/fr/trend?x=1', locale_current_url());

        $html = view('errors.500')->render();
        $this->assertStringContainsString('href="https://example.test/site/fr/trend?x=1"', $html);
        $this->assertStringContainsString('href="https://example.test/site/fr"', $html, 'Go home');
    }

    public function test_mail_links_of_a_console_process_keep_the_folder_once(): void
    {
        // the queue worker and the scheduler: Laravel's console request is made from APP_URL (its folder is the base)
        $this->siteInFolder();
        $console = Request::create('https://example.test/site', 'GET', [], [], [], ['SCRIPT_NAME' => '/site', 'SCRIPT_FILENAME' => '/site']);
        app()->instance('request', $console);
        $member = User::factory()->create(['language' => 'fr']);

        app()->setLocale('fr');
        $this->assertSame('https://example.test/site/fr/profile/edit', MemberMail::link('user.edit'));
        $this->assertSame('/site/profile/edit', route('user.edit', [], false));
        $this->assertSame('https://example.test/site/fr/reset-password/t0ken?email='.rawurlencode($member->email), url(locale_url(route('password.reset', ['token' => 't0ken', 'email' => $member->email]))));
    }

    public function test_an_installed_site_on_a_local_address_checks_its_web_server_itself(): void
    {
        // R3-040: localhost and 127.0.0.1 are the example's address only while an installation is in progress
        $lock = storage_path('framework/testing/sb-installed-'.bin2hex(random_bytes(4)).'.json');
        $installed = $_ENV['APP_INSTALLED'] ?? null;

        try {
            ServerChecks::$installLock = $lock;
            $_ENV['APP_INSTALLED'] = 'false';
            config(['app.url' => 'http://localhost:8089/site']);
            $this->assertNull(ServerChecks::siteAddress(), 'an install in progress');

            file_put_contents($lock, '{}');
            $this->assertSame('http://localhost:8089/site', ServerChecks::siteAddress(), 'installed (the installer\'s lock)');
            @unlink($lock);

            $_ENV['APP_INSTALLED'] = 'true';
            config(['app.url' => 'http://127.0.0.1:8261']);
            $this->assertSame('http://127.0.0.1:8261', ServerChecks::siteAddress(), 'installed (APP_INSTALLED=true)');
            $this->assertSame('http://127.0.0.1:8261/_avdbcms/check/clean-urls/'.str_repeat('a', 32), ServerChecks::url((string) ServerChecks::siteAddress(), ServerChecks::CLEAN_URLS, str_repeat('a', 32)));
        } finally {
            @unlink($lock);
            ServerChecks::$installLock = null;
            if ($installed === null) {
                unset($_ENV['APP_INSTALLED']);
            } else {
                $_ENV['APP_INSTALLED'] = $installed;
            }
        }
    }

    public function test_the_session_cookie_belongs_to_the_sites_folder(): void
    {
        $path = config('session.path');

        $apply = new \ReflectionMethod(\App\Providers\EnvironmentServiceProvider::class, 'applyAppUrlDerivedDefaults');
        $provider = new \App\Providers\EnvironmentServiceProvider($this->app);

        try {
            $this->siteInFolder();
            config(['session.path' => '/']);
            $apply->invoke($provider);
            $this->assertSame('/site', config('session.path'));

            config(['session.path' => '/members']);
            $apply->invoke($provider);
            $this->assertSame('/members', config('session.path'), 'a path the owner set stays');

            URL::forceRootUrl('https://example.test');
            config(['app.url' => 'https://example.test', 'session.path' => '/']);
            $apply->invoke($provider);
            $this->assertSame('/', config('session.path'), 'a domain\'s root: as before');
        } finally {
            config(['session.path' => $path]);
        }
    }

    // The cold review of 29 Oct (opus-verifier): the points it found, each fixed and kept here.

    public function test_a_path_of_the_site_never_names_another_host(): void
    {
        $this->siteInFolder();

        // "/site//evil.example" gave "//evil.example", which browsers and the URL generator read as another host.
        $this->assertSame('/evil.example', site_path('/site//evil.example'));
        $this->assertSame('/evil.example', site_path('/site///evil.example'));
        $this->assertSame('/evil.example/x', site_path('/site/\\evil.example/x'));
        $this->assertSame('/', site_path('/site'));
        $this->assertSame('/?a=1', site_path('/site?a=1'));
        $this->assertSame('/fr/trend', site_path('/site/fr/trend'));
        $this->assertSame('/other/x', site_path('/other/x'), 'outside the folder: as it is (its callers check it)');
    }

    public function test_the_https_redirect_of_a_site_in_a_folder_stays_on_the_site(): void
    {
        config(['app.url' => 'http://example.test/site', 'site.force_https' => true]);
        URL::forceRootUrl('http://example.test/site');
        $env = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $server = ['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'REQUEST_URI' => '/site//evil.example'];
            $this->assertNull(SiteAddress::prepare($server, array_keys((array) config('languages')), public_path(), public_path('index.php')));

            $response = $this->withServerVariables(['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'SCRIPT_FILENAME' => public_path('index.php')])
                ->get('http://example.test'.$server['REQUEST_URI']);

            $response->assertStatus(301);
            $location = (string) $response->headers->get('Location');
            $this->assertStringStartsWith('https://example.test/site/', $location);
            $this->assertSame('example.test', parse_url($location, PHP_URL_HOST));
        } finally {
            $this->app['env'] = $env;
        }
    }

    public function test_notification_rows_link_to_their_click_address_once(): void
    {
        $vars = ['row' => ['id' => 'abc', 'url' => 'https://example.test/site/video/x', 'kind' => 'other', 'parts' => [['t' => 'Hello', 'b' => false]],
            'message' => 'Hello', 'is_read' => false, 'avatar' => '', 'iso' => '2026-10-29T00:00:00Z', 'when' => 'now'],
            'kindIcons' => [], 'kindBadge' => [], 'rowMenuLabel' => 'More', 'rowUnread' => 'Unread', 'rowOrigin' => 'https://example.test'];

        $this->siteInFolder();
        $this->assertStringContainsString('href="/site/profile/notifications/abc/click"', view('users.notifications.row', $vars)->render());

        $this->indexPhpLinks();
        $this->assertStringContainsString('href="/site/index.php/profile/notifications/abc/click"', view('users.notifications.row', $vars)->render());

        // A domain's root with /index.php links: "/index.php/index.php/..." up to 1.2.3 RC2.
        config(['app.url' => 'https://example.test']);
        URL::forceRootUrl('https://example.test');
        $this->assertStringContainsString('href="/index.php/profile/notifications/abc/click"', view('users.notifications.row', $vars)->render());

        app('url')->formatPathUsing(fn ($path) => $path);
        $this->assertStringContainsString('href="/profile/notifications/abc/click"', view('users.notifications.row', $vars)->render(), 'a domain\'s root: as before');
    }

    public function test_signed_links_of_a_site_in_a_folder_are_checked_in_both_forms(): void
    {
        $server = ['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'SCRIPT_FILENAME' => public_path('index.php'), 'HTTPS' => 'on'];

        // A link signed as /site/index.php/... and opened through it: public/index.php took /index.php off.
        $clean = Request::create('https://example.test/site/email/verify/1/abc?expires=1&signature=x', 'GET', [], [], [], $server);
        $this->assertSame('/site', $clean->getBasePath());
        $others = \App\Http\Middleware\ValidateSignature::otherAddresses($clean);
        $this->assertContains('https://example.test/site/index.php/email/verify/1/abc', $others);
        $this->assertNotContains('https://example.test/index.php/site/email/verify/1/abc', $others);

        $through = Request::create('https://example.test/site/index.php/email/verify/1/abc', 'GET', [], [], [], $server);
        $this->assertContains('https://example.test/site/email/verify/1/abc', \App\Http\Middleware\ValidateSignature::otherAddresses($through));

        $root = Request::create('https://example.test/email/verify/1/abc', 'GET', [], [], [], ['SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'), 'HTTPS' => 'on']);
        $this->assertContains('https://example.test/index.php/email/verify/1/abc', \App\Http\Middleware\ValidateSignature::otherAddresses($root), 'a domain\'s root: as before');
    }

    public function test_a_whole_address_of_the_site_loses_its_folder_once(): void
    {
        // A folder named like a page of the site: "https://example.test/profile/profile/export" was "/export".
        config(['app.url' => 'https://example.test/profile']);
        URL::forceRootUrl('https://example.test/profile');
        $request = Request::create('https://example.test/profile/profile/export', 'GET', [], [], [], ['SCRIPT_NAME' => '/profile/index.php',
            'PHP_SELF' => '/profile/index.php', 'SCRIPT_FILENAME' => public_path('index.php'), 'HTTPS' => 'on']);
        $sitePage = new \ReflectionMethod(\App\Providers\RouteServiceProvider::class, 'sitePage');

        $this->assertSame('/profile/export', $sitePage->invoke(null, $request, 'https://example.test/profile/profile/export'));
        $this->assertSame('/profile/export', $sitePage->invoke(null, $request, '/profile/profile/export'));
        $this->assertNull($sitePage->invoke(null, $request, 'https://elsewhere.example/profile/profile/export'));
    }

    public function test_a_script_address_with_a_php_segment_is_no_folder(): void
    {
        // PHP-FPM's cgi.fix_pathinfo can make SCRIPT_NAME "/index.php/x/index.php" of the address /index.php/x/index.php.
        $this->assertSame('', SiteAddress::folder(['SCRIPT_NAME' => '/index.php/x/index.php', 'REQUEST_URI' => '/index.php/x/index.php']));
        $this->assertSame('', SiteAddress::folder(['SCRIPT_NAME' => '/a.php/index.php', 'REQUEST_URI' => '/a.php/trend']));
        $this->assertSame('/site', SiteAddress::folder(['SCRIPT_NAME' => '/site/index.php', 'REQUEST_URI' => '/site/trend']));
    }

    public function test_the_settings_page_asks_its_catalog_from_the_sites_own_origin(): void
    {
        // A path (with the folder and /index.php where the links carry them), never APP_URL's origin: an admin who opened
        // the admin on another host or scheme would make a cross-origin call. At a domain's root: "/api/..." as before.
        $source = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));
        $this->assertStringContainsString("fetch(@json((string) parse_url(url('/api/daily-design/catalog'), PHP_URL_PATH))", $source);

        $this->assertSame('/api/daily-design/catalog', (string) parse_url(url('/api/daily-design/catalog'), PHP_URL_PATH));
        $this->siteInFolder();
        $this->indexPhpLinks();
        $this->assertSame('/site/index.php/api/daily-design/catalog', (string) parse_url(url('/api/daily-design/catalog'), PHP_URL_PATH));
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
     * A request of the site in the folder as public/index.php hands it over (the language taken off and kept in
     * DETECTED_LOCALE, /index.php taken off), sent through the kernel.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function visit(string $method, string $address, array $data = [], array $headers = []): TestResponse
    {
        $server = ['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'REQUEST_URI' => $address];
        $this->assertNull(SiteAddress::prepare($server, array_keys((array) config('languages')), public_path(), public_path('index.php')));

        foreach (['DETECTED_LOCALE', 'LOCALE_PREFIX', 'AVDBCLIP_INDEX_PHP_PREFIX'] as $key) {
            if (isset($server[$key])) {
                $_SERVER[$key] = $server[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }

        return $this->withServerVariables(['SCRIPT_NAME' => $server['SCRIPT_NAME'], 'PHP_SELF' => $server['PHP_SELF'], 'SCRIPT_FILENAME' => public_path('index.php')])
            ->call($method, 'https://example.test'.$server['REQUEST_URI'], $data, [], [], $this->transformHeadersToServerVars($headers));
    }

    /** @param  array<string, string>  $extra */
    private function folderRequest(string $address, array $extra = []): Request
    {
        foreach ($extra as $key => $value) {
            $_SERVER[$key] = $value;
        }

        return Request::create('https://example.test'.$address, 'GET', [], [], [], ['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'), 'HTTPS' => 'on']);
    }
}
