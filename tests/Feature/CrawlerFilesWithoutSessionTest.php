<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 1b judge R1B-014 (R1-087 is wider): the crawler files of the site (/robots.txt, /sitemap.xml and every other
 * sitemap, /sitemap.xsl, the IndexNow key file) are the same for everybody and marked public for caches (s-maxage
 * 1800 + stale-while-revalidate; the stylesheet max-age 86400), but they sat in the web group: every answer started a
 * session and set XSRF-TOKEN and a session cookie, for guests too, and re-sent a signed-in member's live session
 * cookie. R1-087's safety net then made them private, so no CDN could keep a sitemap.
 *
 * Now they are served the way the picture routes are (ReadOnlySession, R1-087's one mechanism): the visitor's session is
 * read (an administrator still passes the maintenance page) but never started, written or sent, so the answers set no
 * cookie and stay public; a "remember me" sign-in on such an answer is the one private exception. And the API's public
 * lists never put per-member data (subscribed_by_auth_user) into an answer a shared cache may keep.
 */
class CrawlerFilesWithoutSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CRAWLER_FILES = [
        '/sitemap.xml', '/sitemap.xsl', '/sitemap-pages.xml', '/sitemap-pages-en.xml', '/sitemap-pages-vi.xml',
        '/sitemap-videos.xml', '/sitemap-videos-1.xml', '/sitemap-videos-en-1.xml', '/sitemap-profiles-en-1.xml',
        '/video-sitemap.xml', '/robots.txt',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['localhost'],
            'site.sitemap_enabled' => true,
            'site.robots_noindex' => false,
            'site.indexnow_enabled' => true,
            'site.indexnow_key' => 'r1b014indexnowkey0123',
        ]);
    }

    public function test_the_crawler_files_set_no_cookie_for_any_visitor_and_stay_public(): void
    {
        $this->publicVideo();
        $member = User::factory()->create();
        $demo = $this->viewOnlyDemo();
        $files = [...self::CRAWLER_FILES, '/r1b014indexnowkey0123.txt'];

        foreach (['guest' => null, 'member' => $member, 'view-only demo' => $demo] as $who => $account) {
            foreach ($files as $path) {
                $this->freshVisitor();
                if ($account !== null) {
                    $this->actingAs($account);
                }

                $response = $this->get($path);
                $this->assertContains($response->getStatusCode(), [200, 301], "{$who} {$path}");
                $this->assertSame([], $this->cookieNames($response), "{$who} {$path}: no session is started, no cookie sent");
            }
        }

        // The answers with content keep the public headers a CDN may use (they are the same for everybody).
        foreach (['guest' => null, 'member' => $member] as $who => $account) {
            foreach (['/sitemap.xml' => 's-maxage=1800', '/sitemap-pages.xml' => 's-maxage=1800', '/sitemap.xsl' => 'max-age=86400', '/robots.txt' => 'max-age=300'] as $path => $age) {
                $this->freshVisitor();
                if ($account !== null) {
                    $this->actingAs($account);
                }

                $cacheControl = (string) $this->get($path)->assertOk()->headers->get('Cache-Control');
                $this->assertStringContainsString('public', $cacheControl, "{$who} {$path}");
                $this->assertStringContainsString($age, $cacheControl, "{$who} {$path}");
            }
        }
    }

    public function test_a_browser_that_has_a_session_gets_no_cookie_back_and_its_session_is_not_rewritten(): void
    {
        $this->publicVideo();
        $member = User::factory()->create();

        // A member's own browser: a stored session (signed in, the page it came from) and its cookie.
        $store = $this->app['session']->driver();
        $store->setId(Str::random(40));
        $store->start();
        $store->put(Auth::guard('web')->getName(), $member->getAuthIdentifier());
        $store->put('marker', 'kept');
        $store->setPreviousUrl('http://localhost/trend');
        $store->save();
        $id = $store->getId();
        $handler = $store->getHandler();
        $before = $handler->read($id);

        foreach (['/sitemap.xml', '/sitemap.xsl', '/robots.txt'] as $path) {
            $response = $this->withCookie(config('session.cookie'), $id)->get($path);
            $response->assertOk();
            $this->assertSame([], $this->cookieNames($response), $path.': the session cookie is never sent back');
            $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'), $path);
        }

        $after = @unserialize((string) $handler->read($id));
        $this->assertSame($before, $handler->read($id), 'the stored session is left exactly as it was');
        $this->assertSame('http://localhost/trend', $after['_previous']['url'] ?? null, 'a crawler file is never stored as the page the member came from');
        $this->assertSame('kept', $after['marker'] ?? null);
    }

    public function test_a_member_signed_in_again_from_keep_me_signed_in_gets_a_private_answer(): void
    {
        $this->publicVideo();
        $member = User::factory()->create(['remember_token' => Str::random(60)]);

        $response = $this->withCookie(config('session.cookie'), Str::random(40))
            ->withCookie(Auth::guard('web')->getRecallerName(), $member->getAuthIdentifier().'|'.$member->getRememberToken().'|'.$member->getAuthPassword())
            ->get('/sitemap.xml');

        $response->assertOk();
        $this->assertNotNull($response->getCookie(config('session.cookie')), 'the new sign-in reaches the browser');
        $this->assertNeverShared($response);
    }

    public function test_an_administrator_still_gets_the_crawler_files_during_maintenance(): void
    {
        $this->publicVideo();
        config(['site.maintenance_mode' => true, 'site.maintenance_allow_admins' => true]);
        $admin = User::factory()->admin()->create();

        // A guest gets the maintenance answer, the signed-in administrator (read from the session) the sitemap.
        $this->assertSame(503, $this->get('/sitemap.xml')->getStatusCode());

        $this->freshVisitor();
        $store = $this->app['session']->driver();
        $store->setId(Str::random(40));
        $store->start();
        $store->put(Auth::guard('web')->getName(), $admin->getAuthIdentifier());
        $store->put('password_hash_web', $admin->getAuthPassword());
        $store->save();

        $response = $this->withCookie(config('session.cookie'), $store->getId())->get('/sitemap.xml');
        $this->assertSame(200, $response->getStatusCode(), 'the signed-in administrator is read from the session');
        $this->assertSame([], $this->cookieNames($response));
    }

    public function test_the_public_api_lists_never_carry_member_data_in_an_answer_a_cache_may_share(): void
    {
        $category = Category::query()->create(['title' => 'Music', 'slug' => 'music', 'in_menu' => true]);
        $this->publicVideo($category);
        $member = User::factory()->create();
        $demo = $this->viewOnlyDemo();
        $token = $member->createToken('r1b-014')->plainTextToken;
        $lists = ['/api/videos', '/api/videos/trend', '/api/categories/'.$category->id.'/videos', '/api/search?q=video'];
        $memberDataSeen = 0;

        foreach ($lists as $list) {
            $visitors = [
                'anonymous' => fn () => $this->withHeaders(['Accept' => 'application/json'])->get($list),
                'guest on the site' => fn () => $this->withHeaders(['Accept' => 'application/json', 'Referer' => 'http://localhost/'])->get($list),
                'member' => fn () => $this->actingAs($member)->withHeaders(['Accept' => 'application/json', 'Referer' => 'http://localhost/'])->get($list),
                'view-only demo' => fn () => $this->actingAs($demo)->withHeaders(['Accept' => 'application/json', 'Referer' => 'http://localhost/'])->get($list),
                'app with a token' => fn () => $this->withToken($token)->withHeaders(['Accept' => 'application/json'])->get($list),
            ];

            foreach ($visitors as $who => $request) {
                $this->freshVisitor();
                $response = $request();
                $response->assertOk();
                $carriesMemberData = str_contains((string) $response->getContent(), 'subscribed_by_auth_user');
                $memberDataSeen += $carriesMemberData ? 1 : 0;

                if ($this->sharedCacheMayKeep($response)) {
                    $this->assertFalse($carriesMemberData, "{$who} {$list}: per-member data in a shared answer");
                    $this->assertSame([], $this->cookieNames($response), "{$who} {$list}: a cookie in a shared answer");
                }
            }
        }

        $this->assertGreaterThan(0, $memberDataSeen, 'the lists do carry member data for a signed-in caller (only privately)');
    }

    private function publicVideo(?Category $category = null): Video
    {
        $owner = User::factory()->create();

        return Video::factory()->for($owner)->create(array_filter([
            'status' => VideoStatus::PUBLIC,
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
            'category_id' => $category?->id,
        ], static fn ($value) => $value !== null));
    }

    private function viewOnlyDemo(): User
    {
        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();

        return $demo;
    }

    private function sharedCacheMayKeep(TestResponse $response): bool
    {
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

        if (str_contains($cacheControl, 'private') || str_contains($cacheControl, 'no-store')) {
            return false;
        }

        return str_contains($cacheControl, 'public') || str_contains($cacheControl, 's-maxage');
    }

    private function assertNeverShared(TestResponse $response): void
    {
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('s-maxage', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
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
