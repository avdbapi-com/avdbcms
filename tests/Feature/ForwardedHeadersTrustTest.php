<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\EnvironmentServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Security plan S01 + S33 (server part): forwarded headers are believed only from hops nobody on the internet can be
 * (this machine, a private network, Cloudflare) unless the owner listed proxies or wrote '*', only X-Forwarded-For and
 * X-Forwarded-Proto are ever believed (never X-Forwarded-Host, -Prefix or -Port), and every link the site builds starts
 * with APP_URL, never with a host a request brings.
 *
 * Every test runs with the settings of a site that never set its trusted proxies, exactly as a web request sees them
 * (AppServiceProvider::applyDbSettings(), which skips the console), and with the site's real address
 * https://victim-site.example (the environment provider is booted again with it, as at the start of a web request).
 */
class ForwardedHeadersTrustTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://victim-site.example';

    private const VISITOR = '203.0.113.9';

    private const CLOUDFLARE = '173.245.48.10';

    protected function setUp(): void
    {
        parent::setUp();

        // Probe pages of their own, first in the list (the video address catch-all would answer them otherwise).
        $probe = fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->secure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'root' => url('/'),
        ]);
        $routes = new RouteCollection();
        $routes->add(Route::get('/proxy-probe', $probe));
        $routes->add(Route::middleware('web')->get('/proxy-probe-web', $probe));

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route->uri(), ['proxy-probe', 'proxy-probe-web'], true)) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
        $this->siteLikeAWebRequest();
    }

    protected function tearDown(): void
    {
        unset($_ENV['SITE_TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        parent::tearDown();
    }

    public function test_a_site_that_never_set_its_trusted_proxies_trusts_only_local_hops_and_cloudflare(): void
    {
        $this->assertSame('', (string) config('site.trusted_proxies'), 'a site without a setting row has no list');

        $proxies = TrustProxies::trustedProxies();
        $this->assertIsArray($proxies);
        $this->assertContains('127.0.0.0/8', $proxies);
        $this->assertContains('10.0.0.0/8', $proxies);
        $this->assertContains('173.245.48.0/20', $proxies);
        $this->assertNotContains('*', $proxies);
    }

    /** The reset link of a genuine reset mail always names the site's own address (part 1 + 3). */
    public function test_a_password_reset_link_always_names_the_site_address(): void
    {
        $cases = [
            'X-Forwarded-Host' => ['http://victim-site.example/forgot-password', ['X-Forwarded-Host' => 'attacker.example']],
            'X-Forwarded-Prefix' => ['http://victim-site.example/forgot-password', ['X-Forwarded-Prefix' => '@attacker.example']],
            'a forged Host header' => ['http://attacker.example/forgot-password', []],
        ];

        foreach ($cases as $case => [$url, $headers]) {
            // A member of their own each time (the broker sends one link a minute per member).
            $member = User::factory()->create(['email' => 'reset-'.md5($case).'@example.test']);

            $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
                ->post($url, ['email' => $member->email], $headers)
                ->assertRedirect();

            $link = $this->lastResetLink();
            $this->assertSame('victim-site.example', parse_url($link, PHP_URL_HOST), $case.': '.$link);
            $this->assertStringStartsWith(self::SITE.'/reset-password/', $link, $case);
            $this->assertStringNotContainsString('attacker', $link, $case);
        }
    }

    /** A visitor who types forwarded headers is not a proxy: its own address and scheme count (part 2). */
    public function test_a_visitor_cannot_choose_its_address_or_scheme(): void
    {
        $probe = $this->probe(self::VISITOR, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https']);

        $this->assertSame(self::VISITOR, $probe['ip']);
        $this->assertFalse($probe['secure']);
    }

    /** X-Forwarded-For no longer opens a fresh login limit for each made-up address (part 2). */
    public function test_a_made_up_forwarded_address_does_not_lift_the_login_limit(): void
    {
        $attempt = fn (int $n) => $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->postJson('http://victim-site.example/login', ['username' => 'nobody', 'password' => 'wrong-password'], ['X-Forwarded-For' => '1.2.3.'.$n]);

        for ($n = 1; $n <= 5; $n++) {
            $this->assertNotSame(429, $attempt($n)->getStatusCode(), 'attempt '.$n);
        }

        $attempt(6)->assertStatus(429);
    }

    /** Cloudflare and local proxies still say who the visitor is and that it used https; none can rename the host. */
    public function test_cloudflare_and_local_proxies_are_believed_for_the_address_and_scheme_only(): void
    {
        $cloudflare = $this->probe(self::CLOUDFLARE, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example']);
        $this->assertSame('1.2.3.4', $cloudflare['ip']);
        $this->assertTrue($cloudflare['secure']);
        $this->assertSame('victim-site.example', $cloudflare['host']);
        $this->assertSame(self::SITE, $cloudflare['root']);

        foreach (['127.0.0.1', '172.17.0.1', '10.1.2.3', '192.168.1.10', '::1'] as $local) {
            $probe = $this->probe($local, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example']);
            $this->assertSame('1.2.3.4', $probe['ip'], $local);
            $this->assertTrue($probe['secure'], $local);
            $this->assertSame('victim-site.example', $probe['host'], $local);
        }
    }

    /**
     * A server whose socket takes IPv4 and IPv6 at once reports an IPv4 hop as ::ffff:a.b.c.d: Cloudflare and local
     * proxies seen that way are the same hops and are believed the same way; a visitor seen that way is not.
     */
    public function test_hops_seen_through_a_dual_stack_socket_are_the_same_hops(): void
    {
        foreach (['::ffff:173.245.48.10', '::ffff:127.0.0.1', '::ffff:10.1.2.3', '::ffff:172.17.0.1'] as $mapped) {
            $probe = $this->probe($mapped, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example']);
            $this->assertSame('1.2.3.4', $probe['ip'], $mapped);
            $this->assertTrue($probe['secure'], $mapped);
            $this->assertSame('victim-site.example', $probe['host'], $mapped);
        }

        foreach (['::ffff:203.0.113.9', '::ffff:173.245.64.10', '::ffff:8.8.8.8'] as $visitor) {
            $probe = $this->probe($visitor, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https']);
            $this->assertSame($visitor, $probe['ip'], $visitor);
            $this->assertFalse($probe['secure'], $visitor);
        }

        $this->assertContains('::ffff:173.245.48.0/116', TrustProxies::trustedProxies());
        $this->assertContains('::ffff:127.0.0.0/104', TrustProxies::trustedProxies());

        // The owner's own list stays exactly as written.
        config(['site.trusted_proxies' => '198.51.100.7']);
        $this->assertSame(['198.51.100.7'], TrustProxies::trustedProxies());
    }

    /** Proxies listed in .env are believed too; a '*' there is the owner's choice of trusting whoever connects. */
    public function test_proxies_listed_in_the_env_file_are_added(): void
    {
        $_ENV['SITE_TRUSTED_PROXIES'] = '198.51.100.7';
        $this->assertSame('1.2.3.4', $this->probe('198.51.100.7', ['X-Forwarded-For' => '1.2.3.4'])['ip']);
        $this->assertSame('127.0.0.1', $this->probe('127.0.0.1', [])['ip'], 'the built-in hops stay');
        $this->assertSame(self::VISITOR, $this->probe(self::VISITOR, ['X-Forwarded-For' => '1.2.3.4'])['ip']);

        $_ENV['SITE_TRUSTED_PROXIES'] = '*';
        $this->assertSame('*', TrustProxies::trustedProxies());
        $this->assertSame('1.2.3.4', $this->probe(self::VISITOR, ['X-Forwarded-For' => '1.2.3.4'])['ip']);
    }

    /** A list or '*' the owner set keeps the behaviour of before; the host still comes from the request (part 4). */
    public function test_a_list_or_star_the_owner_set_is_kept(): void
    {
        config(['site.trusted_proxies' => '*']);
        $probe = $this->probe(self::VISITOR, ['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.example']);
        $this->assertSame('1.2.3.4', $probe['ip']);
        $this->assertTrue($probe['secure']);
        $this->assertSame('victim-site.example', $probe['host'], 'X-Forwarded-Host is never believed, also under *');

        config(['site.trusted_proxies' => "198.51.100.7\n198.51.100.8"]);
        $this->assertSame('1.2.3.4', $this->probe('198.51.100.8', ['X-Forwarded-For' => '1.2.3.4'])['ip']);
        // The owner's list replaces the built-in one, as before 1.2.3.
        $this->assertSame('127.0.0.1', $this->probe('127.0.0.1', ['X-Forwarded-For' => '1.2.3.4'])['ip']);
    }

    /** X-Forwarded-Prefix cannot put another host into the absolute links of a page (part 1 + 3). */
    public function test_a_forwarded_prefix_never_reaches_the_links_of_a_page(): void
    {
        foreach (['127.0.0.1', self::CLOUDFLARE, self::VISITOR] as $remote) {
            $html = $this->withServerVariables(['REMOTE_ADDR' => $remote])
                ->get('http://victim-site.example/', ['X-Forwarded-Prefix' => '.attacker.example', 'X-Forwarded-Host' => 'attacker.example'])
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString('attacker.example', (string) $html, $remote);
        }
    }

    /** S33: X-Forwarded-Port (also inside HEADER_X_FORWARDED_AWS_ELB) is never believed. */
    public function test_a_forwarded_port_is_never_believed(): void
    {
        foreach (['127.0.0.1', self::CLOUDFLARE] as $remote) {
            $https = $this->probe($remote, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '4444']);
            $this->assertSame(443, (int) $https['port'], $remote);
            $this->assertStringNotContainsString(':4444', $https['root'], $remote);

            $http = $this->probe($remote, ['X-Forwarded-Port' => '4444']);
            $this->assertSame(80, (int) $http['port'], $remote);
        }

        $sitemap = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://victim-site.example/sitemap.xml', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '4444']);
        $sitemap->assertOk();
        $this->assertStringNotContainsString(':4444', (string) $sitemap->getContent());

        // A request-built redirect (StripEmptyParams answers an empty query value with the clean address).
        $redirect = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://victim-site.example/proxy-probe-web?a=1&b=', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '4444']);
        $redirect->assertRedirect();
        $this->assertStringNotContainsString(':4444', (string) $redirect->headers->get('Location'));
    }

    /** S33: a notification a member's request stores never carries a host the member typed. */
    public function test_a_stored_notification_link_names_the_site_address(): void
    {
        $victim = User::factory()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->withServerVariables(['REMOTE_ADDR' => self::VISITOR])
            ->postJson('http://victim-site.example/api/subscribe/'.$victim->id, [], ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Prefix' => '.evil.example'])
            ->assertSuccessful();

        $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $victim->id)->latest('created_at')->value('data'), true);
        $this->assertIsArray($data);
        $this->assertSame('victim-site.example', parse_url((string) ($data['url'] ?? ''), PHP_URL_HOST));
        $this->assertStringNotContainsString('evil', (string) $data['url']);
    }

    /**
     * Parts 2 + 4 and signed links: the e-mail confirmation link is built from APP_URL in https. Behind Cloudflare
     * Flexible SSL on a server that puts the visitor's address in REMOTE_ADDR (mod_remoteip, OpenLiteSpeed "use client
     * IP"), Cloudflare's X-Forwarded-Proto is no longer believed, so the request reaches PHP as http: the link must still
     * open (it did in 1.2.2, where every caller was trusted). Also with an http APP_URL (the link is https because the
     * request said so), and for the changed-address confirmation.
     */
    public function test_a_signed_email_link_opens_behind_cloudflare_flexible_ssl_on_a_server_that_rewrites_remote_addr(): void
    {
        $visitor = '198.51.100.7';
        $flexible = ['X-Forwarded-Proto' => 'https', 'CF-Connecting-IP' => $visitor, 'CF-Visitor' => '{"scheme":"https"}'];

        $this->assertSame(302, $this->openSignupLink($visitor, 'http://victim-site.example', $flexible), 'https APP_URL');

        // An http APP_URL: the mail was sent from a request that said https, so its link is https too.
        $this->bootSite('http://victim-site.example');
        URL::forceScheme('https');
        $this->assertSame(302, $this->openSignupLink($visitor, 'http://victim-site.example', $flexible), 'http APP_URL, https link');
        $this->bootSite(self::SITE);

        // The changed-address confirmation (VerifyUpdatedEmail) is signed the same way.
        $member = User::factory()->create(['temporary_email' => 'new-address@example.test']);
        $link = URL::temporarySignedRoute('verification.verify.update', now()->addHour(), [
            'id' => $member->id,
            'hash' => sha1($member->getTemporaryEmailForVerification()),
        ]);
        $this->assertStringStartsWith(self::SITE.'/email/verify-update/', $link);

        $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => $visitor])
            ->get('http://victim-site.example'.substr($link, strlen(self::SITE)), $flexible)
            ->assertRedirect();
        $this->assertSame('new-address@example.test', $member->fresh()->email);
    }

    /** The same link behind a TLS proxy that sends no header, behind a proxy that passes its own Host on, and directly. */
    public function test_a_signed_email_link_opens_behind_a_proxy_without_headers_or_with_its_own_host(): void
    {
        $this->assertSame(302, $this->openSignupLink('198.51.100.7', 'http://victim-site.example', []), 'a TLS proxy without any header');
        $this->assertSame(302, $this->openSignupLink('127.0.0.1', 'http://127.0.0.1:8080', ['X-Forwarded-Proto' => 'https']), 'a proxy that passes its own Host on');
        $this->assertSame(302, $this->openSignupLink(self::CLOUDFLARE, 'http://victim-site.example', ['X-Forwarded-Proto' => 'https', 'CF-Visitor' => '{"scheme":"https"}']), 'Cloudflare itself');
        $this->assertSame(302, $this->openSignupLink(self::VISITOR, self::SITE, []), 'a direct https visit');
    }

    /** Checking the other addresses never lets a changed link, a link signed for another site or an expired one through. */
    public function test_a_changed_foreign_or_expired_signed_link_still_answers_403(): void
    {
        $flexible = ['X-Forwarded-Proto' => 'https', 'CF-Visitor' => '{"scheme":"https"}'];
        $member = User::factory()->unverified()->create();
        $link = $this->signupLink($member);
        $path = (string) parse_url($link, PHP_URL_PATH);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $open = fn (string $url, string $remote = '198.51.100.7', array $headers = []): int => $this->actingAs($member)
            ->withServerVariables(['REMOTE_ADDR' => $remote])->get($url, $headers ?: $flexible)->getStatusCode();

        // A later expiry without a new signature.
        $later = 'http://victim-site.example'.$path.'?expires='.((int) $query['expires'] + 3600).'&signature='.$query['signature'];
        $this->assertSame(403, $open($later));

        // Signed with this site's key, but for another site's address.
        $foreign = hash_hmac('sha256', 'https://other-site.example'.$path.'?expires='.$query['expires'], (string) config('app.key'));
        foreach (['http://victim-site.example', self::SITE] as $origin) {
            $this->assertSame(403, $open($origin.$path.'?expires='.$query['expires'].'&signature='.$foreign), $origin);
        }

        // Another member's link: the signature does not cover this member's id.
        $other = User::factory()->unverified()->create();
        $theirs = $this->signupLink($other);
        $this->assertSame(403, $open('http://victim-site.example'.$path.'?'.parse_url($theirs, PHP_URL_QUERY)));

        $this->assertFalse($member->fresh()->hasVerifiedEmail());

        // Expired.
        $this->travel(61)->minutes();
        $this->assertSame(403, $open('http://victim-site.example'.substr($link, strlen(self::SITE))));
        $this->assertSame(403, $open($link, self::VISITOR, ['Accept' => 'text/html']));
        $this->assertFalse($member->fresh()->hasVerifiedEmail());
    }

    /** Opens a new member's sign-up confirmation link (signed for APP_URL) at $origin from $remote; the answer's status. */
    private function openSignupLink(string $remote, string $origin, array $headers): int
    {
        $member = User::factory()->unverified()->create();
        $link = $this->signupLink($member);
        $this->assertStringStartsWith('https://victim-site.example/email/verify/', $link);

        $status = $this->actingAs($member)->withServerVariables(['REMOTE_ADDR' => $remote])
            ->get($origin.substr($link, strlen('https://victim-site.example')), $headers)
            ->getStatusCode();

        if ($status === 302) {
            $this->assertTrue($member->fresh()->hasVerifiedEmail(), 'the address is confirmed');
        }

        return $status;
    }

    /** The link of the sign-up confirmation mail (Laravel's VerifyEmail notification builds it the same way). */
    private function signupLink(User $member): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $member->getKey(),
            'hash' => sha1($member->getEmailForVerification()),
        ]);
    }

    /** The site at another address, as the environment provider applies it at the start of a web request. */
    private function bootSite(string $url): void
    {
        URL::forceScheme(null);
        config(['app.url' => $url]);
        $this->app->getProvider(EnvironmentServiceProvider::class)->boot();
    }

    /**
     * The settings of a site that never set its trusted proxies, as AppServiceProvider applies them at the start of every
     * web request (it skips the console), and the site's address, as the environment provider applies it at boot.
     */
    private function siteLikeAWebRequest(): void
    {
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $before = $console->getValue($this->app);
        $console->setValue($this->app, false);

        try {
            (new ReflectionMethod($provider, 'applyDbSettings'))->invoke($provider);
        } finally {
            $console->setValue($this->app, $before);
        }

        // The shipped built-in captcha is solved by people, not by these requests.
        config(['site.captcha_on_password_reset' => false, 'site.captcha_on_login' => false, 'app.url' => self::SITE]);
        $this->app->getProvider(EnvironmentServiceProvider::class)->boot();
    }

    /** @return array{ip: string, secure: bool, host: string, port: int|string, root: string} */
    private function probe(string $remote, array $headers): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->get('http://victim-site.example/proxy-probe', $headers)
            ->assertOk()
            ->json();
    }

    private function lastResetLink(): string
    {
        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertGreaterThan(0, $messages->count(), 'a reset mail was sent');
        $html = (string) $messages->last()->getOriginalMessage()->getHtmlBody();

        $this->assertSame(1, preg_match('#https?://[^\s"\'<>]*?/reset-password/[^\s"\'<>]+#', html_entity_decode($html), $match), 'the mail has a reset link');

        return $match[0];
    }
}
