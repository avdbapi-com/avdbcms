<?php

namespace Tests\Feature;

use App\Http\Middleware\Security;
use App\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Always use the secure address (https)" (1.2.2): with an https APP_URL it used to redirect no http visit at all
 * (Environment::isSecureRequest() counts the APP_URL). Now the visitor's own scheme decides (Environment::visitorScheme()),
 * and it redirects only where that cannot loop:
 *
 *  - redirected: a plain http request straight from the visitor (no proxy in front), Cloudflare's CF-Visitor scheme http
 *    (from Cloudflare's own address, or a local proxy passing its traffic on), X-Forwarded-Proto http from a proxy the
 *    owner listed by its address (the setting, SITE_TRUSTED_PROXIES or TRUSTED_PROXIES);
 *  - never redirected: https, Cloudflare Flexible SSL (CF-Visitor https while the origin sees http), any proxy header
 *    that says https, a proxy that says nothing (a loopback or private address, headers from an unlisted address,
 *    Cloudflare without CF-Visitor), X-Forwarded-Proto http from a proxy nobody listed (also a local one: a CDN that
 *    ended TLS, then a local proxy that says http), a CF-Visitor that does not come from Cloudflare, the setting off,
 *    not production;
 *  - an http APP_URL keeps the behaviour of before (a request that does not say https is redirected);
 *  - the redirect leaves a Secure cookie: a browser that brings it back (it came over https, a proxy hid that) is never
 *    sent again, so even a proxy that hides https without any trace cannot make it loop;
 *  - for clients without cookies (search bots) the same guard lives on the server (Security::cameBack()), and only where
 *    a loop is possible: on a server that https requests never reach as https. There, a redirect that rests on a guess
 *    ("straight from the visitor", or nothing says it) is remembered for a few minutes per client and address, and the
 *    same client asking for the same http address again gets the page (each address costs it one redirect, never a
 *    loop). On a server that handles TLS itself (an https request for the site's address arrived with the server's
 *    HTTPS variable on), http is redirected every time, as 1.2.2 did, and nothing is written. The guard keeps a fixed
 *    number of cache entries, whatever the clients send. Cloudflare's CF-Visitor and a listed proxy's X-Forwarded-Proto
 *    are no guess: redirected every time.
 *
 * A site that never set its trusted proxies has none ('': App\Http\Middleware\TrustProxies then believes only this machine,
 * private networks and Cloudflare, 1.2.3); an owner may also write '*' (whoever connects). Every case runs under both:
 * neither may make a visitor a proxy, or a proxy a listed one.
 */
class ForceHttpsRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** An address of Cloudflare (173.245.48.0/20). */
    private const CLOUDFLARE = '173.245.48.10';

    private const VISITOR = '203.0.113.9';

    /** The trusted proxies of a site that never set a list (AppServiceProvider: $cdn['trusted_proxies'] ?? ''). */
    private const DEFAULT_TRUST = '';

    /** An owner who wrote '*': whoever connects is trusted (as every site was before 1.2.3). */
    private const STAR_TRUST = '*';

    protected function setUp(): void
    {
        parent::setUp();

        // A probe page of its own, first in the list (the video address catch-all would answer it otherwise).
        $routes = new RouteCollection();
        $routes->add(Route::middleware('web')->get('/https-probe', fn () => 'probe'));

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== 'https-probe') {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
        config(['site.force_https' => true, 'app.url' => 'https://example.test', 'site.trusted_proxies' => self::DEFAULT_TRUST]);
        $this->app['env'] = 'production';
    }

    protected function tearDown(): void
    {
        unset($_ENV['SITE_TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    public static function redirected(): array
    {
        return self::underEachTrust([
            'straight from the visitor, no proxy in front' => [self::VISITOR, []],
            'straight from an IPv4 visitor on a dual-stack socket' => ['::ffff:'.self::VISITOR, []],
            'straight from an IPv6 visitor' => ['2001:db8::9', []],
            'Cloudflare: the visitor used http' => [self::CLOUDFLARE, ['CF-Ray' => '8d1f0c0e5a1b2c3d-AMS', 'CF-Visitor' => '{"scheme":"http"}', 'X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR]],
            'Cloudflare through a local proxy: the visitor used http' => ['127.0.0.1', ['CF-Ray' => '8d1f0c0e5a1b2c3d-AMS', 'CF-Connecting-IP' => self::VISITOR, 'CF-Visitor' => '{"scheme":"http"}']],
            'a listed proxy says http' => ['10.0.0.5', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR], '10.0.0.5'],
            'a proxy in a listed range says http' => ['10.0.0.5', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR], '10.0.0.0/8'],
            'a listed proxy next to * says http' => ['10.0.0.5', ['X-Forwarded-Proto' => 'http'], "*\n10.0.0.5"],
            'a listed public proxy says http, list separated by spaces' => ['198.51.100.7', ['X-Forwarded-Proto' => 'http'], '192.0.2.1 198.51.100.7'],
            'a listed local proxy says http' => ['127.0.0.1', ['X-Forwarded-Proto' => 'http'], '127.0.0.1'],
        ]);
    }

    #[DataProvider('redirected')]
    public function test_an_http_visit_goes_to_https_where_that_cannot_loop(string $remote, array $headers, string $trusted): void
    {
        config(['site.trusted_proxies' => $trusted]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $remote])->get('http://example.test/https-probe?x=1', $headers);

        $response->assertStatus(301);
        $this->assertSame('https://example.test/https-probe?x=1', $response->headers->get('Location'));
        // The same request, told directly (no middleware: the answer depends on the setting alone).
        $this->assertSame('http', Environment::visitorScheme(Request::create('http://example.test/', 'GET', server: ['REMOTE_ADDR' => $remote] + $this->server($headers))));
    }

    public static function notRedirected(): array
    {
        return self::underEachTrust([
            'Cloudflare Flexible SSL: the visitor used https' => [self::CLOUDFLARE, ['CF-Ray' => '8d1f0c0e5a1b2c3d-AMS', 'CF-Visitor' => '{"scheme":"https"}', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => self::VISITOR]],
            'Cloudflare Flexible SSL, X-Forwarded-Proto rewritten to http by a proxy between' => ['127.0.0.1', ['CF-Ray' => '8d1f0c0e5a1b2c3d-AMS', 'CF-Visitor' => '{"scheme":"https"}', 'X-Forwarded-Proto' => 'http']],
            'Cloudflare without CF-Visitor' => [self::CLOUDFLARE, ['CF-Ray' => '8d1f0c0e5a1b2c3d-AMS']],
            'a proxy on the server that says nothing' => ['127.0.0.1', []],
            'a proxy on the private network that says nothing' => ['10.0.0.5', []],
            'a CDN that ended TLS, then a proxy on the server that says http (not listed)' => ['127.0.0.1', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR]],
            'a proxy on the private network that says http (not listed)' => ['10.0.0.5', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR]],
            'a listed proxy that names no scheme' => ['10.0.0.5', ['X-Forwarded-For' => self::VISITOR], '10.0.0.5'],
            'a listed proxy that says https' => ['10.0.0.5', ['X-Forwarded-Proto' => 'https'], '10.0.0.5'],
            'a listed proxy that says http, from another address' => ['10.0.0.6', ['X-Forwarded-Proto' => 'http'], '10.0.0.5'],
            'X-Forwarded-Proto http from an address nobody listed' => ['198.51.100.7', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR]],
            'a proxy that only adds X-Forwarded-For' => ['198.51.100.7', ['X-Forwarded-For' => self::VISITOR]],
            'a CDN that only adds CDN-Loop' => ['198.51.100.7', ['CDN-Loop' => 'akamai;v=1.0']],
            'a CDN that only adds Via' => ['198.51.100.7', ['Via' => '1.1 edge (proxy)']],
            'CloudFront: the visitor used https' => ['198.51.100.7', ['CloudFront-Forwarded-Proto' => 'https', 'X-Amz-Cf-Id' => 'abc']],
            'CF-Visitor http that does not come from Cloudflare' => ['198.51.100.7', ['CF-Visitor' => '{"scheme":"http"}']],
            'X-Forwarded-Ssl on' => ['10.0.0.5', ['X-Forwarded-Ssl' => 'on']],
            'Forwarded proto=https' => ['10.0.0.5', ['Forwarded' => 'for=203.0.113.9;proto=https;host=example.test']],
            'X-Forwarded-Port 443' => ['10.0.0.5', ['X-Forwarded-Port' => '443']],
            'a connection address that is not an address' => ['unix:', []],
        ]);
    }

    #[DataProvider('notRedirected')]
    public function test_nothing_happens_where_a_redirect_could_loop_or_is_not_needed(string $remote, array $headers, string $trusted): void
    {
        config(['site.trusted_proxies' => $trusted]);

        $this->withServerVariables(['REMOTE_ADDR' => $remote])->get('http://example.test/https-probe', $headers)
            ->assertOk()->assertSee('probe');
    }

    public function test_a_proxy_listed_in_the_env_file_counts_like_one_in_the_setting(): void
    {
        $headers = ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR];

        // Not listed anywhere: not believed (the built-in trusted hops are no listing of the owner's).
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('http://example.test/https-probe', $headers)->assertOk();

        foreach (['SITE_TRUSTED_PROXIES', 'TRUSTED_PROXIES'] as $key) {
            $_ENV[$key] = '10.0.0.5, 10.0.0.6';
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('http://example.test/https-probe', $headers)->assertStatus(301);
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://example.test/https-probe', $headers)->assertOk();
            unset($_ENV[$key]);
        }

        // A '*' in .env lists nobody either.
        $_ENV['SITE_TRUSTED_PROXIES'] = '*';
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('http://example.test/https-probe', $headers)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe')->assertStatus(301);
    }

    public function test_a_browser_that_followed_the_redirect_over_https_is_never_sent_again(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe');

        $response->assertStatus(301)->assertCookie('avdbcms_https_hop', '1');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'avdbcms_https_hop');
        $this->assertTrue($cookie->isSecure(), 'a browser keeps it only from an answer it got over https');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/', $cookie->getPath());

        // A cookie the site did not write (not encrypted with its key) counts for nothing (another address: the same one
        // again would be the cookie-less guard's case, see the tests below).
        $this->withUnencryptedCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->get('http://example.test/https-probe?forged=1')->assertStatus(301);
        $this->unencryptedCookies = [];

        // A proxy in front hid that the visitor used https (it ended TLS and passed the request on with no header at
        // all): the browser comes back with the cookie and gets the page, not the same redirect again.
        $this->withCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->get('http://example.test/https-probe')->assertOk()->assertSee('probe');
        $this->withCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])
            ->get('http://example.test/https-probe', ['CF-Ray' => 'x', 'CF-Visitor' => '{"scheme":"http"}'])->assertOk();
    }

    /** Security plan S15: the redirect always goes to a path on this site, never to "//another-host". */
    public function test_the_redirect_never_leaves_the_site(): void
    {
        $cases = [
            '//evil.example/x' => 'https://example.test/evil.example/x',
            '//evil.example' => 'https://example.test/evil.example',
            '/trend' => 'https://example.test/trend',
        ];

        foreach ($cases as $path => $location) {
            $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test'.$path)
                ->assertStatus(301)->assertHeader('Location', $location);
        }

        // A raw backslash reaches PHP as the web server got it (the test client refuses to build such an address).
        $request = Request::create('http://example.test/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->server->set('REQUEST_URI', '/\\evil.example/x');
        $this->app->instance('request', $request);
        app('url')->setRequest($request);

        $response = app(Security::class)->handle($request, fn () => response('page'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.test/evil.example/x', $response->headers->get('Location'));
    }

    public function test_an_https_visit_is_never_redirected(): void
    {
        foreach ([self::DEFAULT_TRUST, self::STAR_TRUST] as $trusted) {
            config(['site.trusted_proxies' => $trusted]);
            $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('https://example.test/https-probe')->assertOk();
        }
    }

    public function test_nothing_happens_when_the_setting_is_off_or_outside_production(): void
    {
        config(['site.force_https' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe')->assertOk();

        config(['site.force_https' => true]);
        $this->app['env'] = 'local';
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe')->assertOk();
    }

    public function test_an_http_site_address_keeps_the_behaviour_of_before(): void
    {
        config(['app.url' => 'http://example.test']);

        foreach ([self::DEFAULT_TRUST, self::STAR_TRUST] as $trusted) {
            config(['site.trusted_proxies' => $trusted]);
            // Each request asks for an address of its own: the same client asking for the same http address again right
            // after its redirect is the cookie-less guard's case (see the tests below), not this one.
            // (Never an empty query value: StripEmptyParams would answer that one with its own redirect.)
            $url = fn (string $case): string => 'http://example.test/https-probe?trust='.($trusted === '' ? 'none' : 'all').'&case='.$case;

            // A request that does not say https: redirected, as before 1.2.2 (also behind a proxy that says nothing or
            // says http).
            $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get($url('direct'))->assertStatus(301);
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($url('local'))->assertStatus(301);
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($url('local-http'), ['X-Forwarded-Proto' => 'http'])->assertStatus(301);

            // One that says https: not redirected, also when only CF-Visitor says so.
            $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])->get($url('cloudflare'), ['CF-Ray' => 'x', 'CF-Visitor' => '{"scheme":"https"}'])->assertOk();
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get($url('proxy'), ['X-Forwarded-Proto' => 'https'])->assertOk();
        }
    }

    /**
     * A proxy in front that ended TLS, sends no header at all and passes the visitor's address on (REMOTE_ADDR is the
     * visitor's): every request looks like a plain http visit straight from the visitor, also the one that followed the
     * redirect over https, and no request ever reaches this server as https. A browser brings the hop cookie back; a
     * client that keeps no cookies (a search bot) was sent to the same address again and again. Now it gets the page
     * when it comes back for the same address: each address costs it one redirect, never a loop.
     */
    public function test_behind_a_proxy_that_hides_https_a_client_without_cookies_gets_each_page_after_one_redirect(): void
    {
        $bot = ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        $get = fn (string $path) => $this->withServerVariables(['REMOTE_ADDR' => '66.249.66.1'])->get('http://example.test'.$path, $bot);

        $first = $get('/https-probe?page=1');
        $first->assertStatus(301);
        $this->assertSame('https://example.test/https-probe?page=1', $first->headers->get('Location'));

        // It followed the redirect (over https, which the proxy hid): the page, not the same redirect again.
        $get('/https-probe?page=1')->assertOk()->assertSee('probe');
        $get('/https-probe?page=1')->assertOk();

        // Another address: a redirect of its own first (coming back for one address is no pass for any other).
        $get('/https-probe?page=2')->assertStatus(301);
        $get('/https-probe?page=2')->assertOk();

        // A few minutes later, the same address is redirected once again (it may have come straight over http by then).
        $this->travel(Security::HOP_WINDOW + 1)->seconds();
        $get('/https-probe?page=1')->assertStatus(301);
        $get('/https-probe?page=1')->assertOk();
    }

    /**
     * A server with its own certificate (no proxy in front, the case of most sites): an https request for the site's
     * address arrives with the server's HTTPS variable on, so a redirect to https cannot loop here. Every http visit is
     * redirected, as 1.2.2 did: the same address again, any other address, a day later, and neighbours behind one
     * address (a shared NAT) with the same browser and language never get each other's pass. The guard writes nothing.
     */
    public function test_on_a_server_that_handles_https_itself_every_http_visit_is_redirected(): void
    {
        $curl = ['User-Agent' => 'curl/8.4.0'];
        $http = fn (string $path, string $ip = self::VISITOR, array $headers = []) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('http://example.test'.$path, $headers + $curl);

        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('https://example.test/https-probe?tls=1', $curl)->assertOk();

        $http('/https-probe?page=1')->assertStatus(301)->assertHeader('Location', 'https://example.test/https-probe?page=1');
        $http('/https-probe?page=1')->assertStatus(301);
        $http('/https-probe?login=1')->assertStatus(301);
        $this->travel(23)->hours();
        $http('/https-probe?account=1')->assertStatus(301);
        $http('/https-probe?account=1')->assertStatus(301);

        $phone = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Accept-Language' => 'en-US,en;q=0.9'];
        $http('/https-probe?shared=1', '100.64.10.20', $phone)->assertStatus(301);
        $http('/https-probe?shared=1', '100.64.10.20', $phone)->assertStatus(301);
        $http('/https-probe?other=7', '100.64.10.20', $phone)->assertStatus(301);

        for ($n = 0; $n < Security::HOP_BUCKETS; $n++) {
            $this->assertNull(Cache::get(Security::HOP_CACHE_PREFIX.$n), 'the guard wrote nothing');
        }
    }

    public function test_the_proof_of_https_at_this_server_lasts_a_day_after_the_last_https_request(): void
    {
        $https = fn (string $path) => $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('https://example.test'.$path)->assertOk();
        $http = fn (string $path) => $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test'.$path);

        // Each https request keeps it (written again at most once an hour).
        $https('/https-probe?tls=1');
        $this->travel(20)->hours();
        $https('/https-probe?tls=2');
        $this->travel(20)->hours();
        $http('/https-probe?a=1')->assertStatus(301);
        $http('/https-probe?a=1')->assertStatus(301);

        // A whole day without any https request (or a cleared cache): the guard is used again, until the next one.
        $this->travel(Security::TLS_SECONDS + 1)->seconds();
        $http('/https-probe?b=1')->assertStatus(301);
        $http('/https-probe?b=1')->assertOk();
        $https('/https-probe?tls=3');
        $http('/https-probe?b=1')->assertStatus(301);
        $http('/https-probe?b=1')->assertStatus(301);
    }

    public function test_only_tls_at_this_server_for_the_site_address_is_a_proof(): void
    {
        // An X-Forwarded-Proto https anybody can send (Request::secure() believes it from a trusted hop, and from anybody
        // under '*'): that request is not redirected (a claim of https only ever stops a redirect), and it proves nothing.
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?xfp=1', ['X-Forwarded-Proto' => 'https'])->assertOk();
        // TLS at this server, but for another host than the site's address.
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('https://localhost/https-probe?host=1')->assertOk();
        $this->assertNull(Cache::get(Security::TLS_CACHE_KEY));

        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?p=1')->assertStatus(301);
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?p=1')->assertOk();

        // The site's address over TLS (also with a port, and Cloudflare talking TLS to this server): a proof.
        $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])->get('https://example.test:443/https-probe?cf=1', ['CF-Ray' => 'x', 'CF-Visitor' => '{"scheme":"https"}'])->assertOk();
        $this->assertIsInt(Cache::get(Security::TLS_CACHE_KEY));
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?p=2')->assertStatus(301);
        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?p=2')->assertStatus(301);
    }

    /**
     * Cloudflare (Full SSL) and a load balancer the owner listed both talk TLS to this server and say what the visitor
     * used: a visitor on http is redirected every time, as 1.2.2 did, although the connection to this server is TLS.
     */
    public function test_a_proxy_that_talks_tls_to_this_server_and_says_http_is_still_redirected(): void
    {
        // (An https address: the request reaches this server over TLS, HTTPS on.)
        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])
                ->get('https://example.test/https-probe?full=1', ['CF-Ray' => 'x', 'CF-Visitor' => '{"scheme":"http"}', 'X-Forwarded-Proto' => 'http'])
                ->assertStatus(301)->assertHeader('Location', 'https://example.test/https-probe?full=1');
        }

        config(['site.trusted_proxies' => '10.0.0.5']);

        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
                ->get('https://example.test/https-probe?lb=1', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR])
                ->assertStatus(301);
        }

        // And https, as they say it: never redirected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->get('https://example.test/https-probe?lb=2', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => self::VISITOR])
            ->assertOk();
    }

    /**
     * Behind a proxy that hides https, clients behind one address are told apart by their browser and languages: another
     * one is never helped by a redirect someone else followed. (Two clients that are the same in all of that cannot be
     * told apart without a cookie: within a few minutes they share the redirect of that one address, never another.)
     */
    public function test_behind_a_proxy_that_hides_https_neighbours_with_other_browsers_never_share_a_redirect(): void
    {
        $get = fn (array $headers, string $path = '/https-probe?n=1') => $this->withServerVariables(['REMOTE_ADDR' => '100.64.10.20'])
            ->get('http://example.test'.$path, $headers);
        $a = ['User-Agent' => 'Browser A/1.0', 'Accept-Language' => 'de-DE'];
        $b = ['User-Agent' => 'Browser B/2.0', 'Accept-Language' => 'de-DE'];
        $c = ['User-Agent' => 'Browser A/1.0', 'Accept-Language' => 'fr-FR'];

        $get($a)->assertStatus(301);
        $get($b)->assertStatus(301);
        $get($c)->assertStatus(301);
        $this->withServerVariables(['REMOTE_ADDR' => '100.64.10.21'])->get('http://example.test/https-probe?n=1', $a)->assertStatus(301);

        $get($a)->assertOk();
        $get($a, '/https-probe?n=2')->assertStatus(301);
    }

    public function test_behind_a_proxy_that_hides_https_a_browser_needs_the_cookie_only(): void
    {
        $browser = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36', 'Accept-Language' => 'de-DE,de;q=0.9'];

        $first = $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?b=1', $browser);
        $first->assertStatus(301)->assertCookie('avdbcms_https_hop', '1');

        // The browser kept the cookie (the redirect reached it over https): every address at once, the guard aside.
        foreach (['/https-probe?b=1', '/https-probe?b=2', '/https-probe?b=3'] as $path) {
            $this->withCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
                ->get('http://example.test'.$path, $browser)->assertOk();
        }
    }

    public function test_a_visitor_on_plain_http_is_still_sent_to_https(): void
    {
        $visitor = fn (string $ip, string $path, array $headers = []) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('http://example.test'.$path, $headers);

        // Straight from the visitor, no proxy in front: each address is redirected, and the visitor then uses https,
        // which reaches this server over TLS.
        foreach (['/https-probe?v=1', '/https-probe?v=2', '/https-probe?v=3'] as $path) {
            $visitor(self::VISITOR, $path)->assertStatus(301);
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('https://example.test/https-probe?v=1')->assertOk();

        // From then on, http is redirected every time: the same address again, this visitor or another one.
        $visitor(self::VISITOR, '/https-probe?v=1')->assertStatus(301);
        $visitor(self::VISITOR, '/https-probe?v=2')->assertStatus(301);
        $visitor('203.0.113.77', '/https-probe?v=1')->assertStatus(301);
        $visitor(self::VISITOR, '/https-probe?v=1', ['User-Agent' => 'Another browser/1.0'])->assertStatus(301);
    }

    /** Cloudflare's CF-Visitor and a listed proxy's X-Forwarded-Proto say what the visitor used: never a guess. */
    public static function saidHttp(): array
    {
        return [
            'Cloudflare: the visitor used http' => [self::CLOUDFLARE, ['CF-Ray' => '8d1f0c0e5a1b2c3d-AMS', 'CF-Visitor' => '{"scheme":"http"}', 'X-Forwarded-For' => self::VISITOR], self::DEFAULT_TRUST],
            'a listed proxy says http' => ['10.0.0.5', ['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => self::VISITOR], '10.0.0.5'],
        ];
    }

    #[DataProvider('saidHttp')]
    public function test_a_visit_a_proxy_says_was_http_is_redirected_every_time(string $remote, array $headers, string $trusted): void
    {
        config(['site.trusted_proxies' => $trusted]);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $remote])->get('http://example.test/https-probe', $headers)
                ->assertStatus(301)->assertHeader('Location', 'https://example.test/https-probe');
        }

        // And https, as they say it: never redirected.
        $https = $remote === self::CLOUDFLARE ? ['CF-Visitor' => '{"scheme":"https"}'] + $headers : ['X-Forwarded-Proto' => 'https'] + $headers;
        $this->withServerVariables(['REMOTE_ADDR' => $remote])->get('http://example.test/https-probe', $https)->assertOk();
    }

    public function test_behind_a_local_proxy_that_says_nothing_an_http_site_address_is_guarded_too(): void
    {
        // The site's address is http, a proxy on the server passes requests on without any header: redirected as before
        // 1.2.2, and when that proxy ended TLS, the client that comes back for the same address gets the page.
        config(['app.url' => 'http://example.test']);
        $get = fn () => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://example.test/https-probe?local=1', ['User-Agent' => 'curl/8.4.0']);

        $get()->assertStatus(301);
        $get()->assertOk();
    }

    public function test_without_a_working_cache_the_redirect_is_as_before(): void
    {
        // A cache that keeps nothing: the guard cannot remember, so every visit is redirected (a browser still has the
        // hop cookie); nothing fails.
        config(['cache.default' => 'none']);

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?nocache=1')->assertStatus(301);
        }
    }

    public function test_the_guard_remembers_only_the_newest_redirects(): void
    {
        // One client: all of its redirects are in one cache entry.
        $get = fn (int $n) => $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?n='.$n);

        for ($n = 1; $n <= Security::HOP_PER_BUCKET + 5; $n++) {
            $get($n)->assertStatus(301);
        }

        // The oldest five are forgotten (so that one is redirected again), the newest are remembered.
        $get(1)->assertStatus(301);
        $get(Security::HOP_PER_BUCKET + 5)->assertOk();
    }

    /**
     * The cache a site uses by default is the file store, which deletes an expired entry only when it is read again: the
     * guard keeps its redirects in HOP_BUCKETS entries with fixed names, so a flood of clients (other browsers, other
     * addresses) never makes more files, then or days later.
     */
    public function test_the_guard_keeps_a_fixed_number_of_cache_entries(): void
    {
        $dir = storage_path('framework/testing/https-hop-cache');
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir]);
        app('cache')->forgetDriver('file');

        try {
            $flood = function (int $from, int $to): void {
                for ($i = $from; $i < $to; $i++) {
                    // One address with many browsers, and many addresses.
                    $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe?x=1', ['User-Agent' => 'bot-'.$i])->assertStatus(301);
                    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.dechex($i + 1)])->get('http://example.test/https-probe?x=1', ['User-Agent' => 'bot'])->assertStatus(301);
                }
            };
            $buckets = array_map(fn (int $n): string => str_replace('\\', '/', Cache::store('file')->getStore()->path(Security::HOP_CACHE_PREFIX.$n)), range(0, Security::HOP_BUCKETS - 1));
            $files = fn (): array => array_map(fn ($file): string => str_replace('\\', '/', $file->getPathname()), File::allFiles($dir));

            $flood(0, 300);
            $this->assertNotSame([], $files());
            $this->assertSame([], array_diff($files(), $buckets), 'only the guard\'s own entries');

            for ($n = 0; $n < Security::HOP_BUCKETS; $n++) {
                $this->assertLessThanOrEqual(Security::HOP_PER_BUCKET, count((array) Cache::store('file')->get(Security::HOP_CACHE_PREFIX.$n)));
            }

            $this->travel(3)->days();
            $flood(300, 310);
            $this->assertSame([], array_diff($files(), $buckets), 'days later, still only those');
            $this->assertLessThanOrEqual(Security::HOP_BUCKETS, count($files()));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_visitor_scheme_of_a_request(): void
    {
        $scheme = fn (string $url, array $server = []): ?string => Environment::visitorScheme(Request::create($url, 'GET', server: $server));

        $this->assertSame('https', $scheme('https://example.test/'));
        $this->assertSame('http', $scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR]), "no visitor is a proxy");
        $this->assertNull($scheme('http://example.test/', ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertNull($scheme('http://example.test/', ['REMOTE_ADDR' => '']));
        $this->assertNull($scheme('http://example.test/', ['REMOTE_ADDR' => 'unix:']));
        $this->assertSame('https', $scheme('http://example.test/', ['REMOTE_ADDR' => self::CLOUDFLARE, 'HTTP_CF_VISITOR' => '{"scheme":"https"}']));
        $this->assertSame('http', $scheme('http://example.test/', ['REMOTE_ADDR' => self::CLOUDFLARE, 'HTTP_CF_VISITOR' => '{"scheme":"http"}']));
        $this->assertNull($scheme('http://example.test/', ['REMOTE_ADDR' => self::CLOUDFLARE, 'HTTP_CF_VISITOR' => 'not json']));
        $this->assertSame('https', $scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR, 'HTTP_X_FORWARDED_PROTO' => 'https, http']), 'the first hop counts');
        $this->assertSame('https', $scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR, 'HTTP_FRONT_END_HTTPS' => 'on']));
        $this->assertSame('https', $scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR, 'HTTP_CLOUDFRONT_FORWARDED_PROTO' => 'https']));
        $this->assertNull($scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR, 'HTTP_VIA' => '1.1 proxy']));
        $this->assertNull($scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR, 'HTTP_CDN_LOOP' => 'cloudflare']));

        // X-Forwarded-Proto http counts only from a proxy listed by its address; '*' and broken entries list nobody.
        $local = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http'];
        $private = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'http'];
        $this->assertNull($scheme('http://example.test/', $local));
        $this->assertNull($scheme('http://example.test/', $private));

        foreach (['**', '* **', 'not-an-address 10.0.0.300 10.0.0.5/40', '10.0.0.6', '127.0.0.1'] as $list) {
            config(['site.trusted_proxies' => $list]);
            $this->assertNull($scheme('http://example.test/', $private), "listed: {$list}");
        }

        foreach (['10.0.0.5', '10.0.0.0/8', "*\n10.0.0.5", 'not-an-address,10.0.0.5', ['10.0.0.5']] as $list) {
            config(['site.trusted_proxies' => $list]);
            $this->assertSame('http', $scheme('http://example.test/', $private), 'listed: '.json_encode($list));
        }

        config(['site.trusted_proxies' => '127.0.0.1']);
        $this->assertSame('http', $scheme('http://example.test/', $local));

        // How it knows: only "straight from the visitor" is a guess (a proxy that hides itself looks the same).
        $source = fn (string $url, array $server = []): array => Environment::visitorSchemeSource(Request::create($url, 'GET', server: $server));
        $this->assertSame(['scheme' => 'http', 'guessed' => true], $source('http://example.test/', ['REMOTE_ADDR' => self::VISITOR]));
        $this->assertSame(['scheme' => 'http', 'guessed' => false], $source('http://example.test/', ['REMOTE_ADDR' => self::CLOUDFLARE, 'HTTP_CF_VISITOR' => '{"scheme":"http"}']));
        $this->assertSame(['scheme' => 'http', 'guessed' => false], $source('http://example.test/', $local));
        $this->assertSame(['scheme' => 'https', 'guessed' => false], $source('https://example.test/', ['REMOTE_ADDR' => self::VISITOR]));
        $this->assertSame(['scheme' => null, 'guessed' => false], $source('http://example.test/', ['REMOTE_ADDR' => '10.0.0.9']));
    }

    /**
     * Each case without a list of its own runs under the trusted proxies of a site that never set a list ('') and under
     * an owner's '*': neither lists a proxy.
     *
     * @param  array<string, array{0: string, 1: array<string, string>, 2?: string}>  $cases
     * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
     */
    private static function underEachTrust(array $cases): array
    {
        $all = [];

        foreach ($cases as $name => $case) {
            if (array_key_exists(2, $case)) {
                $all[$name] = $case;

                continue;
            }

            $all[$name.' (trusted proxies: not set)'] = [$case[0], $case[1], self::DEFAULT_TRUST];
            $all[$name.' (trusted proxies: *)'] = [$case[0], $case[1], self::STAR_TRUST];
        }

        return $all;
    }

    /** @param  array<string, string>  $headers @return array<string, string> the headers as server variables */
    private function server(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
