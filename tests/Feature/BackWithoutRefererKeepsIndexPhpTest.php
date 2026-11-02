<?php

namespace Tests\Feature;

use App\Http\Middleware\StartSessionInSiteForm;
use App\Models\User;
use App\Providers\EnvironmentServiceProvider;
use App\Support\Environment;
use App\Support\ServerChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Round 2 R1BR-03: on a server without rewrite rules (the fleet's nodes 2, 4 and 7: every page only as /index.php/...)
 * a redirect back without a Referer header (a privacy extension, a strict Referrer-Policy) uses the page the session
 * remembered. Round 1b (lane BW, BWR-07) remembers each page in the site's own form; a page remembered BEFORE the site's
 * links needed /index.php kept its clean form: every session that was open while a site updated from 1.2.2, or one of a
 * site whose rewrite rules went away after its check said yes. back() took those to the clean address, nginx's 404 page
 * (lane CX, box: runs/box/stale_norw_int-C2.json). The session middleware now gives such a page the site's form when the
 * session starts. A request that came in through /index.php is simulated the way public/index.php hands it over.
 */
class BackWithoutRefererKeepsIndexPhpTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEYS = ['SERVER_SOFTWARE', 'REQUEST_URI', 'AVDBCLIP_USED_INDEX_PHP', 'AVDBCLIP_ROOT_FALLBACK', 'AVDBCLIP_INDEX_PHP_PREFIX', 'LOCALE_PREFIX', 'HOSTING_PANEL', 'DETECTED_LOCALE'];

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

        config(['site.default_locale' => 'en', 'cache.limiter' => 'array']);
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->state = storage_path('framework/testing/back-without-referer-'.bin2hex(random_bytes(4)).'.json');
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

    public function test_a_page_remembered_in_its_clean_form_is_given_the_sites_form(): void
    {
        $this->assertInstanceOf(StartSessionInSiteForm::class, $this->app->make(StartSession::class));
        $start = new \ReflectionMethod(StartSessionInSiteForm::class, 'startSession');
        $base = $this->base();

        $this->indexPhpOnlyServer('/profile/update');
        $cases = [
            $base.'/profile/edit' => $base.'/index.php/profile/edit',
            $base.'/vi/profile/videos?status=1' => $base.'/index.php/vi/profile/videos?status=1',
            $base.'/' => $base.'/index.php',
            // already in the site's form, or another site's address: as they are
            $base.'/index.php/profile/edit' => $base.'/index.php/profile/edit',
            'https://other.example/profile/edit' => 'https://other.example/profile/edit',
        ];

        foreach ($cases as $remembered => $expected) {
            $session = $this->sessionRemembering($remembered);
            $request = Request::create($base.'/profile/update', 'POST');
            $start->invoke($this->app->make(StartSession::class), $request, $session);
            $this->assertSame($expected, $session->previousUrl(), $remembered);

            // what back() then does for a refused form sent without a Referer header
            $request->setLaravelSession($session);
            $this->app->instance('request', $request);
            URL::setRequest($request);
            $this->assertSame($expected, redirect()->back()->getTargetUrl(), 'back() from '.$remembered);
        }

        // a site whose clean addresses reach it: nothing changes
        $this->cleanServer('/profile/update');
        $session = $this->sessionRemembering($base.'/profile/edit');
        $start->invoke($this->app->make(StartSession::class), Request::create($base.'/profile/update', 'POST'), $session);
        $this->assertSame($base.'/profile/edit', $session->previousUrl());
    }

    public function test_a_refused_form_without_a_referer_goes_back_to_an_address_that_answers(): void
    {
        $member = User::factory()->create();
        $base = $this->base();
        $this->indexPhpOnlyServer('/profile/update');

        // a session kept from before the update: its page remembered as the request saw it. (The address is written in
        // full: the test client builds a relative one with url(), which carries /index.php here, and public/index.php,
        // which takes it off before Laravel sees the request, is not part of a test.)
        $this->actingAs($member)
            ->withSession(['_previous' => ['url' => $base.'/profile/edit']])
            ->put($base.'/profile/update', ['section' => 'profile', 'username' => '', 'email' => 'not-an-email'])
            ->assertRedirect($base.'/index.php/profile/edit');
    }

    private function sessionRemembering(string $url): \Illuminate\Contracts\Session\Session
    {
        $session = $this->app['session']->driver('array');
        $session->setId(str_repeat('a', 40));
        $session->start();
        $session->setPreviousUrl($url);
        $session->save();

        return $session;
    }

    /** nginx without rewrite rules (the check said no), a request that came in through /index.php. */
    private function indexPhpOnlyServer(string $path): void
    {
        $this->decide(ServerChecks::NO, ['AVDBCLIP_INDEX_PHP_PREFIX' => true, 'REQUEST_URI' => $path]);
    }

    /** nginx with the rewrite rule (the check said yes), a request at the clean address. */
    private function cleanServer(string $path): void
    {
        $this->decide(ServerChecks::YES, ['REQUEST_URI' => $path]);
    }

    /** The provider's link decision again, as on this server (round 1b's IndexPhpRequestAddressesTest). */
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

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
