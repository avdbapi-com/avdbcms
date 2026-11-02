<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\ReadOnlySession;
use App\Models\User;
use App\Models\Video;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Round 3 check SB-V1-01: the video's own file (route video.file: the page's <video> asks for it at load and again for
 * each seek or buffer refill) reads the session but never writes it, since its older copy of the session replaced the
 * sign-in page's question and token (SB-X1). With that it stopped keeping the session alive: in 1.2.2 every request of
 * the <video> stored the session again, so a member who watched a long film stored on the site was still signed in at
 * its end; with nothing but the video's requests for longer than the session lifetime the member was signed out.
 *
 * Now the video's requests keep the session alive the way the page's background requests do
 * (App\Http\Middleware\BackgroundSessionHandler): the stored session's time moves on (at most once a minute) without a
 * byte of it written again, and the answer renews the same cookie. An ended session is never brought back, a visitor
 * without a stored session gets no cookie, a "remember me" sign-in on an ended session is stored once, and nothing older
 * is ever written over what another request stored meanwhile.
 *
 * The video's file is never kept by a shared cache (a CDN in front of the site). Before SB-X1 every answer carried the
 * session cookie, which made it private (EdgeCacheVideoHtml::neverSharedWithCookies()); without a cookie a guest's
 * answer said "public", so a CDN could keep the site's videos and hand one out after its owner made it private.
 *
 * The sessions live in files of their own and every request gets a new session store, as on a real site.
 */
class VideoFileKeepAliveTest extends TestCase
{
    use RefreshDatabase;

    /** A video file name no video has. */
    private const MISSING_FILE = 'nothing-here.mp4';

    private ?string $sessions = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = storage_path('framework/testing/sessions-sb-v1-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->sessions);
        config(['session.driver' => 'file', 'session.files' => $this->sessions, 'session.lottery' => [0, 100]]);
        $this->freshStore();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->sessions !== null) {
            File::deleteDirectory($this->sessions);
        }

        parent::tearDown();
    }

    public function test_the_video_file_keeps_the_read_only_session_and_keeps_it_alive(): void
    {
        $route = Route::getRoutes()->getByName('video.file');
        $this->assertContains(ReadOnlySession::class.':'.ReadOnlySession::KEEP_ALIVE, $route->gatherMiddleware());
        foreach (ReadOnlySession::REPLACES as $removed) {
            $this->assertContains($removed, $route->excludedMiddleware(), 'video.file still runs '.$removed);
        }

        // it starts where StartSession starts: after the cookies are decrypted, before the bindings and the middleware
        // that ask for the signed-in account (the owner of a private video, an admin in maintenance mode)
        $resolved = array_map(
            fn ($middleware) => is_string($middleware) ? explode(':', $middleware)[0] : get_class($middleware),
            app('router')->gatherRouteMiddleware($route),
        );
        $session = array_search(ReadOnlySession::class, $resolved, true);
        $this->assertIsInt($session);
        $this->assertNotContains(\Illuminate\Session\Middleware\StartSession::class, $resolved);
        $this->assertGreaterThan(array_search(\App\Http\Middleware\EncryptCookies::class, $resolved, true), $session);
        foreach ([
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\IsBanned::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\CheckMaintenanceMode::class,
            \App\Http\Middleware\ViewOnlyDemo::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ] as $reader) {
            $at = array_search($reader, $resolved, true);
            $this->assertIsInt($at, 'video.file runs '.$reader);
            $this->assertLessThan($at, $session, 'the session starts after '.$reader);
        }

        // the pictures keep the read-only session without it (a page view keeps the session alive with them)
        foreach (['user.avatar', 'user.banner', 'video.thumbnail', 'video.thumbnails'] as $name) {
            $this->assertContains(ReadOnlySession::class, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_a_member_who_only_watches_a_long_video_is_still_signed_in_at_its_end(): void
    {
        // the check's run: a 2-minute session lifetime, then 3 minutes with nothing but the video's requests
        config(['session.lifetime' => 2]);
        $this->freshStore();
        $member = User::factory()->create();
        $video = $this->videoWithFile(VideoStatus::PUBLIC, User::factory()->create());
        $id = $this->storedSession($member);

        $this->page('/library', $id)->assertOk();

        for ($second = 20; $second <= 180; $second += 20) {
            $this->travel(20)->seconds();
            $this->videoFile($video, $id)->assertSuccessful();
        }

        $this->page('/library', $id)->assertOk();
        $this->assertAuthenticatedAs($member);
    }

    public function test_the_video_keeps_the_stored_session_alive_without_writing_it(): void
    {
        $member = User::factory()->create();
        $video = $this->videoWithFile(VideoStatus::PUBLIC, User::factory()->create());
        $id = $this->storedSession($member);
        $file = $this->sessions.'/'.$id;
        $content = File::get($file);

        // a film played for 30 minutes since the last page view
        $this->setFileTime($file, now()->subMinutes(30)->getTimestamp());
        $response = $this->videoFile($video, $id)->assertSuccessful();

        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp(), $this->fileTime($file), 'its time moves on, as with a page view');
        $this->assertSame($content, File::get($file), 'what is in it stays as it was');
        $this->assertSame([$id], $this->sessionIds($response), 'the same session cookie, renewed');
        $this->assertNeverShared($response);
        $this->assertCount(1, File::files($this->sessions), 'no other session was stored');

        // within a minute the time is left as it is (the requests of a seek or a buffer refill renew it once a minute)
        $recent = now()->subSeconds(20)->getTimestamp();
        $this->setFileTime($file, $recent);
        $this->assertSame([$id], $this->sessionIds($this->videoFile($video, $id)->assertSuccessful()));
        $this->assertSame($recent, $this->fileTime($file));
        $this->assertSame($content, File::get($file));
    }

    public function test_an_ended_or_missing_session_is_never_brought_back_and_a_visitor_without_one_gets_no_cookie(): void
    {
        $member = User::factory()->create();
        $video = $this->videoWithFile(VideoStatus::PUBLIC, User::factory()->create());
        $id = $this->storedSession($member);
        $file = $this->sessions.'/'.$id;
        $content = File::get($file);

        $ended = now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp();
        $this->setFileTime($file, $ended);
        $response = $this->videoFile($video, $id)->assertSuccessful();
        $this->assertSame($ended, $this->fileTime($file), 'an ended session keeps its time');
        $this->assertSame($content, File::get($file));
        $this->assertSame([], $this->sessionIds($response), 'and its cookie is not renewed');

        // no stored session (cleaned up): nothing is stored, no cookie is given
        File::delete($file);
        $this->assertSame([], $this->sessionIds($this->videoFile($video, $id)->assertSuccessful()));
        $this->assertSame([], File::files($this->sessions));

        // a visitor without any cookie (a guest on a watch page, a public cached page): no session, no cookie at all
        $guest = $this->videoFile($video, null)->assertSuccessful();
        $this->assertSame([], $guest->headers->getCookies());
        $this->assertSame([], File::files($this->sessions));
    }

    public function test_what_another_request_stored_meanwhile_stays_and_only_the_time_moves_on(): void
    {
        // round 3 review SB-X1: the video's request read the session, the sign-in page stored its question and the form's
        // token meanwhile, and the video's older copy replaced it when it ended
        $video = $this->videoWithFile(VideoStatus::PUBLIC, User::factory()->create());
        $id = $this->storedSession(null);
        $file = $this->sessions.'/'.$id;
        $this->setFileTime($file, now()->subMinutes(10)->getTimestamp());
        $signInPage = serialize(['_token' => Str::random(40), 'stored_by' => 'the sign-in page', '_flash' => ['old' => [], 'new' => []]]);

        // the sign-in page stores the session while the video's request runs (its authorization comes after the read; a
        // callback of the gate runs for a guest only when its first parameter may be null)
        Gate::before(function (?User $user = null) use ($file, $signInPage): void {
            if (File::get($file) !== $signInPage) {
                File::put($file, $signInPage);
                $this->setFileTime($file, now()->subMinutes(10)->getTimestamp());
            }
        });

        $response = $this->videoFile($video, $id)->assertSuccessful();

        $this->assertSame($signInPage, File::get($file), 'the video wrote its older copy over the sign-in page\'s');
        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp(), $this->fileTime($file));
        $this->assertSame([$id], $this->sessionIds($response), 'the same session: never another one');
    }

    public function test_with_database_sessions_only_the_last_activity_moves_on(): void
    {
        config(['session.driver' => 'database', 'session.connection' => null, 'session.lottery' => [0, 100]]);
        $this->freshStore();
        $member = User::factory()->create();
        $video = $this->videoWithFile(VideoStatus::PUBLIC, User::factory()->create());
        $id = Str::random(40);
        $payload = base64_encode(serialize([Auth::guard('web')->getName() => $member->getAuthIdentifier(), '_token' => Str::random(40)]));
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $member->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'payload' => $payload, 'last_activity' => now()->subMinutes(30)->getTimestamp()]);

        $response = $this->videoFile($video, $id)->assertSuccessful();
        $row = DB::table('sessions')->where('id', $id)->first();
        $this->assertSame($payload, $row->payload, 'what is in it stays as it was');
        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp(), (int) $row->last_activity);
        $this->assertSame([$id], $this->sessionIds($response));
        $this->assertSame(1, DB::table('sessions')->count());

        // an ended one is left alone
        $ended = now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp();
        DB::table('sessions')->where('id', $id)->update(['last_activity' => $ended]);
        $this->assertSame([], $this->sessionIds($this->videoFile($video, $id)->assertSuccessful()));
        $this->assertSame($ended, (int) DB::table('sessions')->where('id', $id)->value('last_activity'));
    }

    public function test_a_remember_me_sign_in_on_an_ended_session_is_stored_once(): void
    {
        $member = User::factory()->create(['remember_token' => Str::random(60), 'last_login_at' => null]);
        $video = $this->videoWithFile(VideoStatus::PUBLIC, User::factory()->create());
        $logins = 0;
        Event::listen(Login::class, function () use (&$logins): void {
            $logins++;
        });
        $recaller = [Auth::guard('web')->getRecallerName() => $member->getAuthIdentifier().'|'.$member->getRememberToken().'|'.$member->getAuthPassword()];

        // the session the browser sends has ended (nothing stored under its id)
        $first = $this->videoFile($video, Str::random(40), $recaller)->assertSuccessful();
        $this->assertSame(1, $logins);
        $ids = $this->sessionIds($first);
        $this->assertCount(1, $ids, 'the new sign-in was not given to the browser');
        $stored = (string) File::get($this->sessions.'/'.$ids[0]);
        $this->assertSame($member->getAuthIdentifier(), unserialize($stored)[Auth::guard('web')->getName()] ?? null, 'the new sign-in was not stored');
        $this->assertStringNotContainsString('/video/file/', $stored, 'the video was stored as the previous page');
        $this->assertNeverShared($first);

        // the next request of the <video> comes with that session: no new sign-in, its time kept, the same cookie
        $second = $this->videoFile($video, $ids[0], $recaller)->assertSuccessful();
        $this->assertSame(1, $logins, 'the next request signed the member in again');
        $this->assertSame($ids, $this->sessionIds($second));
        $this->assertCount(1, File::files($this->sessions));
    }

    public function test_the_video_file_is_never_kept_by_a_shared_cache(): void
    {
        $owner = User::factory()->create();
        $public = $this->videoWithFile(VideoStatus::PUBLIC, $owner);
        $private = $this->videoWithFile(VideoStatus::PRIVATE, $owner);

        // a guest without a session: no cookie, and still a private answer (the browser keeps it, a CDN never does)
        $guest = $this->videoFile($public, null)->assertSuccessful();
        $this->assertSame([], $guest->headers->getCookies());
        $this->assertNeverShared($guest);

        // a guest gets no private video: since round 3 R2R-07 (App\Support\HiddenRouteModels) the refusal is the answer of
        // a file that does not exist (404, no longer 403 "This video is private"), without a cookie and never kept by a
        // shared cache either
        $refused = $this->videoFile($private, null)->assertNotFound();
        $this->assertSame([], $refused->headers->getCookies());
        $this->assertNeverShared($refused);
        $this->assertSame($this->answerOf($this->missingFile(null)), $this->answerOf($refused, $private->file), 'not the missing video\'s answer');

        // the owner of a private video gets it through the session, never in a shared cache either
        $id = $this->storedSession($owner);
        $this->assertNeverShared($this->videoFile($private, $id)->assertSuccessful());
        $this->assertNeverShared($this->videoFile($public, $id)->assertSuccessful());

        // another member's session gets the missing video's answer too: the session is read (the page is the signed-in
        // member's), nothing of it is written, no other session is stored, the answer is never shared, and its cookies are
        // those of a file that does not exist (none today: a 404 has nothing to play and renews nothing)
        $other = $this->storedSession(User::factory()->create());
        $content = File::get($this->sessions.'/'.$other);
        $refused = $this->videoFile($private, $other)->assertNotFound();
        $this->assertSame($content, File::get($this->sessions.'/'.$other), 'the stored session changed');
        $this->assertCount(2, File::files($this->sessions), 'another session was stored');
        $this->assertNeverShared($refused);
        $this->assertSame($this->answerOf($this->missingFile($other)), $this->answerOf($refused, $private->file), 'not the missing video\'s answer');
    }

    // ------------------------------------------------------------------ helpers

    private function videoWithFile(VideoStatus $status, User $owner): Video
    {
        $video = Video::factory()->withStatus($status)->for($owner)->create(['file' => 'sb-v1-'.Str::lower(Str::random(10)).'.mp4']);
        Storage::disk('local')->put(Video::VIDEO_FOLDER.'/'.$video->file, str_repeat('MP4-BYTES ', 100));

        return $video;
    }

    /**
     * A request of the page's <video>: a new session store, as a real request gets, and only the cookies a browser would
     * send ($sessionId null: no session cookie at all).
     *
     * @param  array<string, string>  $cookies
     */
    private function videoFile(Video $video, ?string $sessionId, array $cookies = []): TestResponse
    {
        $this->nextRequest($sessionId, $cookies);

        return $this->get(route('video.file', $video, false), ['Range' => 'bytes=0-']);
    }

    /** The <video>'s request for a file that does not exist (the same kind of request as videoFile()). */
    private function missingFile(?string $sessionId): TestResponse
    {
        $this->nextRequest($sessionId);

        return $this->get(route('video.file', ['video' => self::MISSING_FILE], false), ['Range' => 'bytes=0-']);
    }

    /**
     * An answer as the visitor gets it, without what differs by nature: its status, its headers (but the date, the length
     * and the cookies' values), the names of its cookies and its page ($file, the address's value, as <ID>; the form's
     * token and the nonces left out).
     *
     * @return array{0: int, 1: array<string, list<string|null>>, 2: list<string>, 3: string}
     */
    private function answerOf(TestResponse $response, string $file = self::MISSING_FILE): array
    {
        $headers = $response->headers->all();
        unset($headers['date'], $headers['content-length'], $headers['set-cookie']);
        ksort($headers);
        $cookies = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        sort($cookies);
        $page = (string) preg_replace(
            ['/name="csrf-token" content="[^"]+"/', '/name="_token" value="[^"]+"/', '/nonce="[^"]+"/'],
            ['csrf-token', '_token', 'nonce'],
            str_replace($file, '<ID>', (string) $response->getContent()),
        );

        return [$response->getStatusCode(), $headers, $cookies, $page];
    }

    private function page(string $uri, string $sessionId): TestResponse
    {
        $this->nextRequest($sessionId);

        return $this->get($uri);
    }

    /** @param  array<string, string>  $cookies */
    private function nextRequest(?string $sessionId, array $cookies = []): void
    {
        $this->freshStore();
        Auth::forgetGuards();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        if ($sessionId !== null) {
            $this->withCookie((string) config('session.cookie'), $sessionId);
        }

        foreach ($cookies as $name => $value) {
            $this->withCookie($name, $value);
        }
    }

    /**
     * A new session store for the next request, as a real request gets (it then reads the session from storage), and no
     * cookie left queued by the request before (the test client keeps one cookie jar for all its requests).
     */
    private function freshStore(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['cookie']->flushQueuedCookies();
    }

    /** A session stored under a new id, as a page view leaves it ($user: signed in); returns the id the browser holds. */
    private function storedSession(?User $user): string
    {
        $id = Str::random(40);
        $data = ['_token' => Str::random(40), '_previous' => ['url' => url('/'), 'route' => 'pages.home'], '_flash' => ['old' => [], 'new' => []]];

        if ($user !== null) {
            $data[Auth::guard('web')->getName()] = $user->getAuthIdentifier();
        }

        File::put($this->sessions.'/'.$id, serialize($data));

        return $id;
    }

    /** @return list<string> the session ids the answer's session cookie gives the browser (decrypted) */
    private function sessionIds(TestResponse $response): array
    {
        $cookie = $response->getCookie((string) config('session.cookie'));

        return $cookie === null ? [] : [(string) $cookie->getValue()];
    }

    private function assertNeverShared(TestResponse $response): void
    {
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('s-maxage', $cacheControl);
    }

    private function setFileTime(string $file, int $time): void
    {
        touch($file, $time);
        clearstatcache(true, $file);
    }

    private function fileTime(string $file): int
    {
        clearstatcache(true, $file);

        return (int) filemtime($file);
    }
}
