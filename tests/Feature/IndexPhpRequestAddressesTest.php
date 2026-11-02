<?php

namespace Tests\Feature;

use App\Http\Middleware\Security;
use App\Http\Middleware\StartSessionInSiteForm;
use App\Http\Middleware\ValidateSignature;
use App\Providers\EnvironmentServiceProvider;
use App\Support\Environment;
use App\Support\ServerChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Round 1b R1B-007 ("nginx without rewrite rules: everything keeps working under /index.php/...", the fleet's nodes 2, 4
 * and 7) and review BWR-07. public/index.php takes /index.php off the address before Laravel sees the request, so every
 * address Laravel builds from the request itself lacked it and ended on nginx's 404 page there (box, lane BW pass 2,
 * runs/box/urlform_before.txt): a list filter or search with an empty field (the empty-parameter redirect), the page
 * back() returns to without a Referer header (a refused form), the next page of a list, the canonical-host redirect;
 * and every signed e-mail link (address confirmation) answered 403, because the site signs /index.php/email/verify/...
 * while Laravel compared the address without it. A request that came in through /index.php is simulated the way
 * public/index.php hands it over: the address without /index.php, AVDBCLIP_INDEX_PHP_PREFIX set.
 */
class IndexPhpRequestAddressesTest extends TestCase
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
        $this->state = storage_path('framework/testing/index-php-addresses-'.bin2hex(random_bytes(4)).'.json');
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

    public function test_an_empty_field_redirect_keeps_index_php(): void
    {
        $this->indexPhpOnlyServer('/search');

        $response = $this->kernel(Request::create($this->base().'/search?q=avd&sort=', 'GET'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($this->base().'/index.php/search?q=avd', $response->headers->get('Location'));

        // A clean site redirects to the clean address, as before.
        $this->cleanServer('/search');
        $response = $this->kernel(Request::create($this->base().'/search?q=avd&sort=', 'GET'));
        $this->assertSame($this->base().'/search?q=avd', $response->headers->get('Location'));
    }

    public function test_the_page_back_returns_to_without_a_referer_keeps_index_php(): void
    {
        // The web group's session middleware is the lane's (bound by EnvironmentServiceProvider, the HTTP kernel unchanged).
        $this->assertInstanceOf(StartSessionInSiteForm::class, $this->app->make(StartSession::class));

        $this->indexPhpOnlyServer('/login');
        $request = Request::create($this->base().'/login', 'GET');
        $request->setRouteResolver(fn () => $this->app['router']->getRoutes()->match($request));
        $session = $this->app['session']->driver('array');
        $session->start();
        $request->setLaravelSession($session);

        $store = new \ReflectionMethod(StartSessionInSiteForm::class, 'storeCurrentUrl');
        $store->invoke($this->app->make(StartSession::class), $request, $session);

        $this->assertSame($this->base().'/index.php/login', $session->previousUrl());

        // What back() then does for a refused form sent without a Referer header.
        $post = Request::create($this->base().'/login', 'POST');
        $post->setLaravelSession($session);
        $this->app->instance('request', $post);
        URL::setRequest($post);
        $this->assertSame($this->base().'/index.php/login', URL::previous());
        $this->assertSame($this->base().'/index.php/login', redirect()->back()->getTargetUrl());

        // A clean site keeps the clean address.
        $this->cleanServer('/login');
        $store->invoke($this->app->make(StartSession::class), $request, $session);
        $this->assertSame($this->base().'/login', $session->previousUrl());
    }

    public function test_a_signed_e_mail_link_of_a_site_with_index_php_links_opens(): void
    {
        $this->indexPhpOnlyServer('/email/verify/1/abc');
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => 1, 'hash' => 'abc']);
        $this->assertStringContainsString('/index.php/email/verify/1/abc?', $signed, 'the site signs the form of its links');

        // public/index.php hands the request over without /index.php.
        $this->assertTrue($this->signatureAccepted(str_replace('/index.php/', '/', $signed)));

        // Changed in any way: still refused.
        $this->assertFalse($this->signatureAccepted(str_replace('/index.php/', '/', str_replace('/1/abc', '/2/abc', $signed))));
        $this->assertFalse($this->signatureAccepted(str_replace('/index.php/', '/', $signed).'x'));

        // A link signed in the clean form (before the rewrite rule went away, or by a clean site) also opens through
        // /index.php, and a link signed with /index.php also opens at the clean address once the rule is there.
        $this->cleanServer('/email/verify/1/abc');
        $clean = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => 1, 'hash' => 'abc']);
        $this->assertStringNotContainsString('/index.php/', $clean);
        $this->assertTrue($this->signatureAccepted($clean));
        $this->assertTrue($this->signatureAccepted(str_replace('/index.php/', '/', $signed)));
    }

    public function test_the_canonical_host_redirect_keeps_index_php(): void
    {
        // The site's address is site.test; a visitor arrives at www.site.test through /index.php/trend?x=1.
        config(['app.url' => 'https://site.test']);
        $this->indexPhpOnlyServer('/trend');
        $request = Request::create('https://www.site.test/trend?x=1', 'GET', [], [], [], ['REQUEST_URI' => '/trend?x=1', 'HTTPS' => 'on']);

        $target = (new \ReflectionMethod(Security::class, 'canonicalUrlFor'))->invoke($this->app->make(Security::class), $request);

        $this->assertSame('https://site.test/index.php/trend?x=1', $target);
    }

    /** nginx without rewrite rules (the check got nginx's 404 page), a request that came in through /index.php. */
    private function indexPhpOnlyServer(string $path): void
    {
        $this->decide(ServerChecks::NO, ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => $path]);
    }

    /** nginx with the rewrite rule (the check said yes), a request at the clean address. */
    private function cleanServer(string $path): void
    {
        $this->decide(ServerChecks::YES, ['REQUEST_URI' => $path]);
    }

    /** The provider's link decision again, as on this server (the app booted in the console). */
    private function decide(string $answer, array $server): void
    {
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }

        @unlink($this->state);
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        Environment::reset();
        ServerChecks::reset();
        ServerChecks::record(ServerChecks::CLEAN_URLS, $answer, 'test');
        ServerChecks::reset();

        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }

        config(['app.clean_url_fallback' => false, 'app.clean_url_reason' => null]);
        app('url')->formatPathUsing(fn ($path) => $path);
        (new \ReflectionMethod(EnvironmentServiceProvider::class, 'applyCleanUrlFallback'))->invoke(new EnvironmentServiceProvider($this->app));
    }

    private function signatureAccepted(string $url): bool
    {
        $request = Request::create($url, 'GET');

        try {
            (new ValidateSignature())->handle($request, fn () => response('ok'));

            return true;
        } catch (InvalidSignatureException) {
            return false;
        }
    }

    private function kernel(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
