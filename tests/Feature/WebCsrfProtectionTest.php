<?php

namespace Tests\Feature;

use App\Enums\PlaylistStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
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

    public function test_only_signed_payment_webhooks_are_excluded_from_web_csrf(): void
    {
        $excluded = $this->strictCsrfMiddleware()->getExcludedPaths();

        $this->assertSame(['stripe-webhook', 'paypal-webhook'], $excluded);

        foreach (['stripe-webhook', 'paypal-webhook'] as $path) {
            $request = $this->csrfRequest('POST', url($path));

            $response = $this->strictCsrfMiddleware()->handle($request, fn () => response('webhook-csrf-exempt'));

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame('webhook-csrf-exempt', $response->getContent(), $path);
        }
    }

    private function assertCsrfRejects(string $method, string $uri, array $payload = []): void
    {
        try {
            $this->strictCsrfMiddleware()->handle(
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

    private function strictCsrfMiddleware(): VerifyCsrfToken
    {
        return new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }
}
