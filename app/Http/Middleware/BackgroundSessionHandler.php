<?php

namespace App\Http\Middleware;

use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\ExistenceAwareInterface;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use SessionHandlerInterface;
use Throwable;

/**
 * The session handler of one background request (BackgroundRequest): it reads through the site's own handler and keeps
 * nothing the request did. When the store saves at the end of the request, it does only this instead:
 *
 *  - the session the browser sent, still stored: kept alive without changing what is in it (keepAlive()), so a page
 *    left open with only its background requests stays signed in, as before;
 *  - no stored session (it had ended, or the browser had none) and the request signed the account in again from its
 *    "remember me" cookie: that new session is stored once, without "the page you came from" (the background address)
 *    and without one-time messages of its own. Otherwise every later background request would sign in again (the
 *    Login event, a users-table update) and the page would never get a session;
 *  - anything else (a new id given while the session existed, a session started for a visitor): nothing is stored.
 *
 * Nothing is ever destroyed or cleaned up through it. keepsCookie() tells the middleware whether the browser gets the
 * session cookie of the response (renewed, or the new session's) or keeps the one it has. The store's "this session
 * exists" flag reaches the database handler as before (a new id after a sign-in is a new row, not an update).
 *
 * ReadOnlySession uses it the same way for a route that keeps the session alive (ReadOnlySession::KEEP_ALIVE: the
 * video's own file, round 3 check SB-V1-01).
 */
final class BackgroundSessionHandler implements SessionHandlerInterface, ExistenceAwareInterface
{
    /** Seconds between two renewals of a session's time by background requests where a renewal costs a write. */
    public const KEEP_ALIVE_EVERY = 60;

    /** The cache key that spaces out renewals of a session kept by a cache store without its own "touch". */
    public const CACHE_MARK = 'background-request-alive:';

    private ?string $readId = null;

    private string $raw = '';

    private bool $found = false;

    private bool $alive = false;

    private bool $stored = false;

    private bool $saving = false;

    public function __construct(
        private readonly SessionHandlerInterface $inner,
        private readonly Store $store,
        private readonly Request $request,
    ) {
    }

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
        $data = $this->inner->read($id);

        // The store reads once, when the session starts: that is the session the browser sent (or a new id).
        if ($this->readId === null) {
            $this->readId = $id;
            $this->raw = is_string($data) ? $data : '';
            $this->found = $this->raw !== '';
        }

        return $data;
    }

    public function write(string $id, string $data): bool
    {
        if ($this->saving) {
            return $this->inner->write($id, $data);
        }

        if ($this->found && $id === $this->readId) {
            $this->alive = $this->keepAlive($id);
        } elseif (! $this->found && $this->readId !== null && $id !== $this->readId && self::signedIn($this->store)) {
            $this->storeNewSignIn();
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    /**
     * Store::migrate() (a sign-in, a new id) tells the handler the session under the new id does not exist yet. The
     * database handler needs it: it read the ended row of the old id, and without this it would update a row the new id
     * does not have instead of inserting one.
     */
    public function setExists($value)
    {
        if ($this->inner instanceof ExistenceAwareInterface) {
            $this->inner->setExists($value);
        }

        return $this;
    }

    /** Whether the browser gets the response's session cookie: its session was kept alive, or a new sign-in was stored. */
    public function keepsCookie(): bool
    {
        return $this->alive || $this->stored;
    }

    /** Whether the store holds a sign-in of an auth guard (SessionGuard keeps "login_<guard>_<sha1 of its class>"). */
    public static function signedIn(Store $store): bool
    {
        foreach ($store->all() as $key => $value) {
            if (is_string($key) && preg_match('/^login_\w+_[0-9a-f]{40}$/', $key) === 1 && $value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The request signed the account in again from its "remember me" cookie on a session that had ended: the new session
     * is stored this once, as a page view would store it, but without the background address as the previous page. It
     * holds nothing from before (there was nothing), so no one-time message of another page is used up.
     */
    private function storeNewSignIn(): void
    {
        $this->saving = true;

        try {
            $this->store->forget('_previous');
            $this->store->save();
            $this->stored = true;
        } catch (Throwable) {
            $this->stored = false; // nothing stored: the browser keeps the cookie it has
        } finally {
            $this->saving = false;
        }
    }

    /**
     * Keeps the stored session alive the way a page view does, without changing what is in it. False when it could not
     * be renewed (it ended meanwhile, the storage did not answer, an unknown handler): the browser then keeps its cookie.
     *
     *  - files: the file's time (at most once a minute);
     *  - database: the row's last activity (at most once a minute; never an ended row);
     *  - Redis: EXPIRE on the key, Memcached: touch (the value is not sent again; an ended key stays ended);
     *  - another cache store (APC, DynamoDB, files, database): the same value put back with a new lifetime, at most once a
     *    minute and only while it is still exactly what this request read;
     *  - cookie: the same data in a renewed cookie, only once less than half the lifetime is left (a page view renews it
     *    in full, so this happens only on a page left alone for that long);
     *  - memory (tests): the same data written again while unchanged.
     */
    private function keepAlive(string $id): bool
    {
        $seconds = max(1, (int) config('session.lifetime', 120)) * 60;
        $now = now()->getTimestamp();
        $handler = $this->inner;

        try {
            if ($handler instanceof FileSessionHandler) {
                $file = rtrim((string) config('session.files'), '/\\').DIRECTORY_SEPARATOR.$id;
                clearstatcache(true, $file);
                $time = is_file($file) ? (int) filemtime($file) : 0;

                if ($time <= $now - $seconds) {
                    return false;
                }

                return $time >= $now - self::KEEP_ALIVE_EVERY || @touch($file, $now);
            }

            if ($handler instanceof DatabaseSessionHandler) {
                $rows = static fn () => DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))->where('id', $id);
                $time = (int) $rows()->value('last_activity');

                if ($time <= $now - $seconds) {
                    return false;
                }

                if ($time < $now - self::KEEP_ALIVE_EVERY) {
                    $rows()->where('last_activity', '>', $now - $seconds)->update(['last_activity' => $now]);
                }

                return true;
            }

            if ($handler instanceof CacheBasedSessionHandler) {
                return self::keepCacheAlive($handler->getCache(), $id, $this->raw, $seconds);
            }

            if ($handler instanceof CookieSessionHandler) {
                $sent = json_decode((string) $this->request->cookies->get($id, ''), true);
                $expires = is_array($sent) ? (int) ($sent['expires'] ?? 0) : 0;

                if ($expires < $now) {
                    return false;
                }

                return $expires - $now > intdiv($seconds, 2) || $handler->write($id, $this->raw);
            }

            if ($handler instanceof ArraySessionHandler) {
                $current = $handler->read($id);

                if ($current === '' || $current === false) {
                    return false;
                }

                return $current !== $this->raw || $handler->write($id, $this->raw);
            }
        } catch (Throwable) {
            // the storage did not answer: nothing renewed
        }

        return false;
    }

    /** keepAlive() for the cache-based drivers (redis, memcached, apc, dynamodb and a SESSION_STORE). */
    private static function keepCacheAlive(CacheContract $cache, string $id, string $raw, int $seconds): bool
    {
        $store = $cache instanceof CacheRepository ? $cache->getStore() : null;

        if ($store instanceof RedisStore) {
            return (bool) $store->connection()->expire($store->getPrefix().$id, $seconds);
        }

        if ($store instanceof MemcachedStore) {
            return (bool) $store->getMemcached()->touch($store->getPrefix().$id, now()->getTimestamp() + $seconds);
        }

        // Renewed by a background request less than a minute ago: still alive.
        if (! $cache->add(self::CACHE_MARK.$id, 1, self::KEEP_ALIVE_EVERY)) {
            return true;
        }

        $current = $cache->get($id);

        if (! is_string($current) || $current === '') {
            return false; // ended meanwhile
        }

        // Another request stored the session after this one read it: that request renewed it, and its copy stays.
        return $current !== $raw || $cache->put($id, $raw, $seconds);
    }
}
