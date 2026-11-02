<?php

namespace Tests\Feature;

use App\Providers\EnvironmentServiceProvider;
use App\Support\CleanUrls;
use App\Support\Environment;
use App\Support\ServerChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Round 1b R1B-007: on any nginx host outside four panels every link carried /index.php although the documented config
 * makes clean addresses work (canonical, og:url, sitemap <loc>, menus, forms), the home page's language alternates were
 * /fr/index.php (404 on nginx), config('app.clean_urls') was read but defined nowhere and the Health check said the server
 * had no rewrite rules. Now: APP_CLEAN_URLS (auto|true|false, config/app.php) overrides; with auto, nginx gets clean links
 * only after the site checked that a clean address reaches it with status 200 (App\Support\ServerChecks), /index.php/...
 * everywhere else (the fleet's nodes 2, 4 and 7), every other server as up to 1.2.2; the alternates keep the language
 * after /index.php. The web server is simulated through SERVER_SOFTWARE; the check's answer through its file.
 */
class CleanUrlsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEYS = ['SERVER_SOFTWARE', 'REQUEST_URI', 'AVDBCLIP_USED_INDEX_PHP', 'AVDBCLIP_ROOT_FALLBACK', 'AVDBCLIP_INDEX_PHP_PREFIX', 'LOCALE_PREFIX', 'HOSTING_PANEL'];

    /** @var array<string, mixed> */
    private array $server = [];

    private string $state;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SERVER_KEYS as $key) {
            $this->server[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }

        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->state = storage_path('framework/testing/clean-urls-'.bin2hex(random_bytes(4)).'.json');
        ServerChecks::$stateFile = $this->state;
        ServerChecks::reset();
        Environment::reset();
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

        @unlink($this->state);
        ServerChecks::$stateFile = null;
        ServerChecks::reset();
        Environment::reset();

        parent::tearDown();
    }

    public static function settings(): array
    {
        return [
            'not set' => [null, CleanUrls::AUTO],
            'auto' => ['auto', CleanUrls::AUTO],
            'true (env() gives a boolean)' => [true, CleanUrls::ON],
            'false (env() gives a boolean)' => [false, CleanUrls::OFF],
            '1' => ['1', CleanUrls::ON],
            'off' => ['off', CleanUrls::OFF],
            'NO' => ['NO', CleanUrls::OFF],
            'anything else' => ['maybe', CleanUrls::AUTO],
        ];
    }

    #[DataProvider('settings')]
    public function test_app_clean_urls_is_read_from_config_app(mixed $value, string $setting): void
    {
        config(['app.clean_urls' => $value]);

        $this->assertSame($setting, CleanUrls::setting());
    }

    public function test_the_key_is_defined_with_auto_as_its_default(): void
    {
        $config = (string) file_get_contents(config_path('app.php'));

        $this->assertStringContainsString("'clean_urls' => env('APP_CLEAN_URLS', 'auto')", $config);
    }

    public function test_nginx_without_a_check_keeps_index_php_and_the_alternates_put_the_language_after_it(): void
    {
        $this->serve('nginx/1.24.0');

        $html = $this->home();

        $this->assertStringContainsString('<link rel="canonical" href="'.$this->base().'/index.php">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="fr" href="'.$this->base().'/index.php/fr">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.$this->base().'/index.php">', $html);
        $this->assertDoesNotMatchRegularExpression('#hreflang="[a-z-]+" href="'.preg_quote($this->base(), '#').'/[a-z]{2}(-[A-Za-z]+)?/index\.php#', $html);
        $this->assertSame('not_checked', config('app.clean_url_reason'));
    }

    public function test_nginx_gets_clean_links_once_the_site_checked_that_clean_addresses_reach_it(): void
    {
        $this->answer(ServerChecks::YES);
        $this->serve('nginx/1.24.0');

        $html = $this->home();

        $this->assertStringContainsString('<link rel="canonical" href="'.$this->base().'/">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="fr" href="'.$this->base().'/fr">', $html);
        $this->assertStringNotContainsString('href="'.$this->base().'/index.php', $html);
        $this->assertSame('/login', parse_url(route('login'), PHP_URL_PATH));
        $this->assertSame('/fr/trend', $this->localeUrl('fr', '/trend'));
    }

    public function test_a_proven_site_gives_clean_links_also_to_a_request_that_came_in_through_index_php(): void
    {
        $this->answer(ServerChecks::YES);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_USED_INDEX_PHP' => true]);

        $this->assertFalse((bool) config('app.clean_url_fallback'));
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertFalse(index_php_links(), 'locale_url() follows the same answer');
    }

    public function test_the_admin_area_and_the_sign_in_page_keep_index_php_when_opened_through_it_on_nginx(): void
    {
        // Round 1b review BWR-01: with a "yes" that went out of date (the rewrite rule removed since), every clean link was
        // nginx's 404 page and the admin had no way back: /index.php/admin/preflight showed a Re-probe form that posted to
        // the clean /admin/preflight/refresh. Pages no search engine indexes keep the form their request came by.
        $this->answer(ServerChecks::YES);

        foreach (['/admin/preflight', '/admin', '/login', '/logout'] as $page) {
            $this->serve('nginx/1.24.0', ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => $page]);
            $this->assertSame('/index.php/admin/preflight/refresh', parse_url(route('admin.preflight.refresh'), PHP_URL_PATH), $page);
            $this->assertSame('/index.php/login', parse_url(route('login.perform'), PHP_URL_PATH), $page);
            $this->assertTrue(index_php_links(), $page.': locale_url() follows');
            $this->assertSame('via_index_php', config('app.clean_url_reason'), $page);
            $this->assertSame([false, 'checked_yes'], CleanUrls::decide(false), 'the Health check still describes the site');
        }

        // The root index.php's form of it (nginx with the web root at the project folder).
        $this->serve('nginx/1.24.0', ['AVDBCLIP_USED_INDEX_PHP' => true, 'AVDBCLIP_ROOT_FALLBACK' => true, 'REQUEST_URI' => '/admin/preflight']);
        $this->assertSame('/index.php/admin/preflight', parse_url(route('admin.preflight.index'), PHP_URL_PATH));

        // Pages search engines index stay clean on a proven site (old /index.php/... links converge to the clean form).
        $this->serve('nginx/1.24.0', ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => '/trend']);
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('checked_yes', config('app.clean_url_reason'));

        // The admin area opened at its clean address: clean (the rule works right now).
        $this->serve('nginx/1.24.0', ['REQUEST_URI' => '/admin/preflight']);
        $this->assertSame('/admin/preflight/refresh', parse_url(route('admin.preflight.refresh'), PHP_URL_PATH));

        // Apache and the others: as up to 1.2.2 (no check there, nothing goes out of date).
        $this->serve('Apache/2.4.58 (Ubuntu)', ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => '/admin/preflight']);
        $this->assertSame('/admin/preflight/refresh', parse_url(route('admin.preflight.refresh'), PHP_URL_PATH));
    }

    public function test_addresses_built_from_the_request_get_index_php_back_where_the_site_needs_it(): void
    {
        $base = $this->base();
        $host = (string) parse_url($base, PHP_URL_HOST);

        // A clean site, a clean request: nothing changes.
        $this->answer(ServerChecks::YES);
        $this->serve('nginx/1.24.0', ['REQUEST_URI' => '/trend']);
        $this->assertSame($base.'/trend?page=2', CleanUrls::requestForm($base.'/trend?page=2'));
        $this->assertSame('/trend', CleanUrls::requestForm('/trend'));

        // The site's links carry /index.php (a server without rewrite rules): it goes in after the host.
        $this->answer(ServerChecks::NO);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => '/trend']);
        $this->assertSame($base.'/index.php/trend?page=2', CleanUrls::requestForm($base.'/trend?page=2'));
        $this->assertSame($base.'/index.php', CleanUrls::requestForm($base), 'the home page as request()->url() gives it');
        $this->assertSame($base.'/index.php?q=1', CleanUrls::requestForm($base.'/?q=1'));
        $this->assertSame('/index.php/trend?x=1', CleanUrls::requestForm('/trend?x=1'));
        $this->assertSame('/index.php', CleanUrls::requestForm('/'));
        $this->assertSame($base.'/index.php/trend', CleanUrls::requestForm($base.'/index.php/trend'), 'never twice');
        $this->assertSame('/index.php/fr', CleanUrls::requestForm('/index.php/fr'));
        $this->assertSame('/index.php/index.phpx', CleanUrls::requestForm('/index.phpx'), 'only /index.php as a segment of its own');
        $this->assertSame('https://elsewhere.example/trend', CleanUrls::requestForm('https://elsewhere.example/trend'), 'another host');
        $this->assertSame('//elsewhere.example/trend', CleanUrls::requestForm('//elsewhere.example/trend'));
        $this->assertNotSame('', $host);

        // A site in a sub folder: /index.php after the folder, never in front of it; another folder: as it is.
        $previous = config('app.url');
        config(['app.url' => 'https://example.test/site']);
        try {
            $this->assertSame('https://example.test/site/index.php/trend?page=2', CleanUrls::requestForm('https://example.test/site/trend?page=2'));
            $this->assertSame('https://example.test/site/index.php', CleanUrls::requestForm('https://example.test/site'));
            $this->assertSame('/site/index.php?q=1', CleanUrls::requestForm('/site?q=1'));
            $this->assertSame('/site/index.php/trend', CleanUrls::requestForm('/site/index.php/trend'), 'already there');
            $this->assertSame('/other/trend', CleanUrls::requestForm('/other/trend'));
        } finally {
            config(['app.url' => $previous]);
        }

        // A proven site, but this request came in through /index.php: its own addresses keep that form (it reached the
        // site, a clean address only while the rule is there).
        $this->answer(ServerChecks::YES);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => '/trend']);
        $this->assertSame($base.'/index.php/trend', CleanUrls::requestForm($base.'/trend'));

        // Pagination: Laravel builds "page 2" from the request's address.
        $this->answer(ServerChecks::NO);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => '/trend']);
        $this->assertStringContainsString('/index.php', \Illuminate\Pagination\Paginator::resolveCurrentPath());
        // As the query builder's paginate() makes it (its path is the resolved current path).
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(range(1, 30), 30, 10, 1, ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]);
        $this->assertStringContainsString('/index.php?page=2', (string) $paginator->url(2));
        $this->assertStringContainsString('/index.php', \App\Support\AdminPagination::collection(range(1, 30), 10)->url(2));
    }

    public function test_nginx_without_rewrite_rules_keeps_index_php_everywhere(): void
    {
        // The fleet's nodes 2, 4 and 7: the check got nginx's own 404 page for the clean address.
        $this->answer(ServerChecks::NO);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_ROOT_FALLBACK' => true, 'AVDBCLIP_USED_INDEX_PHP' => true]);

        $this->assertSame('/index.php/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('/index.php/login', parse_url(route('login'), PHP_URL_PATH));
        $this->assertTrue(index_php_links());
        $this->assertSame('checked_no', config('app.clean_url_reason'));

        $sitemap = $this->page('/sitemap-pages-fr.xml');
        $this->assertStringContainsString('<loc>'.$this->base().'/index.php/fr</loc>', $sitemap);
        $this->assertStringNotContainsString('/fr/index.php', $sitemap);
        // The pages' own paths come from route(..., false), which already carries /index.php: never twice.
        $this->assertStringContainsString('<loc>'.$this->base().'/index.php/fr/trend</loc>', $sitemap);
        $this->assertStringNotContainsString('/index.php/fr/index.php', $sitemap);
    }

    public function test_the_page_a_sign_in_returns_to_keeps_index_php_on_a_server_without_rewrite_rules(): void
    {
        // The auth middleware remembers the page as the request saw it: public/index.php took /index.php off, so the
        // member landed on nginx's 404 page after signing in (box: runs/box/observation_intended_url_norw.txt).
        $this->answer(ServerChecks::NO);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_USED_INDEX_PHP' => true]);
        $this->assertSame($this->base().'/index.php/profile', $this->returnAddressAfterLoginPage($this->base().'/profile'));
        $this->assertSame($this->base().'/index.php/fr/profile', $this->returnAddressAfterLoginPage($this->base().'/index.php/fr/profile'), 'kept as it is');

        // A site with clean addresses keeps the clean one.
        $this->answer(ServerChecks::YES);
        $this->serve('nginx/1.24.0');
        $this->assertSame($this->base().'/profile', $this->returnAddressAfterLoginPage($this->base().'/profile'));
    }

    public function test_a_console_process_puts_the_web_servers_form_into_its_mails(): void
    {
        // The queue worker and the scheduler's commands have no web server: the links of their mails follow the answer
        // the web requests got for this site (an /index.php-only server: /index.php/... in the mails too).
        $this->answer(ServerChecks::NO);
        $this->console();
        $this->assertSame('/index.php/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('checked_no', config('app.clean_url_reason'));

        $this->answer(ServerChecks::YES);
        $this->console();
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));

        // Without an answer: clean, as up to 1.2.2.
        @unlink($this->state);
        $this->console();
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('server_rewrites', config('app.clean_url_reason'));
    }

    public function test_a_panel_that_writes_the_rule_stays_clean_until_a_check_says_otherwise(): void
    {
        $_SERVER['HOSTING_PANEL'] = 'cloudpanel';
        $this->serve('nginx/1.24.0');
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('server_rewrites', config('app.clean_url_reason'));

        $this->answer(ServerChecks::NO);
        $this->serve('nginx/1.24.0');
        $this->assertSame('/index.php/trend', parse_url(url('/trend'), PHP_URL_PATH));
    }

    public function test_apache_and_php_s_server_keep_clean_links_as_before(): void
    {
        $this->serve('Apache/2.4.58 (Ubuntu)');
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('server_rewrites', config('app.clean_url_reason'));

        // A request that came in through /index.php on a server nobody checked: /index.php/... as up to 1.2.2.
        $this->serve('Apache/2.4.58 (Ubuntu)', ['AVDBCLIP_USED_INDEX_PHP' => true]);
        $this->assertSame('/index.php/trend', parse_url(url('/trend'), PHP_URL_PATH));

        // php -S: clean as before (its router sends every address to index.php).
        $this->serve('PHP 8.3.6 Development Server');
        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
    }

    public function test_app_clean_urls_false_keeps_index_php_on_any_server(): void
    {
        config(['app.clean_urls' => false]);
        $this->serve('Apache/2.4.58 (Ubuntu)');

        $this->assertSame('/index.php/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('setting_off', config('app.clean_url_reason'));
    }

    public function test_app_clean_urls_true_gives_clean_links_whatever_a_check_said(): void
    {
        config(['app.clean_urls' => true]);
        $this->answer(ServerChecks::NO);
        $this->serve('nginx/1.24.0', ['AVDBCLIP_USED_INDEX_PHP' => true]);

        $this->assertSame('/trend', parse_url(url('/trend'), PHP_URL_PATH));
        $this->assertSame('setting_on', config('app.clean_url_reason'));
    }

    public function test_an_answer_made_on_another_server_counts_for_nothing(): void
    {
        $this->answer(ServerChecks::YES);
        $state = json_decode((string) file_get_contents($this->state), true);
        $state[ServerChecks::CLEAN_URLS]['server'] = 'another-server';
        file_put_contents($this->state, json_encode($state));
        ServerChecks::reset();

        $this->serve('nginx/1.24.0');

        $this->assertSame('/index.php/trend', parse_url(url('/trend'), PHP_URL_PATH));
    }

    public function test_a_clean_arrival_is_told_apart_from_the_home_page_and_index_php(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertFalse(CleanUrls::arrivedClean(), 'the bare home page reaches index.php on any server');

        $_SERVER['REQUEST_URI'] = '/trend?x=1';
        $this->assertTrue(CleanUrls::arrivedClean());

        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['LOCALE_PREFIX'] = '/fr';
        $this->assertTrue(CleanUrls::arrivedClean(), '/fr needs the rewrite rule');
        unset($_SERVER['LOCALE_PREFIX']);

        $_SERVER['REQUEST_URI'] = '/trend';
        $_SERVER['AVDBCLIP_INDEX_PHP_PREFIX'] = true;
        $this->assertFalse(CleanUrls::arrivedClean(), 'public/index.php took /index.php off');
    }

    /** Boot the provider's link decision again as on this server (the app booted in the console). */
    private function serve(string $software, array $server = []): void
    {
        foreach (self::SERVER_KEYS as $key) {
            if ($key !== 'HOSTING_PANEL') {
                unset($_SERVER[$key]);
            }
        }

        $_SERVER['SERVER_SOFTWARE'] = $software;

        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }

        Environment::reset();
        ServerChecks::reset();
        config(['app.clean_url_fallback' => false, 'app.clean_url_reason' => null]);
        app('url')->formatPathUsing(fn ($path) => $path);

        $method = new \ReflectionMethod(EnvironmentServiceProvider::class, 'applyCleanUrlFallback');
        $method->invoke(new EnvironmentServiceProvider($this->app));
    }

    /** Boot the link decision again as a console process (no SERVER_SOFTWARE: the queue worker, the scheduler). */
    private function console(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }

        Environment::reset();
        ServerChecks::reset();
        config(['app.clean_url_fallback' => false, 'app.clean_url_reason' => null]);
        app('url')->formatPathUsing(fn ($path) => $path);

        $method = new \ReflectionMethod(EnvironmentServiceProvider::class, 'applyCleanUrlFallback');
        $method->invoke(new EnvironmentServiceProvider($this->app));
    }

    private function answer(string $answer): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        Environment::reset();
        ServerChecks::reset();
        ServerChecks::record(ServerChecks::CLEAN_URLS, $answer, 'test');
        ServerChecks::reset();
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function home(): string
    {
        return $this->page('/');
    }

    /**
     * A GET handled by the kernel as public/index.php hands it over: the test client would build its address with url(),
     * which now carries /index.php (public/index.php takes it off before Laravel on a real server).
     */
    private function page(string $path): string
    {
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create($this->base().$path, 'GET'));
        $this->assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getContent();
    }

    /** The page the session remembers for after the sign-in, once the sign-in page was shown (ReturnPath). */
    private function returnAddressAfterLoginPage(string $intended): string
    {
        $session = $this->app['session']->driver('array');
        $session->start();
        $session->put('url.intended', $intended);
        $request = \Illuminate\Http\Request::create($this->base().'/login', 'GET');
        $request->setLaravelSession($session);

        \App\Http\Controllers\Auth\ReturnPath::rememberFrom($request);

        return (string) $session->get('url.intended');
    }

    /** locale_url() for another language than the current one. */
    private function localeUrl(string $locale, string $path): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return locale_url($path);
        } finally {
            app()->setLocale($previous);
        }
    }
}
