<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R1-087 (round 1 judge): answers marked for any cache ("public, immutable, max-age=604800" on the picture routes,
 * "public, max-age=30/60" on the API's public lists) carried a Set-Cookie with the visitor's live session: a picture that
 * signed a member in again from "Keep me signed in", and the lists the site's own pages fetch. A shared cache that
 * keeps Set-Cookie would hand that session to the next visitor. The same held for every web answer marked public that
 * sets the session cookie (sitemaps, the /storage/ fallback for files).
 *
 * Now: an answer that sets a cookie is never public (private, the browser may still keep it); the picture and
 * public-file answers set no cookie and stay public; a cookie-less request to a public list keeps its public answer.
 */
class NoSharedCacheWithCookiesTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'http://localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost']]);
    }

    public function test_a_picture_that_signs_a_member_in_again_is_private(): void
    {
        $member = User::factory()->create(['remember_token' => Str::random(60)]);

        // The browser's session has ended; its "Keep me signed in" cookie signs it in again on this picture.
        $first = $this->withCookie(config('session.cookie'), Str::random(40))
            ->withCookie(Auth::guard('web')->getRecallerName(), $member->getAuthIdentifier().'|'.$member->getRememberToken().'|'.$member->getAuthPassword())
            ->get(route('user.avatar', $member, false));

        $this->assertContains($first->getStatusCode(), [200, 304]);
        $cookie = $first->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'the new sign-in is given to the browser');
        $this->assertNeverShared($first);
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));

        // The next picture comes with that session: no cookie, so the public answer a CDN may keep.
        Auth::forgetGuards();
        $this->flushSession();
        $second = $this->withCookie(config('session.cookie'), $cookie->getValue())->get(route('user.banner', $member, false));
        $this->assertContains($second->getStatusCode(), [200, 304]);
        $this->assertSame([], $this->cookieNames($second));
        $this->assertStringContainsString('public', (string) $second->headers->get('Cache-Control'));
    }

    public function test_the_apis_public_lists_are_private_when_they_set_a_cookie_or_belong_to_an_account(): void
    {
        $member = User::factory()->create();
        $category = Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        $lists = ['/api/videos', '/api/videos/trend', '/api/search?q=music', '/api/categories/'.$category->id.'/videos'];

        foreach ($lists as $list) {
            // A guest on the site's own page: Sanctum starts a session, the answer sets its cookies.
            $this->freshVisitor();
            $guestPage = $this->withHeaders(['Referer' => self::SITE.'/', 'Accept' => 'application/json'])->get($list);
            $guestPage->assertOk();
            $this->assertContains(config('session.cookie'), $this->cookieNames($guestPage), $list);
            $this->assertNeverShared($guestPage, $list);

            // A signed-in member on the site's own page, and the view-only demo account.
            foreach (['member' => $member, 'view-only demo' => $this->viewOnlyDemo()] as $who => $account) {
                $this->freshVisitor();
                $page = $this->actingAs($account)->withHeaders(['Referer' => self::SITE.'/', 'Accept' => 'application/json'])->get($list);
                $page->assertOk();
                $this->assertNeverShared($page, $list.' '.$who);
            }

            // An app signed in with its token: an answer made for that account.
            $this->freshVisitor();
            $token = $member->createToken('r1-087')->plainTextToken;
            $app = $this->withToken($token)->withHeaders(['Accept' => 'application/json'])->get($list);
            $app->assertOk();
            $this->assertNeverShared($app, $list);

            // A request without a session or an account (a crawler, another site's script): public as before, no cookie.
            $this->freshVisitor();
            $anonymous = $this->withHeaders(['Accept' => 'application/json'])->get($list);
            $anonymous->assertOk();
            $this->assertSame([], $this->cookieNames($anonymous), $list);
            $this->assertStringContainsString('public', (string) $anonymous->headers->get('Cache-Control'), $list);
        }
    }

    public function test_a_web_answer_marked_public_that_sets_the_session_cookie_becomes_private(): void
    {
        $member = User::factory()->create();

        // Any answer of the web group marked public that sets the session cookie becomes private (EdgeCacheVideoHtml, the
        // group's outermost middleware). Since R1B-014 no route of the site answers that way any more (the sitemaps start
        // no session: CrawlerFilesWithoutSessionTest), so the rule is checked on such an answer directly.
        $publicWithSession = function () {
            $response = response('public', 200, ['Cache-Control' => 'public, max-age=86400']);
            $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create((string) config('session.cookie'), 'session-id'));

            return $response;
        };
        $answer = TestResponse::fromBaseResponse((new \App\Http\Middleware\EdgeCacheVideoHtml())->handle(\Illuminate\Http\Request::create('/any-page'), $publicWithSession));
        $this->assertContains(config('session.cookie'), $this->cookieNames($answer));
        $this->assertNeverShared($answer);
        $this->assertStringContainsString('max-age=86400', (string) $answer->headers->get('Cache-Control'), 'the browser may still keep it');

        foreach (['guest' => null, 'member' => $member, 'view-only demo' => $this->viewOnlyDemo()] as $who => $account) {
            // The sitemap stylesheet sets no cookie at all now, so it stays public (R1B-014).
            $this->freshVisitor();
            if ($account !== null) {
                $this->actingAs($account);
            }
            $stylesheet = $this->get('/sitemap.xsl');
            $stylesheet->assertOk();
            $this->assertSame([], $this->cookieNames($stylesheet), $who);
            if ($who !== 'view-only demo') {
                $this->assertStringContainsString('public', (string) $stylesheet->headers->get('Cache-Control'), $who);
            }
        }

        // A guest's public page still drops its session cookies and stays public (EdgeCacheVideoHtml, unchanged).
        $this->freshVisitor();
        $home = $this->get('/');
        $home->assertOk();
        $this->assertSame([], $this->cookieNames($home));
        $this->assertStringContainsString('public', (string) $home->headers->get('Cache-Control'));
    }

    public function test_the_storage_fallback_serves_public_files_without_any_session_cookie(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/r1-087.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $member = User::factory()->create();

        foreach (['guest' => null, 'member' => $member] as $who => $account) {
            $this->freshVisitor();
            if ($account !== null) {
                $this->actingAs($account);
            }

            $file = $this->get('/storage/avatars/r1-087.png');
            $file->assertOk();
            $this->assertSame([], $this->cookieNames($file), $who.': a public file sets no cookie');
            $this->assertStringContainsString('public', (string) $file->headers->get('Cache-Control'), $who);
        }
    }

    /** The answer may be kept by the browser only: never public, s-maxage or a CDN header that allows storing. */
    private function assertNeverShared(TestResponse $response, string $context = ''): void
    {
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $cacheControl, $context);
        $this->assertStringNotContainsString('s-maxage', $cacheControl, $context);
        $this->assertStringContainsString('private', $cacheControl, $context);

        foreach (['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $header) {
            if ($response->headers->has($header)) {
                $this->assertSame('no-store', $response->headers->get($header), $context.' '.$header);
            }
        }
    }

    private function viewOnlyDemo(): User
    {
        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();

        return $demo;
    }

    /** @return list<string> */
    private function cookieNames(TestResponse $response): array
    {
        return array_map(static fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    }

    private function freshVisitor(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->app['auth']->guard('web')->logout();
        Auth::forgetGuards();
    }
}
