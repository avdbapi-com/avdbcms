<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsPageController;
use App\Http\Middleware\BackgroundRequest;
use App\Http\Middleware\BackgroundSessionHandler;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use SessionHandlerInterface;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: a GET the page sends by itself (X-Background-Request: 1) reads the session but
 * never changes it (App\Http\Middleware\BackgroundRequest). Admin Home fetched itself again in the background, and that
 * request used up the one-time message of the page the administrator opened meanwhile: the view-only refusal was lost
 * in 2 of 8 tries of the 1.2.2 test. Also: it never writes back an older copy of the session, and it still
 * keeps the session alive (the time of its file or row, the same cookie renewed), as every request did before.
 *
 * The flow tests keep the session in files and start a new session store for every request, as every request of a real
 * site does (the test client otherwise carries the session in memory from one request to the next).
 */
class BackgroundRequestTest extends TestCase
{
    use RefreshDatabase;

    private const CLOSED = 'This page is not part of the view-only demo: it would show private files or server details.';

    private ?string $sessions = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->sessions !== null) {
            File::deleteDirectory($this->sessions);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ the scenario of the 1.2.2 test

    public function test_a_background_get_leaves_the_refusal_message_for_the_page_it_belongs_to(): void
    {
        $id = $this->fileSessions();
        $this->actingAs($this->viewOnlyAdmin())->withCookie(config('session.cookie'), $id);

        // Backups is closed: back to Home with the one-time message, kept in the session file
        $this->visit('/admin/backups')->assertStatus(303)->assertHeader(ViewOnlyDemo::HEADER, '1');
        $kept = File::get($this->sessionFile($id));
        $this->assertStringContainsString(self::CLOSED, $kept);

        // Home's copy of itself, fetched in the background, reaches the site before the redirected page does
        $background = $this->visit('/admin', true)->assertOk();
        $this->assertSame([$id], $this->sessionIds($background), 'the browser keeps its session (the same id)');
        $this->assertSame($kept, File::get($this->sessionFile($id)), 'a background GET never writes the session');

        // the page the refusal sent the account to shows the message, once
        $this->visit('/admin')->assertOk()->assertSee(self::CLOSED);
        $this->visit('/admin')->assertOk()->assertDontSee(self::CLOSED);
    }

    public function test_without_the_marker_the_same_copy_of_the_page_used_the_message_up(): void
    {
        // what 1.2.2 did (and what any request without the marker still does): proves the test above tests the fix
        $id = $this->fileSessions();
        $this->actingAs($this->viewOnlyAdmin())->withCookie(config('session.cookie'), $id);

        $this->visit('/admin/backups')->assertStatus(303);
        $this->visit('/admin')->assertOk();
        $this->visit('/admin')->assertOk()->assertDontSee(self::CLOSED);
    }

    public function test_a_flash_message_waits_for_its_page_whatever_the_admin_fetches_meanwhile(): void
    {
        $this->routeFirst('lanea-bg/save', fn () => redirect('/admin')->with('success', 'Your changes are saved.'), ['GET']);
        $id = $this->fileSessions();
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);

        $this->visit('/lanea-bg/save')->assertRedirect();

        // the deferred sections' probes, the Ctrl+K index, a list fetched again, the settings' long values
        $this->visit(route('admin.status.probe', ['probe' => 'folders']), true)->assertOk();
        $this->visit(route('admin.shell.search-index', ['v' => 'x', 'l' => 'en']), true)->assertOk();
        $this->visit('/admin/users', true)->assertOk();
        $this->visit(route('admin.settings.values', ['keys' => 'robots_txt']), true)->assertOk();

        $this->visit('/admin')->assertOk()->assertSee('Your changes are saved.');
    }

    // ------------------------------------------------------------------ never writes, never destroys

    public function test_a_background_get_reads_the_session_but_never_writes_destroys_or_cleans_it(): void
    {
        $spy = $this->spySessions();
        $id = Str::random(40);
        $spy->data[$id] = serialize(['_token' => Str::random(40), 'kept' => 'yes']);
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);
        config(['session.lottery' => [1, 1]]); // the clean-up of old sessions on every request

        $this->visit('/admin', true)->assertOk();
        $this->assertSame(['read' => 1], $spy->counts(), 'read once, nothing written, destroyed or cleaned');

        $spy->calls = [];
        $this->visit('/admin')->assertOk();
        $this->assertSame(1, $spy->counts()['write'] ?? 0, 'a normal page view writes as always');
        $this->assertSame(1, $spy->counts()['gc'] ?? 0);
    }

    public function test_whatever_a_background_get_puts_in_the_session_is_not_kept(): void
    {
        $this->routeFirst('lanea-bg/seed', function () {
            session()->put('seed', 'kept');
            session()->flash('success', 'Saved before.');

            return response('seeded');
        }, ['GET']);
        $this->routeFirst('lanea-bg/write', function () {
            session()->put('written', 'by a background request');
            session()->flash('success', 'A message of the background request');
            session()->regenerate(true); // destroys the old one and takes a new id

            return response('wrote');
        }, ['GET', 'HEAD']);
        $this->routeFirst('lanea-bg/read', fn () => response()->json([
            'seed' => session('seed'), 'written' => session('written'), 'success' => session('success'),
        ]), ['GET']);

        $id = $this->fileSessions();
        $this->withCookie(config('session.cookie'), $id);
        $this->visit('/lanea-bg/seed')->assertOk();
        $before = File::get($this->sessionFile($id));

        foreach (['GET', 'HEAD'] as $method) {
            $this->freshStore();
            $response = $this->call($method, '/lanea-bg/write', [], $this->cookies(), [], $this->transformHeadersToServerVars([BackgroundRequest::HEADER => '1']));
            $response->assertOk();
            $this->assertSame([], $this->sessionCookies($response), $method.': no session cookie, no new session id for the browser');
            $this->assertSame($before, File::get($this->sessionFile($id)), $method.': the session file is unchanged');
            $this->assertCount(1, File::files($this->sessions), $method.': no other session was stored');
        }

        // what the background request stored is gone; the message of before still waits for its page
        $this->visit('/lanea-bg/read')->assertOk()->assertExactJson(['seed' => 'kept', 'written' => null, 'success' => 'Saved before.']);
    }

    // ------------------------------------------------------------------ still keeps the session alive

    public function test_a_page_left_open_with_only_background_requests_keeps_its_session(): void
    {
        $id = $this->fileSessions();
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);
        $this->visit('/admin/users')->assertOk();
        $file = $this->sessionFile($id);
        $content = File::get($file);

        // an import's progress page, open for 30 minutes with nothing but its polls
        $this->setFileTime($file, now()->subMinutes(30)->getTimestamp());
        $response = $this->visit('/admin', true)->assertOk();

        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp(), $this->fileTime($file), 'its time moves on, as with a page view');
        $this->assertSame($content, File::get($file), 'what is in it stays as it was');
        $this->assertSame([$id], $this->sessionIds($response), 'the same session cookie, renewed');

        // within a minute the time is left as it is (a poll every few seconds renews it once a minute)
        $recent = now()->subSeconds(20)->getTimestamp();
        $this->setFileTime($file, $recent);
        $this->assertSame([$id], $this->sessionIds($this->visit('/admin', true)->assertOk()));
        $this->assertSame($recent, $this->fileTime($file));
    }

    public function test_an_ended_or_missing_session_is_never_brought_back(): void
    {
        $id = $this->fileSessions();
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);
        $this->visit('/admin/users')->assertOk();
        $file = $this->sessionFile($id);
        $content = File::get($file);

        $ended = now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp();
        $this->setFileTime($file, $ended);
        $response = $this->visit('/admin', true);
        $this->assertSame($ended, $this->fileTime($file), 'an ended session keeps its time');
        $this->assertSame($content, File::get($file));
        $this->assertSame([], $this->sessionCookies($response), 'and its cookie is not renewed');

        // no stored session (cleaned up, or a new visitor): nothing is stored, no cookie is given
        File::delete($file);
        $this->assertSame([], $this->sessionCookies($this->visit('/admin', true)));
        $this->assertSame([], File::files($this->sessions));

        $this->routeFirst('lanea-bg/guest', fn () => response('guest'), ['GET']);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->freshStore();
        // a visitor without any cookie
        $guest = $this->call('GET', '/lanea-bg/guest', [], [], [], $this->transformHeadersToServerVars([BackgroundRequest::HEADER => '1']));
        $guest->assertOk();
        $this->assertSame([], $this->sessionCookies($guest));
        $this->assertSame([], File::files($this->sessions));
    }

    public function test_with_database_sessions_only_the_last_activity_moves_on(): void
    {
        // the sessions table of the site's own database (.env.example names a "session" connection only the file driver ignores)
        config(['session.driver' => 'database', 'session.connection' => null, 'session.lottery' => [0, 100]]);
        $this->freshStore();
        $id = Str::random(40);
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);
        $this->visit('/admin/users')->assertOk();
        $row = DB::table('sessions')->where('id', $id)->first();
        $this->assertNotNull($row);

        DB::table('sessions')->where('id', $id)->update(['last_activity' => now()->subMinutes(30)->getTimestamp()]);
        $response = $this->visit('/admin', true)->assertOk();
        $after = DB::table('sessions')->where('id', $id)->first();

        $this->assertSame($row->payload, $after->payload, 'what is in it stays as it was');
        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp(), (int) $after->last_activity);
        $this->assertSame([$id], $this->sessionIds($response));

        // an ended one is left alone
        $ended = now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp();
        DB::table('sessions')->where('id', $id)->update(['last_activity' => $ended]);
        $this->assertSame([], $this->sessionCookies($this->visit('/admin', true)));
        $this->assertSame($ended, (int) DB::table('sessions')->where('id', $id)->value('last_activity'));
    }

    public function test_redis_sessions_get_a_new_lifetime_without_being_written(): void
    {
        // SESSION_DRIVER=redis (.env.example names the "session" connection): EXPIRE on the key, never the value again
        $redis = $this->fakeRedis();
        $factory = new class($redis) implements RedisFactory
        {
            public function __construct(private readonly object $connection)
            {
            }

            public function connection($name = null)
            {
                return $this->connection;
            }
        };
        $store = new RedisStore($factory, 'lanea:');
        $this->cacheSessions($store);
        $id = Str::random(40);
        $key = $store->getPrefix().$id;
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);

        $this->visit('/admin/users')->assertOk();
        $this->assertArrayHasKey($key, $redis->values, 'a page view stores the session');
        $content = $redis->values[$key];
        $redis->ttl[$key] = 90; // a page left open: a minute and a half before the session would end
        $redis->calls = [];

        $response = $this->visit('/admin', true)->assertOk();
        $this->assertSame(['expire'], $redis->writes(), 'only its lifetime moves on');
        $this->assertSame((int) config('session.lifetime') * 60, $redis->ttl[$key]);
        $this->assertSame($content, $redis->values[$key], 'what is in it stays as it was');
        $this->assertSame([$id], $this->sessionIds($response), 'the same session cookie, renewed');

        // an ended session (the key expired) is never brought back
        unset($redis->values[$key], $redis->ttl[$key]);
        $redis->calls = [];
        $response = $this->visit('/admin', true);
        $this->assertSame([], $redis->writes(), 'nothing is sent for a session that is not stored');
        $this->assertArrayNotHasKey($key, $redis->values);
        $this->assertSame([], $this->sessionCookies($response));
    }

    public function test_memcached_sessions_are_touched_without_being_written(): void
    {
        $memcached = new class
        {
            /** @var array<string, string> */
            public array $values = [];

            /** @var array<string, int> */
            public array $expires = [];

            /** @var list<string> */
            public array $calls = [];

            private int $code = 0;

            public function get(string $key): mixed
            {
                $this->code = isset($this->values[$key]) ? 0 : 16; // Memcached::RES_NOTFOUND

                return $this->values[$key] ?? false;
            }

            public function getResultCode(): int
            {
                return $this->code;
            }

            public function set(string $key, mixed $value, int $expiration): bool
            {
                $this->calls[] = 'set';
                $this->values[$key] = $value;
                $this->expires[$key] = $expiration;

                return true;
            }

            public function delete(string $key): bool
            {
                $this->calls[] = 'delete';
                unset($this->values[$key], $this->expires[$key]);

                return true;
            }

            public function touch(string $key, int $expiration): bool
            {
                $this->calls[] = 'touch';

                if (! isset($this->values[$key])) {
                    return false;
                }

                $this->expires[$key] = $expiration;

                return true;
            }
        };
        // built without its constructor: that one only asks the memcached extension which getMulti() it has
        $store = (new \ReflectionClass(MemcachedStore::class))->newInstanceWithoutConstructor();
        (function (object $memcached): void {
            $this->memcached = $memcached;
            $this->prefix = 'lanea:';
            $this->onVersionThree = true;
        })->call($store, $memcached);
        $this->cacheSessions($store);
        $id = Str::random(40);
        $key = 'lanea:'.$id;
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);

        $this->visit('/admin/users')->assertOk();
        $content = $memcached->values[$key];
        $memcached->expires[$key] = now()->getTimestamp() + 60;
        $memcached->calls = [];

        $response = $this->visit('/admin', true)->assertOk();
        $this->assertSame(['touch'], $memcached->calls);
        $this->assertGreaterThanOrEqual(now()->getTimestamp() + (int) config('session.lifetime') * 60 - 5, $memcached->expires[$key]);
        $this->assertSame($content, $memcached->values[$key]);
        $this->assertSame([$id], $this->sessionIds($response));

        unset($memcached->values[$key]);
        $this->assertSame([], $this->sessionCookies($this->visit('/admin', true)));
        $this->assertArrayNotHasKey($key, $memcached->values);
    }

    public function test_other_cache_stores_get_the_same_value_back_at_most_once_a_minute_and_never_over_a_newer_one(): void
    {
        // apc, dynamodb or a SESSION_STORE of files or the database: no "touch", so the value read is put back with a new
        // lifetime, only while it is still exactly that value
        $store = new class extends ArrayStore
        {
            public int $puts = 0;

            public function put($key, $value, $seconds)
            {
                if (! str_starts_with((string) $key, BackgroundSessionHandler::CACHE_MARK)) {
                    $this->puts++; // the session's own value (the once-a-minute mark is put through add())
                }

                return parent::put($key, $value, $seconds);
            }
        };
        $repository = $this->cacheSessions($store);
        $id = Str::random(40);
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);
        $this->visit('/admin/users')->assertOk();
        $content = $repository->get($id);
        $this->assertIsString($content);

        // 110 of the 120 minutes later, with only background requests
        $this->travel(110)->minutes();
        $store->puts = 0;
        $response = $this->visit('/admin', true)->assertOk();
        $this->assertSame(1, $store->puts, 'put back once');
        $this->assertSame([$id], $this->sessionIds($response));
        $this->visit('/admin', true)->assertOk();
        $this->assertSame(1, $store->puts, 'not again within the minute');

        // 30 more minutes: past the first lifetime, the session is still there, unchanged
        $this->travel(30)->minutes();
        $this->assertSame($content, $repository->get($id));

        // a newer copy stored by another request while a background request ran is never overwritten
        $this->routeFirst('lanea-bg/newer', function () use ($repository, $id) {
            $repository->put($id, 'stored by another request meanwhile', 7200);

            return response('ok');
        }, ['GET']);
        $this->travel(2)->minutes();
        $this->visit('/lanea-bg/newer', true)->assertOk();
        $this->assertSame('stored by another request meanwhile', $repository->get($id));

        // ended: never brought back
        $repository->forget($id);
        $this->travel(2)->minutes();
        $this->assertSame([], $this->sessionCookies($this->visit('/admin', true)));
        $this->assertNull($repository->get($id));
    }

    public function test_cookie_sessions_are_renewed_with_the_data_the_browser_sent_once_half_their_life_is_gone(): void
    {
        config(['session.driver' => 'cookie', 'session.lottery' => [0, 100]]);
        $this->freshStore();
        $id = Str::random(40);
        $lifetime = (int) config('session.lifetime') * 60;
        // a one-time message waits in the session: it must reach its page whole
        $data = serialize(['_token' => Str::random(40), '_flash' => ['old' => [], 'new' => ['success']], 'success' => 'Saved.']);
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);

        // 50 of 120 minutes left: renewed, with the same data
        $this->withCookie($id, (string) json_encode(['data' => $data, 'expires' => now()->getTimestamp() + 3000]));
        $response = $this->visit('/admin', true)->assertOk();
        $renewed = $response->getCookie($id);
        $this->assertNotNull($renewed, 'the session cookie is sent again');
        $payload = (array) json_decode((string) $renewed->getValue(), true);
        $this->assertSame($data, $payload['data'] ?? null, 'the same data: no message used up, nothing older written back');
        $this->assertGreaterThanOrEqual(now()->getTimestamp() + $lifetime - 5, (int) ($payload['expires'] ?? 0));
        $this->assertSame([$id], $this->sessionIds($response));

        // more than half left (a page view renewed it): nothing sent, the browser's cookie stays
        $this->withCookie($id, (string) json_encode(['data' => $data, 'expires' => now()->getTimestamp() + 6000]));
        $response = $this->visit('/admin', true)->assertOk();
        $this->assertNull($response->getCookie($id));
        $this->assertSame([$id], $this->sessionIds($response));

        // ended
        $this->withCookie($id, (string) json_encode(['data' => $data, 'expires' => now()->getTimestamp() - 10]));
        $response = $this->visit('/admin', true);
        $this->assertNull($response->getCookie($id));
        $this->assertSame([], $this->sessionCookies($response));
    }

    // ------------------------------------------------------------------ "remember me" after the session ended

    public function test_a_remember_me_sign_in_after_the_session_ended_is_stored_once(): void
    {
        // 1.2.2 stored the session of every request; a background request that stores nothing signed the account in
        // again from its "remember me" cookie on every poll (the Login event, a users-table update each time)
        $id = $this->fileSessions();
        $admin = $this->admin();
        $token = Str::random(60);
        $admin->forceFill(['remember_token' => $token])->save();
        $guard = $this->app['auth']->guard('web');
        Event::fake([Login::class]);

        // the browser's session ended long ago (no file left); the page's poll comes with the "remember me" cookie
        $this->withCookie(config('session.cookie'), $id)
            ->withCookie($guard->getRecallerName(), $admin->getAuthIdentifier().'|'.$token.'|'.$admin->getAuthPassword());
        $this->app['auth']->forgetGuards();
        $response = $this->visit('/admin', true)->assertOk();

        Event::assertDispatchedTimes(Login::class, 1);
        $new = $this->sessionIds($response);
        $this->assertCount(1, $new, 'the browser gets the new session');
        $this->assertNotSame($id, $new[0]);
        $this->assertFileDoesNotExist($this->sessionFile($id), 'the ended one is not brought back');
        $stored = unserialize(File::get($this->sessionFile($new[0])));
        $this->assertSame($admin->id, $stored[$this->app['auth']->guard('web')->getName()] ?? null, 'stored with the sign-in');
        $this->assertArrayNotHasKey('_previous', $stored, 'the background address is not the page you came from');

        // the next polls use it: no new sign-in, nothing else stored
        $this->withCookie(config('session.cookie'), $new[0]);
        foreach ([1, 2] as $poll) {
            $this->app['auth']->forgetGuards();
            $this->assertSame([$new[0]], $this->sessionIds($this->visit('/admin', true)->assertOk()), "poll {$poll}");
        }
        Event::assertDispatchedTimes(Login::class, 1);
        $this->assertCount(1, File::files($this->sessions));
    }

    public function test_with_database_sessions_the_new_sign_in_is_a_new_row_and_the_ended_one_stays_ended(): void
    {
        // the database handler read the ended row of the old id: the new session must be inserted, not "updated"
        config(['session.driver' => 'database', 'session.connection' => null, 'session.lottery' => [0, 100]]);
        $this->freshStore();
        $id = Str::random(40);
        $admin = $this->admin();
        $token = Str::random(60);
        $admin->forceFill(['remember_token' => $token])->save();
        $guard = $this->app['auth']->guard('web');
        $ended = now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp();
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'payload' => base64_encode(serialize(['_token' => Str::random(40)])), 'last_activity' => $ended]);
        Event::fake([Login::class]);

        $this->withCookie(config('session.cookie'), $id)
            ->withCookie($guard->getRecallerName(), $admin->getAuthIdentifier().'|'.$token.'|'.$admin->getAuthPassword());
        $this->app['auth']->forgetGuards();
        $response = $this->visit('/admin', true)->assertOk();

        Event::assertDispatchedTimes(Login::class, 1);
        $new = $this->sessionIds($response);
        $this->assertCount(1, $new);
        $this->assertNotSame($id, $new[0]);
        $row = DB::table('sessions')->where('id', $new[0])->first();
        $this->assertNotNull($row, 'the new session is a row of its own');
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertSame($ended, (int) DB::table('sessions')->where('id', $id)->value('last_activity'), 'the ended row is not brought back');

        $this->withCookie(config('session.cookie'), $new[0]);
        $this->app['auth']->forgetGuards();
        $this->assertSame([$new[0]], $this->sessionIds($this->visit('/admin', true)->assertOk()));
        Event::assertDispatchedTimes(Login::class, 1);
        $this->assertSame(2, DB::table('sessions')->count());
    }

    public function test_a_stored_session_keeps_its_messages_when_a_background_request_signs_in(): void
    {
        // a session still stored (a visitor's, with a message waiting) is never replaced by a background request: only
        // one that had ended takes the new sign-in
        $id = $this->fileSessions();
        $admin = $this->admin();
        $token = Str::random(60);
        $admin->forceFill(['remember_token' => $token])->save();
        $guard = $this->app['auth']->guard('web');
        $visitor = serialize(['_token' => Str::random(40), '_flash' => ['old' => [], 'new' => ['success']], 'success' => 'Message sent.']);
        File::put($this->sessionFile($id), $visitor);

        $this->withCookie(config('session.cookie'), $id)
            ->withCookie($guard->getRecallerName(), $admin->getAuthIdentifier().'|'.$token.'|'.$admin->getAuthPassword());
        $this->app['auth']->forgetGuards();
        $response = $this->visit('/admin', true)->assertOk();

        $this->assertSame($visitor, File::get($this->sessionFile($id)), 'the stored session is unchanged');
        $this->assertCount(1, File::files($this->sessions), 'no other session stored');
        $this->assertSame([], $this->sessionCookies($response), 'the browser keeps its cookie');

        // the page view that follows signs in as always and shows the message
        $this->app['auth']->forgetGuards();
        $this->visit('/lanea-bg/none');
        $this->assertSame(1, BackgroundRequestTest::sessionFilesWith($this->sessions, 'Message sent.'));
    }

    public function test_the_background_handler_alone_stores_and_destroys_nothing(): void
    {
        $inner = new \Illuminate\Session\ArraySessionHandler(120);
        $handler = new BackgroundSessionHandler($inner, $this->app['session']->driver(), Request::create('/'));
        $id = Str::random(40);

        $this->assertSame('', $handler->read($id));
        $this->assertTrue($handler->write(Str::random(40), 'a session started by the request'));
        $this->assertSame('', $inner->read($id), 'nothing written');
        $inner->write($id, 'kept');
        $this->assertTrue($handler->destroy($id));
        $this->assertSame(0, $handler->gc(1));
        $this->assertSame('kept', $inner->read($id), 'nothing destroyed');
        $this->assertFalse($handler->keepsCookie());
    }

    /** How many session files hold $text. */
    public static function sessionFilesWith(string $folder, string $text): int
    {
        return count(array_filter(File::files($folder), static fn ($file): bool => str_contains((string) File::get($file->getPathname()), $text)));
    }

    // ------------------------------------------------------------------ only marked GET and HEAD

    public function test_only_a_marked_get_or_head_is_a_background_request(): void
    {
        $make = static function (string $method, array $server): Request {
            return Request::createFromBase(\Symfony\Component\HttpFoundation\Request::create('/admin', $method, [], [], [], $server));
        };

        $this->assertTrue(BackgroundRequest::is($make('GET', ['HTTP_X_BACKGROUND_REQUEST' => '1'])));
        $this->assertTrue(BackgroundRequest::is($make('HEAD', ['HTTP_X_BACKGROUND_REQUEST' => '1'])));
        $this->assertFalse(BackgroundRequest::is($make('GET', [])));
        $this->assertFalse(BackgroundRequest::is($make('GET', ['HTTP_X_BACKGROUND_REQUEST' => 'true'])));
        $this->assertFalse(BackgroundRequest::is($make('POST', ['HTTP_X_BACKGROUND_REQUEST' => '1'])));
        $this->assertFalse(BackgroundRequest::is($make('PUT', ['HTTP_X_BACKGROUND_REQUEST' => '1'])));
        // a POST that asks to be routed as a GET is still a POST
        $this->assertFalse(BackgroundRequest::is($make('POST', ['HTTP_X_BACKGROUND_REQUEST' => '1', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET'])));
    }

    public function test_writes_and_unmarked_requests_keep_the_session_as_always(): void
    {
        $this->routeFirst('lanea-bg/post', function () {
            session()->put('posted', 'yes');

            return response('posted');
        }, ['POST']);
        $spy = $this->spySessions();
        $id = Str::random(40);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)->withCookie(config('session.cookie'), $id);

        // a POST with the header: a write, not a background read
        $this->freshStore();
        $this->call('POST', '/lanea-bg/post', [], $this->cookies(), [], $this->transformHeadersToServerVars([BackgroundRequest::HEADER => '1']))->assertOk();
        $this->assertSame(1, $spy->counts()['write'] ?? 0);
        $this->assertStringContainsString('posted', (string) $spy->data[$id]);

        // a GET whose header is not "1"
        $spy->calls = [];
        $this->freshStore();
        $this->call('GET', '/admin/users', [], $this->cookies(), [], $this->transformHeadersToServerVars([BackgroundRequest::HEADER => 'yes']));
        $this->assertSame(1, $spy->counts()['write'] ?? 0);
    }

    public function test_a_background_get_is_never_the_page_you_came_from(): void
    {
        $id = $this->fileSessions();
        $this->actingAs($this->admin())->withCookie(config('session.cookie'), $id);

        $this->visit('/admin/users')->assertOk();
        // the search index (no X-Requested-With: 1.2.2 kept it as "the previous page") and a list fetched again
        $this->visit(route('admin.shell.search-index', ['v' => 'x', 'l' => 'en']), true)->assertOk();
        $this->visit('/admin/videos', true)->assertOk();

        $session = unserialize(File::get($this->sessionFile($id)));
        $this->assertStringEndsWith('/admin/users', $session['_previous']['url'] ?? '');
    }

    public function test_the_sites_own_scripts_get_the_same_in_the_api(): void
    {
        $spy = $this->spySessions();
        $id = Str::random(40);
        $spy->data[$id] = serialize(['_token' => Str::random(40), '_flash' => ['old' => ['success'], 'new' => []], 'success' => 'Profile saved.']);
        config(['sanctum.stateful' => [(string) parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '')]]);
        $this->withCookie(config('session.cookie'), $id);
        $before = $spy->data[$id];

        // the site's lists (VideosList, comments, notifications) through jsonFetch(): a session started by Sanctum
        $this->freshStore();
        $this->call('GET', '/api/videos', [], $this->cookies(), [], $this->transformHeadersToServerVars([
            BackgroundRequest::HEADER => '1', 'Accept' => 'application/json', 'Referer' => url('/'),
        ]))->assertOk();
        $this->assertSame(1, $spy->counts()['read'] ?? 0, 'the session is read (signed-in members)');
        $this->assertSame(0, $spy->counts()['write'] ?? 0);
        $this->assertSame($before, $spy->data[$id], 'the message waits for its page');

        $spy->calls = [];
        $this->freshStore();
        $this->call('GET', '/api/videos', [], $this->cookies(), [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json', 'Referer' => url('/'),
        ]))->assertOk();
        $this->assertSame(1, $spy->counts()['write'] ?? 0, 'without the marker the API request writes as always');
    }

    public function test_the_sites_background_gets_keep_the_session_alive_too(): void
    {
        // the api group: the session is started inside Sanctum's middleware, after this one (cookies decrypted there)
        $id = $this->fileSessions();
        config(['sanctum.stateful' => [(string) parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '')]]);
        $this->withCookie(config('session.cookie'), $id);
        $this->visit('/lanea-bg/none'); // any page view stores the session
        $file = $this->sessionFile($id);
        $this->assertFileExists($file);
        $content = File::get($file);
        $this->setFileTime($file, now()->subMinutes(30)->getTimestamp());

        $this->freshStore();
        $response = $this->call('GET', '/api/videos', [], $this->cookies(), [], $this->transformHeadersToServerVars([
            BackgroundRequest::HEADER => '1', 'Accept' => 'application/json', 'Referer' => url('/'),
        ]))->assertOk();

        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->getTimestamp(), $this->fileTime($file));
        $this->assertSame($content, File::get($file));
        $this->assertSame([$id], $this->sessionIds($response));
    }

    // ------------------------------------------------------------------ the scripts mark their background GETs

    public function test_every_background_get_of_the_admin_and_the_site_carries_the_marker(): void
    {
        $header = BackgroundRequest::HEADER;
        $this->assertSame('X-Background-Request', $header);

        $dom = $this->source('resources/admin/lib/dom.ts');
        $this->assertStringContainsString("export const BACKGROUND_HEADER = '{$header}';", $dom);
        $this->assertMatchesRegularExpression('/export const backgroundGet = .*\[BACKGROUND_HEADER\]: \'1\'/s', $dom);

        // the deferred sections (Home ...), the lists fetched again after a row action, the Ctrl+K search
        $probes = $this->source('resources/admin/modules/list-probes.ts');
        $this->assertStringContainsString('backgroundGet(url,', $probes);
        $this->assertStringNotContainsString('fetch(', str_replace('backgroundGet(', '', $probes));
        $this->assertStringContainsString('backgroundGet(window.location.href,', $this->source('resources/admin/modules/list-rows.ts'));
        $palette = $this->source('resources/admin/palette.ts');
        $this->assertSame(2, substr_count($palette, 'backgroundGet(url,'));
        $this->assertStringNotContainsString(' fetch(', $palette);
        // the Appearance pages' reads
        $this->assertStringContainsString("if (method.toUpperCase() === 'GET') headers[BACKGROUND_HEADER] = '1';", $this->source('resources/admin/appearance/appearance.ts'));

        // the admin's page scripts print the PHP constant (the old settings page gets it with
        // its own test, OldSettingsPageTextsTest)
        foreach (['resources/views/admin/avdb-import/index.blade.php', 'resources/views/admin/xvid-import/index.blade.php',
            'resources/views/admin/settings/hub.blade.php'] as $view) {
            $this->assertStringContainsString('@json(\App\Http\Middleware\BackgroundRequest::HEADER)', $this->source($view), $view);
        }
        $this->assertStringContainsString("lazyHeaders[config.background || '{$header}'] = '1';", $this->source('resources/views/admin/settings/page.js'));
        $this->assertTrue(method_exists(SettingsPageController::class, 'values'));
        $this->assertStringContainsString("'background' => \App\Http\Middleware\BackgroundRequest::HEADER", $this->source('app/Http/Controllers/Admin/SettingsPageController.php'));

        // the site: every GET of jsonFetch() (lists, comments, notifications, history, searches)
        $api = $this->source('resources/js/functions/api.ts');
        $this->assertStringContainsString("export const BACKGROUND_HEADER = '{$header}';", $api);
        $this->assertStringContainsString("if (method.toUpperCase() === 'GET') {\n        headers[BACKGROUND_HEADER] = '1';", str_replace("\r\n", "\n", $api));
    }

    // ------------------------------------------------------------------ helpers

    /** Sessions in files of their own; returns the browser's session id. */
    private function fileSessions(): string
    {
        $this->sessions = storage_path('framework/testing/sessions-lanea-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->sessions);
        config(['session.driver' => 'file', 'session.files' => $this->sessions, 'session.lottery' => [0, 100]]);
        $this->freshStore();

        return Str::random(40);
    }

    private function spySessions(): object
    {
        $spy = new class implements SessionHandlerInterface
        {
            /** @var array<string, string> */
            public array $data = [];

            /** @var list<string> */
            public array $calls = [];

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string|false
            {
                $this->calls[] = 'read';

                return $this->data[$id] ?? '';
            }

            public function write(string $id, string $data): bool
            {
                $this->calls[] = 'write';
                $this->data[$id] = $data;

                return true;
            }

            public function destroy(string $id): bool
            {
                $this->calls[] = 'destroy';
                unset($this->data[$id]);

                return true;
            }

            public function gc(int $max_lifetime): int|false
            {
                $this->calls[] = 'gc';

                return 0;
            }

            /** @return array<string, int> */
            public function counts(): array
            {
                return array_count_values($this->calls);
            }
        };

        $this->app['session']->extend('lanea-spy', fn () => $spy);
        config(['session.driver' => 'lanea-spy', 'session.lottery' => [0, 100]]);
        $this->freshStore();

        return $spy;
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

    /** Sessions kept by a cache store (the "redis", "memcached", "apc" and "dynamodb" drivers): CacheBasedSessionHandler. */
    private function cacheSessions(Store $store): CacheRepository
    {
        $repository = new CacheRepository($store);
        // the manager wraps the handler a custom driver returns in its session store
        $this->app['session']->extend('lanea-cache', fn () => new CacheBasedSessionHandler($repository, (int) config('session.lifetime')));
        config(['session.driver' => 'lanea-cache', 'session.lottery' => [0, 100]]);
        $this->freshStore();

        return $repository;
    }

    /**
     * A Redis server in memory: the commands RedisStore sends for sessions (get, setex, del, expire), with their lifetimes.
     */
    private function fakeRedis(): object
    {
        return new class
        {
            /** @var array<string, string> */
            public array $values = [];

            /** @var array<string, int> seconds left */
            public array $ttl = [];

            /** @var list<array{0: string, 1: string, 2?: int}> */
            public array $calls = [];

            public function get(string $key): ?string
            {
                $this->calls[] = ['get', $key];

                return $this->values[$key] ?? null;
            }

            public function setex(string $key, int $seconds, string $value): bool
            {
                $this->calls[] = ['setex', $key, $seconds];
                $this->values[$key] = $value;
                $this->ttl[$key] = $seconds;

                return true;
            }

            public function del(string $key): int
            {
                $this->calls[] = ['del', $key];
                $had = isset($this->values[$key]);
                unset($this->values[$key], $this->ttl[$key]);

                return $had ? 1 : 0;
            }

            public function expire(string $key, int $seconds): int
            {
                $this->calls[] = ['expire', $key, $seconds];

                if (! isset($this->values[$key])) {
                    return 0;
                }

                $this->ttl[$key] = $seconds;

                return 1;
            }

            /** @return list<string> the commands that changed something (no reads) */
            public function writes(): array
            {
                return array_values(array_map(static fn (array $call): string => $call[0], array_filter($this->calls, static fn (array $call): bool => $call[0] !== 'get')));
            }
        };
    }

    private function visit(string $uri, bool $background = false): TestResponse
    {
        $this->freshStore();

        return $this->get($uri, $background ? [BackgroundRequest::HEADER => '1'] : []);
    }

    /** @return list<string> the session cookies a response sets (other cookies, such as XSRF-TOKEN, do not count) */
    private function sessionCookies(TestResponse $response): array
    {
        return array_values(array_map(
            static fn ($cookie): string => $cookie->getName(),
            array_filter($response->headers->getCookies(), static fn ($cookie): bool => $cookie->getName() === config('session.cookie'))
        ));
    }

    /** @return list<string> the session ids the response's session cookie gives the browser (decrypted) */
    private function sessionIds(TestResponse $response): array
    {
        $cookie = $response->getCookie((string) config('session.cookie'));

        return $cookie === null ? [] : [(string) $cookie->getValue()];
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

    /** @return array<string, string> the test client's cookies, encrypted as a browser sends them */
    private function cookies(): array
    {
        return $this->prepareCookiesForRequest();
    }

    private function sessionFile(string $id): string
    {
        return $this->sessions.'/'.$id;
    }

    /** @param list<string> $methods */
    private function routeFirst(string $uri, Closure $action, array $methods): void
    {
        $router = $this->app['router'];
        $route = (new \Illuminate\Routing\Route($methods, $uri, $action))->middleware('web')->setRouter($router)->setContainer($this->app);
        $routes = new \Illuminate\Routing\RouteCollection();
        $routes->add($route);
        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $routes->add($existing);
        }
        $router->setRoutes($routes);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function viewOnlyAdmin(): User
    {
        $user = User::factory()->admin()->create(['password' => 'Demo-pass-1234']);
        $user->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        return $user->fresh();
    }
}
