<?php

namespace Tests\Feature;

use App\Http\Middleware\Security;
use App\Providers\EnvironmentServiceProvider;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Security plan S25: the session, XSRF-TOKEN and remember-me cookies are host-only, so they never reach a subdomain of
 * the site. A new install writes SESSION_DOMAIN= (empty); an existing site whose SESSION_DOMAIN is its own host (what
 * the installer wrote up to 1.2.2) gets host-only cookies too, and a browser that still holds the old Domain cookies is
 * told once to drop them (its remember-me cookie set again host-only, so it stays signed in). A domain the owner set
 * (".example.com") stays as it is.
 */
class HostOnlyCookiesTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'site.example';

    private const REMEMBER = 'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';

    protected function setUp(): void
    {
        parent::setUp();

        $routes = new RouteCollection();
        $routes->add(Route::middleware('web')->get('/cookie-probe', fn () => 'probe'));

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== 'cookie-probe') {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
    }

    public function test_a_new_install_writes_an_empty_session_domain(): void
    {
        $values = (new ReflectionMethod(Installer::class, 'envValues'))->invoke(app(Installer::class),
            ['host' => '127.0.0.1', 'port' => '3306', 'database' => 'avdbcms', 'username' => 'installer', 'password' => 'x'],
            ['username' => 'admin', 'email' => 'admin@example.test', 'password' => 'Password123!'],
            ['name' => 'AVDBCMS', 'url' => 'https://'.self::HOST, 'mail_mailer' => 'log', 'mail_host' => '', 'mail_port' => '587',
                'mail_username' => '', 'mail_password' => '', 'mail_encryption' => '', 'mail_from_address' => 'admin@example.test']);

        $this->assertSame('', $values['SESSION_DOMAIN']);
    }

    public function test_the_cookies_are_host_only_for_an_empty_or_the_sites_own_domain(): void
    {
        foreach (['', self::HOST, strtoupper(self::HOST), 'localhost'] as $domain) {
            $this->siteWithSessionDomain($domain);
            $this->assertNull(config('session.domain'), 'session.domain '.$domain);

            $response = $this->get('http://'.self::HOST.'/cookie-probe');

            $response->assertOk();
            foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
                $cookie = $this->cookie($response, $name, null);
                $this->assertNotNull($cookie, $name.' is set host-only ('.$domain.')');
            }

            foreach ($response->headers->getCookies() as $cookie) {
                $this->assertTrue($cookie->getDomain() === null || $cookie->isCleared(), 'no live Domain cookie: '.$cookie->getName());
            }
        }
    }

    public function test_a_browser_with_the_old_domain_cookies_is_told_once_to_drop_them(): void
    {
        $this->siteWithSessionDomain(self::HOST);
        $session = (string) config('session.cookie');

        $response = $this->withCookies([$session => 'old-session-id', 'XSRF-TOKEN' => 'old-xsrf', self::REMEMBER => '7|token|hash'])
            ->get('http://'.self::HOST.'/cookie-probe');

        $response->assertOk();
        $cookies = $response->headers->getCookies();
        $names = array_map(fn (Cookie $cookie): string => $cookie->getName().'@'.($cookie->getDomain() ?? 'host'), $cookies);

        foreach ([$session, 'XSRF-TOKEN', self::REMEMBER] as $name) {
            $expired = $this->cookie($response, $name, self::HOST);
            $this->assertNotNull($expired, 'an expired Domain cookie for '.$name);
            $this->assertTrue($expired->isCleared(), $name.' is expired');
            $this->assertSame('/', $expired->getPath());
        }

        // The expired ones come first, the fresh host-only session and XSRF cookies after them.
        $this->assertLessThan(array_search($session.'@host', $names, true), array_search($session.'@'.self::HOST, $names, true));
        $this->assertLessThan(array_search('XSRF-TOKEN@host', $names, true), array_search('XSRF-TOKEN@'.self::HOST, $names, true));

        $remember = $this->cookie($response, self::REMEMBER, null);
        $this->assertNotNull($remember, 'the remember-me cookie again, host-only');
        $this->assertFalse($remember->isCleared());
        $this->assertGreaterThan(now()->addDays(399)->getTimestamp(), $remember->getExpiresTime());

        $mark = $this->cookie($response, Security::HOST_COOKIES_MARK, null);
        $this->assertNotNull($mark);
        $this->assertSame('1', $mark->getValue(), 'the mark is not encrypted');
        $this->assertTrue($mark->isHttpOnly());

        // With the mark: nothing more.
        $again = $this->withCookies([$session => 'old-session-id', self::REMEMBER => '7|token|hash'])
            ->withUnencryptedCookie(Security::HOST_COOKIES_MARK, '1')
            ->get('http://'.self::HOST.'/cookie-probe');
        $this->assertNull($this->cookie($again, $session, self::HOST));
        $this->assertNull($this->cookie($again, Security::HOST_COOKIES_MARK, null));
    }

    public function test_a_visitor_without_those_cookies_gets_no_clean_up(): void
    {
        $this->siteWithSessionDomain(self::HOST);

        $response = $this->get('http://'.self::HOST.'/cookie-probe');

        $this->assertNull($this->cookie($response, Security::HOST_COOKIES_MARK, null));
        $this->assertNull($this->cookie($response, (string) config('session.cookie'), self::HOST));
    }

    public function test_a_domain_the_owner_chose_stays(): void
    {
        $this->siteWithSessionDomain('.example.com');
        $this->assertSame('.example.com', config('session.domain'));

        $response = $this->withCookies([(string) config('session.cookie') => 'id'])->get('http://'.self::HOST.'/cookie-probe');

        $this->assertNotNull($this->cookie($response, (string) config('session.cookie'), '.example.com'));
        $this->assertNull($this->cookie($response, Security::HOST_COOKIES_MARK, null));
    }

    public function test_an_address_that_is_an_ip_gets_no_clean_up(): void
    {
        config(['session.domain' => '203.0.113.5', 'app.url' => 'http://203.0.113.5']);
        $this->app->getProvider(EnvironmentServiceProvider::class)->boot();
        $this->assertNull(config('session.domain'));

        $response = $this->withCookies([(string) config('session.cookie') => 'id'])->get('http://203.0.113.5/cookie-probe');

        $this->assertNull($this->cookie($response, Security::HOST_COOKIES_MARK, null));
    }

    private function siteWithSessionDomain(string $domain): void
    {
        config(['session.domain' => $domain, 'app.url' => 'https://'.self::HOST]);
        $this->app->getProvider(EnvironmentServiceProvider::class)->boot();
    }

    private function cookie(\Illuminate\Testing\TestResponse $response, string $name, ?string $domain): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name && $cookie->getDomain() === $domain) {
                return $cookie;
            }
        }

        return null;
    }
}
