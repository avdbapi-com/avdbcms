<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One check at a time for a guess that is counted: a password typed to confirm a change (RequirePassword) or a text
 * message code (PhoneVerification, MustVerifyPhone). Such a check reads a count of wrong tries, compares the guess and
 * writes the new count. Requests sent side by side would all read the same count before any of them wrote it, so a
 * script could make many guesses for one counted try (round 1: R1-085, R1-164). Under a cache lock of the account's
 * own key, the requests of that account run their checks one after the other and each one sees the count the one
 * before it wrote; other accounts never wait.
 *
 * The lock lives in the site's cache store (files, database, Redis, Memcached, DynamoDB and the array store of the
 * tests all have locks). A store without locks runs the check as before. The lock ends by itself after LOCK_SECONDS
 * when PHP stops in the middle; a request that cannot get it within WAIT_SECONDS (somebody sends a flood of guesses)
 * gets the answer of $busy, never the check.
 */
final class SerialAttempts
{
    /** How long a lock is kept at most (a request that died while holding it frees it after this). */
    public const LOCK_SECONDS = 10;

    /** How long a request waits for its turn before it gets the busy answer. */
    public const WAIT_SECONDS = 5;

    /** WAIT_SECONDS; tests shorten it. */
    public static int $waitSeconds = self::WAIT_SECONDS;

    /**
     * @template T
     * @param  Closure(): T  $check  the counted check (read the count, compare, write the count)
     * @param  Closure(): T  $busy   the answer when the turn did not come within WAIT_SECONDS
     * @return T
     */
    public static function run(string $key, Closure $check, Closure $busy): mixed
    {
        try {
            $store = Cache::store()->getStore();
        } catch (Throwable) {
            $store = null;
        }

        if (! $store instanceof LockProvider) {
            return $check();
        }

        try {
            $lock = $store->lock('serial-attempts:'.$key, self::LOCK_SECONDS);
            $lock->block(max(1, self::$waitSeconds));
        } catch (LockTimeoutException) {
            return $busy();
        } catch (Throwable) {
            // A store that cannot keep the lock right now (a full disk, a lost connection): the check as before.
            return $check();
        }

        try {
            return $check();
        } finally {
            $lock->release();
        }
    }
}
