<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\EnvironmentServiceProvider;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R1-001 (round 1 judge, major): on a site whose address has a port (the shipped docker-compose 8080:80, an IP with a
 * port, a panel preview), the installer wrote SANCTUM_STATEFUL_DOMAINS=<host> without the port and the runtime
 * fallback added nothing when the bare host was listed. Sanctum compares the Referer / Origin of the site's pages with
 * "host:port", so every signed-in member's API call (like, comment, subscribe, Save, the bell, history) answered 401.
 *
 * Now the installer writes host:port for such an address, and the fallback always lists the address of APP_URL, so
 * sites installed before are fixed without a change to their .env. The member's calls are made the way the browser
 * makes them: a session cookie (never actingAs(), which would skip Sanctum's check) and the page as Referer.
 */
class SanctumStatefulPortTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'http://127.0.0.1:8191';

    public function test_the_installer_writes_the_port_of_the_address(): void
    {
        foreach ([
            'http://127.0.0.1:8191' => '127.0.0.1:8191',
            'http://localhost:8080' => 'localhost:8080',
            'https://Example.COM:8443/' => 'example.com:8443',
            'https://example.com' => 'example.com',
            'http://example.com:80' => 'example.com',
            'https://example.com:443' => 'example.com',
            'http://[::1]:8080' => '[::1]:8080',
        ] as $url => $expected) {
            $this->assertSame($expected, $this->installerEnv($url)['SANCTUM_STATEFUL_DOMAINS'], $url);
        }
    }

    public function test_the_runtime_fallback_lists_host_and_port_when_env_names_the_bare_host(): void
    {
        config(['app.url' => self::APP_URL, 'sanctum.stateful' => ['127.0.0.1']]);

        $this->runStatefulFallback();

        $this->assertSame(['127.0.0.1', '127.0.0.1:8191'], config('sanctum.stateful'), 'the bare host stays, host:port is added');

        // Run again (every boot): nothing is added twice.
        $this->runStatefulFallback();
        $this->assertSame(['127.0.0.1', '127.0.0.1:8191'], config('sanctum.stateful'));

        // An address without a port: the host itself, in lower case, once.
        config(['app.url' => 'https://Example.com', 'sanctum.stateful' => ['example.com', 'cdn.example.com']]);
        $this->runStatefulFallback();
        $this->assertSame(['example.com', 'cdn.example.com'], config('sanctum.stateful'));
    }

    public function test_a_members_api_calls_from_the_sites_pages_are_signed_in_on_an_address_with_a_port(): void
    {
        $member = User::factory()->create();
        $cookie = $this->sessionCookieFor($member);
        config(['app.url' => self::APP_URL, 'sanctum.stateful' => ['127.0.0.1']]);

        // What RC1 did with the installer's value: the page's own address is not "stateful", the session is not read.
        $this->memberCall($cookie, self::APP_URL.'/video/abc')->assertStatus(401);

        $this->runStatefulFallback();

        foreach (['/video/abc' => 'a watch page', '/' => 'the home page', '/library' => 'the library'] as $page => $what) {
            $this->memberCall($cookie, self::APP_URL.$page)->assertOk()->assertJsonPath('id', $member->id);
        }

        // The Origin header of the page counts the same way.
        $this->asFreshRequest();
        $this->withUnencryptedCookie(config('session.cookie'), $cookie)
            ->withHeaders(['Origin' => self::APP_URL, 'Accept' => 'application/json'])
            ->get('/api/me')
            ->assertOk();

        // Another site's page (or a script without a page) still gets no session: 401 as before.
        $this->memberCall($cookie, 'http://127.0.0.1:9999/')->assertStatus(401);
        $this->memberCall($cookie, 'https://attacker.example/')->assertStatus(401);
        $this->asFreshRequest();
        $this->withUnencryptedCookie(config('session.cookie'), $cookie)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/api/me')
            ->assertStatus(401);
    }

    private function memberCall(string $cookie, string $referer): \Illuminate\Testing\TestResponse
    {
        $this->asFreshRequest();

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie)
            ->withHeaders(['Referer' => $referer, 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/api/me');
    }

    /**
     * Every call as a new server request finds the application: no account remembered by the guards of an earlier call
     * of this test and no session data in memory (the session is only what the cookie brings).
     */
    private function asFreshRequest(): void
    {
        Auth::forgetGuards();
        $this->flushHeaders();
        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId(null);
    }

    /**
     * A stored session of the member and the cookie value the browser sends for it (encrypted as EncryptCookies does).
     * The store forgets it in memory afterwards, so only a request that really starts the session finds the member.
     */
    private function sessionCookieFor(User $member): string
    {
        $store = $this->app['session']->driver();
        $store->setId(Str::random(40));
        $store->start();
        $store->put(Auth::guard('web')->getName(), $member->getAuthIdentifier());
        $store->put('password_hash_web', $member->getAuthPassword());
        $store->save();
        $id = $store->getId();
        // As a new request finds it: no data in memory and another id (a middleware that starts the store must not
        // reload this session; only StartSession with the browser's cookie may).
        $store->flush();
        $store->setId(null);

        $encrypter = $this->app['encrypter'];
        $name = (string) config('session.cookie');

        return $encrypter->encrypt(\Illuminate\Cookie\CookieValuePrefix::create($name, $encrypter->getKey()).$id, false);
    }

    private function runStatefulFallback(): void
    {
        $method = new \ReflectionMethod(EnvironmentServiceProvider::class, 'applySanctumStatefulFallback');
        $method->invoke(new EnvironmentServiceProvider($this->app));
    }

    /** @return array<string, string> the .env values the installer would write for this address */
    private function installerEnv(string $url): array
    {
        $method = new \ReflectionMethod(Installer::class, 'envValues');

        return $method->invoke(new Installer(), [
            'host' => '127.0.0.1', 'port' => '3306', 'database' => 'avdbcms', 'username' => 'installer', 'password' => 'secret',
        ], [
            'username' => 'admin', 'email' => 'owner@example.test', 'password' => 'Password123!',
        ], [
            'name' => 'AVDBCMS', 'url' => rtrim($url, '/'), 'mail_mailer' => 'log', 'mail_host' => '', 'mail_port' => '587',
            'mail_username' => '', 'mail_password' => '', 'mail_encryption' => '', 'mail_from_address' => '',
        ]);
    }
}
