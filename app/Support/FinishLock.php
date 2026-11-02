<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The lock of the database step of "Finish update" (App\Support\PendingMigrations): two clicks, two tabs or two
 * administrators never run the migrations at once, and a request the host ended half-way never holds the next one up.
 *
 * A host ends a request that runs too long in two ways. PHP's own time limit ends it with a fatal error: PHP still
 * closes the request's files and database connection. PHP-FPM's request_terminate_timeout, a web server's timeout, an
 * out-of-memory kill or a restart end the PHP process itself: no PHP code runs any more (no "finally", no shutdown
 * function), and only the operating system and the database see that it is gone. So the lock is made of locks that
 * they release by themselves, whatever ended the request:
 *
 *  - a file lock (flock) on storage/framework/avd-finish-update.lock: the operating system releases it when the
 *    request closes the file or its process ends;
 *  - on MySQL and MariaDB, a named lock of the database session (GET_LOCK): the database releases it as soon as it
 *    sees the connection closed - at once while the session idles, else when the statement the ended request had
 *    started is over or stopped (an index being built on a big table goes on in the database after PHP is gone, and
 *    must not be started a second time meanwhile);
 *  - the cache lock 1.2.2 used (Cache::lock, SECONDS), which several web servers sharing one cache store also see.
 *    Nothing releases it when a process is killed: a request that holds the other two takes it over when the request
 *    it belonged to held them too (the note next to it says so) - that request is gone. Where neither works (a disk
 *    without file locks and a database without named locks, e.g. a Galera cluster), it is released after SECONDS.
 */
final class FinishLock
{
    /** The name of the cache lock (the same as 1.2.2's), of the lock file, and the start of the database's named lock. */
    public const NAME = 'avd-finish-update';

    /**
     * How long the cache lock holds when nobody releases it and no request can tell that its holder is gone (see the
     * class): longer than a request of Finish works (Updater::BUDGET) plus one slow migration, short enough for the
     * page that asks again to go on soon.
     */
    public const SECONDS = 120;

    /** The note next to the cache lock (a cache entry): how its holder was locked, so a later request can tell that it is gone. */
    public const NOTE = self::NAME.'.held';

    /** Tests: the lock file instead of storage/framework/avd-finish-update.lock (null: that one). */
    public static ?string $file = null;

    /** @var resource|null the open lock file while this request holds it */
    private mixed $handle;

    private ?string $session;

    private ?string $sessionConnection;

    private ?Lock $cache;

    private bool $released = false;

    /** @param resource|null $handle */
    private function __construct(mixed $handle, ?string $session, ?string $sessionConnection, ?Lock $cache, private readonly bool $tookOver)
    {
        $this->handle = $handle;
        $this->session = $session;
        $this->sessionConnection = $sessionConnection;
        $this->cache = $cache;
    }

    /**
     * The lock, or null while another request holds it (or where nothing can be locked at all: no file lock, no named
     * lock and a cache store that cannot lock).
     */
    public static function acquire(): ?self
    {
        $handle = self::fileLock();

        if ($handle === false) {
            return null;
        }

        [$session, $connection] = self::sessionLock();

        if ($session === false) {
            self::closeFile($handle);

            return null;
        }

        $cache = null;
        $tookOver = false;

        try {
            $lock = Cache::lock(self::NAME, self::SECONDS);

            if (! $lock->get()) {
                // Held: by a request that is still working (another server sharing this cache store), or left by one
                // the host ended. Only the second may be taken over, and only where the locks above prove it is gone.
                if (! self::abandoned($handle !== null, $session !== null)) {
                    self::releaseSession($session, $connection);
                    self::closeFile($handle);

                    return null;
                }

                $lock->forceRelease();

                if (! $lock->get()) {
                    self::releaseSession($session, $connection);
                    self::closeFile($handle);

                    return null;
                }

                $tookOver = true;
            }

            $cache = $lock;
        } catch (Throwable) {
            // The cache store cannot lock (the "null" store): the file lock or the named lock alone, if this site has one.
            if ($handle === null && $session === null) {
                return null;
            }
        }

        $held = new self($handle, $session, $connection, $cache, $tookOver);
        $held->note();

        return $held;
    }

    /** Whether this request took the lock over from a request the host ended (its own locks were gone). */
    public function tookOver(): bool
    {
        return $this->tookOver;
    }

    /**
     * Which locks this request holds: the file lock, the database's named lock, the cache lock.
     *
     * @return array{file: bool, session: bool, cache: bool}
     */
    public function holds(): array
    {
        return ['file' => $this->handle !== null, 'session' => $this->session !== null, 'cache' => $this->cache !== null];
    }

    /** Release every part (safe to call twice): the note and the cache lock first, the two the system releases last. */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if ($this->cache !== null) {
            try {
                $note = Cache::get(self::NOTE);

                if (is_array($note) && ($note['owner'] ?? null) === $this->cache->owner()) {
                    Cache::forget(self::NOTE);
                }
            } catch (Throwable) {
            }

            try {
                $this->cache->release();
            } catch (Throwable) {
            }

            $this->cache = null;
        }

        self::releaseSession($this->session, $this->sessionConnection);
        $this->session = null;
        self::closeFile($this->handle);
        $this->handle = null;
    }

    /** The note next to the cache lock (as long as the lock holds): which of the locks that end with the request its holder has. */
    private function note(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            Cache::put(self::NOTE, [
                'owner' => $this->cache->owner(),
                'file' => $this->handle !== null ? self::where() : null,
                'session' => $this->session !== null,
                'at' => time(),
            ], self::SECONDS);
        } catch (Throwable) {
        }
    }

    /**
     * Is the cache lock left by a request that is gone? Yes when this request holds the named lock and the holder had
     * it too (the database released it: its connection closed), or when this request holds the lock file and the holder
     * had that very file on this server (the operating system released it: its process ended or closed the file).
     */
    private static function abandoned(bool $file, bool $session): bool
    {
        try {
            $note = Cache::get(self::NOTE);
        } catch (Throwable) {
            return false;
        }

        if (! is_array($note)) {
            return false;
        }

        if ($session && ($note['session'] ?? false) === true) {
            return true;
        }

        return $file && is_string($note['file'] ?? null) && hash_equals(self::where(), $note['file']);
    }

    /** This server and this lock file: a file lock says nothing about a request on another server. */
    private static function where(): string
    {
        $host = gethostname();
        $path = self::path();
        $real = realpath($path);

        return sha1((is_string($host) && $host !== '' ? $host : php_uname('n')).'|'.str_replace('\\', '/', $real !== false ? $real : $path));
    }

    private static function path(): string
    {
        return self::$file ?? storage_path('framework/'.self::NAME.'.lock');
    }

    /**
     * The lock file, locked: the open file; false while another request holds it; null where this disk cannot lock files
     * (or the file cannot be made).
     *
     * @return resource|false|null
     */
    private static function fileLock(): mixed
    {
        $handle = @fopen(self::path(), 'c');

        if ($handle === false) {
            return null;
        }

        $wouldBlock = 0;

        if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return $handle;
        }

        fclose($handle);

        return $wouldBlock ? false : null;
    }

    /** @param resource|null $handle */
    private static function closeFile(mixed $handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        try {
            flock($handle, LOCK_UN);
        } catch (Throwable) {
        }

        @fclose($handle);
    }

    /**
     * The named lock of this site's database (MySQL, MariaDB), taken on the connection the migrations run on (its write
     * connection: a read replica is another server): [its name, the connection] when this request holds it; [false, null]
     * while another session holds it; [null, null] where the database has none (SQLite, a Galera cluster refuses
     * GET_LOCK, a database that does not answer).
     *
     * @return array{0: string|false|null, 1: string|null}
     */
    private static function sessionLock(): array
    {
        try {
            $connection = DB::connection();

            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return [null, null];
            }

            $name = self::NAME.':'.substr(sha1($connection->getDatabaseName().'|'.$connection->getTablePrefix()), 0, 24);
            $row = $connection->selectOne('SELECT GET_LOCK(?, 0) AS got', [$name], false);
            $got = is_object($row) ? ($row->got ?? null) : null;

            return match ((string) $got) {
                '1' => [$name, $connection->getName()],
                '0' => [false, null],
                default => [null, null],
            };
        } catch (Throwable) {
            return [null, null];
        }
    }

    private static function releaseSession(string|false|null $name, ?string $connection): void
    {
        if (! is_string($name)) {
            return;
        }

        try {
            DB::connection($connection)->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);
        } catch (Throwable) {
            // The connection is gone (and its named lock with it).
        }
    }
}
