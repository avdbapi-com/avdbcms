<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\ReadOnlySession;
use App\Http\Requests\SearchRequest;
use App\Models\User;
use App\Models\Video;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * watch-26 / watch-28: the picture routes (avatars, banners, video thumbnails) read the session but never write it and
 * never send a session cookie, so a page of 20 avatars no longer stores 20 sessions with a picture as "the page you
 * came from" (a refused form went "back" to an SVG); an over-long search query is searched for in its first 255
 * characters instead of being refused.
 *
 * The read-only session starts where StartSession starts, before the web group's middleware
 * that ask for the signed-in account (IsBanned, Language, CheckMaintenanceMode, ViewOnlyDemo). Started after them, an
 * admin got the maintenance page for every picture and a member with a "remember me" cookie was signed in again (the
 * Login event, a users row write) on every picture.
 */
class PictureRoutesSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PICTURE_ROUTES = ['user.avatar', 'user.banner', 'video.thumbnail', 'video.thumbnails'];

    private int $userWrites = 0;

    public function test_the_picture_routes_run_the_read_only_session_instead_of_the_web_groups(): void
    {
        foreach (self::PICTURE_ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();

            $this->assertContains(ReadOnlySession::class, $middleware, $name);
            foreach (ReadOnlySession::REPLACES as $removed) {
                $this->assertContains($removed, $route->excludedMiddleware(), $name.' still runs '.$removed);
            }
        }

        // Other pages keep the web group's session.
        $this->assertNotContains(ReadOnlySession::class, Route::getRoutes()->getByName('pages.home')->gatherMiddleware());
    }

    public function test_the_read_only_session_starts_before_the_middleware_that_ask_for_the_account(): void
    {
        $router = app('router');

        foreach (self::PICTURE_ROUTES as $name) {
            $resolved = array_map(
                fn ($middleware) => is_string($middleware) ? explode(':', $middleware)[0] : get_class($middleware),
                $router->gatherRouteMiddleware(Route::getRoutes()->getByName($name)),
            );
            $session = array_search(ReadOnlySession::class, $resolved, true);

            $this->assertIsInt($session, $name);
            $this->assertNotContains(\Illuminate\Session\Middleware\StartSession::class, $resolved, $name);
            $this->assertLessThan(array_search(\Illuminate\Routing\Middleware\SubstituteBindings::class, $resolved, true), $session, $name);
            // The cookies are decrypted first.
            $this->assertGreaterThan(array_search(\App\Http\Middleware\EncryptCookies::class, $resolved, true), $session, $name);

            foreach ([
                \App\Http\Middleware\IsBanned::class,
                \App\Http\Middleware\Language::class,
                \App\Http\Middleware\CheckMaintenanceMode::class,
                \App\Http\Middleware\ViewOnlyDemo::class,
                \Illuminate\Auth\Middleware\Authorize::class,
            ] as $reader) {
                $at = array_search($reader, $resolved, true);
                $this->assertIsInt($at, $name.' runs '.$reader);
                $this->assertLessThan($at, $session, $name.': the session starts after '.$reader);
            }
        }
    }

    public function test_an_avatar_answers_without_a_session_cookie_and_leaves_the_previous_page_alone(): void
    {
        $user = User::factory()->create();

        $this->get(route('pages.home'))->assertOk();
        $previous = session()->previousUrl();

        $response = $this->get(route('user.avatar', $user));
        $this->assertContains($response->getStatusCode(), [200, 302, 304]);
        $this->assertNoSessionCookies($response);
        $this->assertSame($previous, session()->previousUrl());
    }

    public function test_a_signed_in_admin_still_gets_the_pictures_in_maintenance_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $id = $this->storedSession($admin);
        config(['site.maintenance_mode' => true, 'site.maintenance_allow_admins' => true]);

        $this->withCookie(config('session.cookie'), $id)->get(route('user.avatar', $admin, false))->assertOk();
        $this->withCookie(config('session.cookie'), $id)->get(route('user.banner', $admin, false))->assertOk();

        // A guest still gets the maintenance page.
        $this->nextVisitor();
        $this->get(route('user.avatar', $admin, false))->assertStatus(503);
    }

    public function test_a_member_with_a_session_and_a_remember_cookie_is_not_signed_in_again_by_a_picture(): void
    {
        $member = User::factory()->create(['remember_token' => Str::random(60), 'last_login_at' => null, 'last_login_ip' => null]);
        $id = $this->storedSession($member);
        $stored = app('session')->driver()->getHandler()->read($id);
        $logins = $this->countLogins();

        $response = $this->withCookie(config('session.cookie'), $id)
            ->withCookie(Auth::guard('web')->getRecallerName(), $this->recaller($member))
            ->get(route('user.avatar', $member, false));

        $this->assertContains($response->getStatusCode(), [200, 304]);
        $this->assertSame(0, $logins(), 'a picture signed the member in again');
        $this->assertSame(0, $this->userWrites, 'a picture wrote the users row');
        $this->assertNull($member->fresh()->last_login_at);
        $this->assertNoSessionCookies($response);
        $this->assertSame($stored, app('session')->driver()->getHandler()->read($id), 'the stored session changed');
    }

    public function test_a_remember_cookie_on_an_ended_session_signs_in_once_and_the_next_picture_uses_that_session(): void
    {
        $member = User::factory()->create(['remember_token' => Str::random(60), 'last_login_at' => null]);
        $logins = $this->countLogins();

        // The session the browser sends has ended (nothing stored under its id).
        $first = $this->withCookie(config('session.cookie'), Str::random(40))
            ->withCookie(Auth::guard('web')->getRecallerName(), $this->recaller($member))
            ->get(route('user.avatar', $member, false));

        $this->assertContains($first->getStatusCode(), [200, 304]);
        $this->assertSame(1, $logins());
        // The browser gets the new session's cookie (decrypted here: EncryptCookies encrypts it on the way out).
        $cookie = $first->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'the new sign-in was not given to the browser');
        $stored = (string) app('session')->driver()->getHandler()->read($cookie->getValue());
        $this->assertNotSame('', $stored, 'the new sign-in was not stored');
        $this->assertStringNotContainsString('/avatar', $stored, 'the picture was stored as the previous page');

        // The next picture comes with that session: no new sign-in, nothing written, no cookie.
        $this->nextRequest();
        $second = $this->withCookie(config('session.cookie'), $cookie->getValue())
            ->withCookie(Auth::guard('web')->getRecallerName(), $this->recaller($member))
            ->get(route('user.banner', $member, false));

        $this->assertContains($second->getStatusCode(), [200, 304]);
        $this->assertSame(1, $logins(), 'the next picture signed the member in again');
        $this->assertNoSessionCookies($second);
    }

    public function test_the_owner_gets_a_private_thumbnail_through_the_session_and_a_guest_does_not(): void
    {
        $owner = User::factory()->create();
        $video = Video::factory()->withStatus(VideoStatus::PRIVATE)->for($owner)->create();
        $id = $this->storedSession($owner);

        $this->withCookie(config('session.cookie'), $id)->get(route('video.thumbnail', $video, false))->assertOk();

        // round 3 R2R-07: like a link that does not exist (it was 403)
        $this->nextVisitor();
        $this->get(route('video.thumbnail', $video, false))->assertNotFound();
    }

    public function test_an_over_long_query_is_cut_to_the_longest_query_searched_for(): void
    {
        $long = str_repeat('night ', 100);
        $response = $this->get(route('search.index', ['q' => $long]));

        $response->assertOk();
        $this->assertSame(255, SearchRequest::MAX_QUERY_LENGTH);
        $this->assertStringNotContainsString(trim($long), $response->getContent());
    }

    /** A session stored for $user under a new id, as a sign-in leaves it (the browser holds the id in its cookie). */
    private function storedSession(User $user): string
    {
        $id = Str::random(40);
        app('session')->driver()->getHandler()->write($id, serialize([
            Auth::guard('web')->getName() => $user->getAuthIdentifier(),
            '_token' => Str::random(40),
            '_previous' => ['url' => url('/'), 'route' => 'pages.home'],
            '_flash' => ['old' => [], 'new' => []],
        ]));

        return $id;
    }

    /** A new request as a browser makes it: no account kept in memory from the previous one (the guard reads it again). */
    private function nextRequest(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
    }

    /** Another visitor: no cookies either. */
    private function nextVisitor(): void
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->nextRequest();
    }

    private function recaller(User $user): string
    {
        return $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$user->getAuthPassword();
    }

    /** Counts Login events and users-row updates from now on; returns a reader of the Login count. */
    private function countLogins(): \Closure
    {
        $logins = 0;
        Event::listen(Login::class, function () use (&$logins): void {
            $logins++;
        });
        DB::listen(function ($query): void {
            if (preg_match('/^update [`"]?users[`"]? set/i', $query->sql) === 1) {
                $this->userWrites++;
            }
        });

        return function () use (&$logins): int {
            return $logins;
        };
    }

    private function assertNoSessionCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNotSame(config('session.cookie'), $cookie->getName(), 'the picture sent the session cookie');
            $this->assertNotSame('XSRF-TOKEN', $cookie->getName(), 'the picture sent an XSRF cookie');
        }
    }
}
