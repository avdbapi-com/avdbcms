<?php

namespace Tests\Feature;

use App\Support\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
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
 *    sent again, so even a proxy that hides https without any trace cannot make it loop.
 *
 * A site that never set its trusted proxies has '*' (AppServiceProvider: whoever connects is trusted), so every case
 * runs under '*' and under an empty setting; '*' must never make a visitor a proxy, or a proxy a listed one.
 */
class ForceHttpsRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** An address of Cloudflare (173.245.48.0/20). */
    private const CLOUDFLARE = '173.245.48.10';

    private const VISITOR = '203.0.113.9';

    /** The trusted proxies of a site that never set a list (AppServiceProvider: $cdn['trusted_proxies'] ?? '*'). */
    private const DEFAULT_TRUST = '*';

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

        // Not listed anywhere: not believed (the setting is '*', whoever connects).
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

        // A cookie the site did not write (not encrypted with its key) counts for nothing.
        $this->withUnencryptedCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->get('http://example.test/https-probe')->assertStatus(301);
        $this->unencryptedCookies = [];

        // A proxy in front hid that the visitor used https (it ended TLS and passed the request on with no header at
        // all): the browser comes back with the cookie and gets the page, not the same redirect again.
        $this->withCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->get('http://example.test/https-probe')->assertOk()->assertSee('probe');
        $this->withCookie('avdbcms_https_hop', '1')->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])
            ->get('http://example.test/https-probe', ['CF-Ray' => 'x', 'CF-Visitor' => '{"scheme":"http"}'])->assertOk();
    }

    public function test_an_https_visit_is_never_redirected(): void
    {
        foreach ([self::DEFAULT_TRUST, ''] as $trusted) {
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

        foreach ([self::DEFAULT_TRUST, ''] as $trusted) {
            config(['site.trusted_proxies' => $trusted]);

            // A request that does not say https: redirected, as before 1.2.2 (also behind a proxy that says nothing or
            // says http).
            $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])->get('http://example.test/https-probe')->assertStatus(301);
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://example.test/https-probe')->assertStatus(301);
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('http://example.test/https-probe', ['X-Forwarded-Proto' => 'http'])->assertStatus(301);

            // One that says https: not redirected, also when only CF-Visitor says so.
            $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARE])->get('http://example.test/https-probe', ['CF-Ray' => 'x', 'CF-Visitor' => '{"scheme":"https"}'])->assertOk();
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->get('http://example.test/https-probe', ['X-Forwarded-Proto' => 'https'])->assertOk();
        }
    }

    public function test_the_visitor_scheme_of_a_request(): void
    {
        $scheme = fn (string $url, array $server = []): ?string => Environment::visitorScheme(Request::create($url, 'GET', server: $server));

        $this->assertSame('https', $scheme('https://example.test/'));
        $this->assertSame('http', $scheme('http://example.test/', ['REMOTE_ADDR' => self::VISITOR]), "'*' makes no visitor a proxy");
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
    }

    /**
     * Each case without a list of its own runs under the trusted proxies of a site that never set a list ('*') and under
     * an empty setting: neither lists a proxy.
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

            $all[$name.' (trusted proxies: *)'] = [$case[0], $case[1], self::DEFAULT_TRUST];
            $all[$name.' (trusted proxies: empty)'] = [$case[0], $case[1], ''];
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
