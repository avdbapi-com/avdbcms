<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as FrameworkCsrfMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class WebCsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_risk_web_mutations_reject_missing_csrf_token(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()
            ->for($owner)
            ->create([
                'status' => PlaylistStatus::PUBLIC,
                'is_deletable' => true,
            ]);

        $mutations = [
            ['PUT', route('admin.settings.update.group', 'maintenance'), [
                'maintenance_mode_enabled' => '0',
                'maintenance_allow_admins' => '1',
                'maintenance_secret' => '',
                'maintenance_message' => 'No token should never persist',
                'maintenance_retry_after' => '120',
                'maintenance_allowed_ips' => '',
            ]],
            ['POST', route('admin.settings.clear.cache'), []],
            ['PUT', route('user.update'), ['email' => 'csrf-attack@example.test']],
            ['POST', route('user.playlists.favorite', $playlist), []],
            ['POST', route('custom-playlist.clear-watched-videos'), []],
        ];

        foreach ($mutations as [$method, $uri, $payload]) {
            $this->assertCsrfRejects($method, $uri, $payload);
        }
    }

    public function test_high_risk_web_mutations_accept_matching_csrf_token_at_middleware_layer(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()
            ->for($owner)
            ->create([
                'status' => PlaylistStatus::PUBLIC,
                'is_deletable' => true,
            ]);

        $mutations = [
            ['PUT', route('admin.settings.update.group', 'maintenance')],
            ['POST', route('admin.settings.clear.cache')],
            ['PUT', route('user.update')],
            ['POST', route('user.playlists.favorite', $playlist)],
            ['POST', route('custom-playlist.clear-watched-videos')],
        ];

        foreach ($mutations as [$method, $uri]) {
            $request = $this->csrfRequest($method, $uri);
            $request->request->set('_token', $request->session()->token());

            $response = $this->strictCsrfMiddleware()->handle($request, fn () => response('csrf-passed'));

            $this->assertSame(200, $response->getStatusCode(), $method . ' ' . $uri);
            $this->assertSame('csrf-passed', $response->getContent(), $method . ' ' . $uri);
        }
    }

    /**
     * The CSRF exceptions are an allowlist. The web group's own list holds the two signed payment webhooks. The global
     * list (RouteServiceProvider::boot, ValidateCsrfToken::except) holds only the guest view-count beacon: edge-cached
     * guest watch pages carry no session cookie, so their token can never match and Sanctum's stateful API check
     * answered 419 to every guest view (2026-10-14). The beacon bumps the view counter and the daily stats
     * and, depending on the view-log mode, stores a views row (with the viewer's id when signed in); ViewService keeps
     * a cooldown per user or IP + user agent and throttle:video-view limits the rate. Cross-site calls run as guests:
     * Sanctum starts a session only for a stateful Origin/Referer, and SameSite=lax is the second layer. The global
     * list is shared by every CSRF middleware, so the web group reports it too; no web route lives under api/.
     */
    public function test_only_signed_payment_webhooks_and_the_view_beacon_are_excluded_from_csrf(): void
    {
        $web = $this->strictCsrfMiddleware();
        $statefulApi = $this->strictStatefulApiCsrfMiddleware();

        $this->assertSame(
            ['stripe-webhook', 'paypal-webhook'],
            (new \ReflectionProperty(FrameworkCsrfMiddleware::class, 'except'))->getValue($web)
        );
        $this->assertSame(['stripe-webhook', 'paypal-webhook', 'api/videos/*/view'], $web->getExcludedPaths());
        $this->assertSame(ValidateCsrfToken::class, config('sanctum.middleware.validate_csrf_token'));
        $this->assertSame(['api/videos/*/view'], $statefulApi->getExcludedPaths());

        // Every route an exception reaches ('*' also matches '/'): the two webhooks and the view beacon, nothing else.
        $covered = $this->routesCoveredBy($web->getExcludedPaths());
        $this->assertSame(['paypal_webhook', 'stripe_webhook', 'videos.view'], array_keys($covered));
        foreach ($covered as $name => $route) {
            $this->assertSame(['POST'], $route->methods(), $name);
        }
        $this->assertContains('web', $covered['stripe_webhook']->gatherMiddleware());
        $this->assertContains('web', $covered['paypal_webhook']->gatherMiddleware());
        $beaconMiddleware = $covered['videos.view']->gatherMiddleware();
        $this->assertContains('api', $beaconMiddleware);
        $this->assertNotContains('web', $beaconMiddleware);
        $this->assertContains('throttle:video-view', $beaconMiddleware);

        foreach (['stripe-webhook', 'paypal-webhook'] as $path) {
            $request = $this->csrfRequest('POST', url($path));

            $response = $web->handle($request, fn () => response('webhook-csrf-exempt'));

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame('webhook-csrf-exempt', $response->getContent(), $path);
        }

        $video = (string) Str::uuid();
        $response = $statefulApi->handle(
            $this->csrfRequest('POST', url("api/videos/{$video}/view")),
            fn () => response('beacon-csrf-exempt')
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('beacon-csrf-exempt', $response->getContent());

        // The beacon's neighbours on the stateful API still need the token.
        foreach ([
            url("api/videos/{$video}/comments"),
            url("api/videos/{$video}/progress"),
            url("api/videos/{$video}/view/extra"),
            route('me.clear-all'),
        ] as $uri) {
            $this->assertCsrfRejects('POST', $uri, [], $statefulApi);
        }
    }

    /**
     * Besides the allowlist, a route can skip CSRF by running no CSRF middleware at all (no web or api group, or
     * withoutMiddleware). Only two framework routes do that: Laravel's broadcasting/auth (it signs a private channel for
     * the current session and its answer cannot be read cross-site) and Cashier's own stripe/webhook (unused: the site's
     * webhook is /stripe-webhook, and Cashier's route may stop being registered, hence a subset check).
     */
    public function test_no_other_mutating_route_skips_csrf(): void
    {
        $router = $this->app['router'];
        $unchecked = [];

        foreach ($router->getRoutes() as $route) {
            if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === []) {
                continue;
            }

            $checked = false;
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                $class = is_string($middleware) ? explode(':', $middleware, 2)[0] : '';
                if ($class !== '' && (is_a($class, FrameworkCsrfMiddleware::class, true) || is_a($class, EnsureFrontendRequestsAreStateful::class, true))) {
                    $checked = true;
                    break;
                }
            }

            if (! $checked) {
                $unchecked[] = $route->uri();
            }
        }

        $this->assertSame([], array_values(array_diff($unchecked, ['broadcasting/auth', 'stripe/webhook'])), 'Mutating routes that skip CSRF: ' . implode(', ', $unchecked));
    }

    private function assertCsrfRejects(string $method, string $uri, array $payload = [], ?FrameworkCsrfMiddleware $middleware = null): void
    {
        try {
            ($middleware ?? $this->strictCsrfMiddleware())->handle(
                $this->csrfRequest($method, $uri, $payload),
                fn () => response('should-not-pass')
            );

            $this->fail($method . ' ' . $uri . ' accepted a missing CSRF token.');
        } catch (TokenMismatchException $exception) {
            $this->assertSame('CSRF token mismatch.', $exception->getMessage(), $method . ' ' . $uri);
        }
    }

    private function csrfRequest(string $method, string $uri, array $payload = []): Request
    {
        $request = Request::create($uri, $method, $payload);
        $session = $this->app['session.store'];
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    /**
     * @param  list<string>  $patterns
     * @return array<string, Route>  by route name, sorted
     */
    private function routesCoveredBy(array $patterns): array
    {
        $covered = [];

        foreach ($this->app['router']->getRoutes() as $route) {
            $path = preg_replace('/\{[^}]+\}/', 'x', $route->uri());

            foreach ($patterns as $pattern) {
                if (Str::is(trim($pattern, '/'), $path)) {
                    $covered[(string) $route->getName()] = $route;
                }
            }
        }

        ksort($covered);

        return $covered;
    }

    private function strictCsrfMiddleware(): VerifyCsrfToken
    {
        return new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }

    private function strictStatefulApiCsrfMiddleware(): ValidateCsrfToken
    {
        return new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }
}
