<?php

namespace App\Support;

/**
 * How long the work of this request may go on. The one-click update's Finish and "Finish update" on Home set a deadline
 * before the database step, and every part of that step that can take long reads it: the migrations run one at a time
 * until it comes (App\Support\PendingMigrations), the recount of likes and comments and the hidden storage names stop
 * in time (App\Support\EngagementCounters, App\Support\StorageHider), and so do the corrected texts. The next request
 * goes on where this one stopped.
 *
 * Why: a host ends a request that runs too long. PHP's own max_execution_time can be raised with set_time_limit(), but
 * not where a host disabled that function; a web server, a proxy or PHP-FPM may have a limit of their own that PHP
 * cannot even see (FcgidIOTimeout, request_terminate_timeout, a CDN's 100 seconds). A request that was ended half-way
 * leaves its work unfinished and its locks taken; one that stops by itself leaves nothing half done.
 *
 * Without a deadline (the console: php artisan migrate, the scheduler; a request that set none) left() is INF, and each
 * part keeps the budget of its own.
 */
final class TimeBudget
{
    /** microtime(true) by which the work of this request is to be done; null: no deadline. */
    private static ?float $deadline = null;

    /** @var array{at: float, seconds: int}|null when raise() last started PHP's clock anew in this process */
    private static ?array $raised = null;

    /**
     * PHP's time limit for the rest of this request: set_time_limit($seconds) where the host allows it (it starts PHP's
     * clock anew). True when PHP took it.
     */
    public static function raise(int $seconds): bool
    {
        if (! function_exists('set_time_limit')) {
            return false;
        }

        if (! @set_time_limit($seconds)) {
            return false;
        }

        self::$raised = ['at' => microtime(true), 'seconds' => max(0, $seconds)];

        return true;
    }

    /**
     * The deadline of this request's work: at most $seconds from now, and $reserve seconds before PHP's own time limit
     * ends the request (as far as PHP tells it), but never less than $minimum seconds, so that each request gets
     * something done. Returns the seconds granted.
     */
    public static function start(float $seconds, float $reserve = 5.0, float $minimum = 1.0): float
    {
        $now = microtime(true);
        $until = $now + max(0.0, $seconds);
        $ends = self::phpLimitEnds($now);

        if ($ends !== null) {
            $until = min($until, $ends - max(0.0, $reserve));
        }

        self::$deadline = max($until, $now + max(0.0, $minimum));

        return self::$deadline - $now;
    }

    /** Sets the deadline itself (microtime(true)), or none with null. */
    public static function until(?float $deadline): void
    {
        self::$deadline = $deadline;
    }

    /** Seconds left until the deadline (less than 0 when it has passed); INF without a deadline. */
    public static function left(): float
    {
        return self::$deadline === null ? INF : self::$deadline - microtime(true);
    }

    /** Whether a deadline is set (a request of Finish is running). */
    public static function active(): bool
    {
        return self::$deadline !== null;
    }

    /**
     * $wanted seconds, or fewer: what is left until the deadline less $reserve (never below $floor). For the parts that
     * keep a budget of their own (the recount of likes and comments: 10 seconds).
     */
    public static function cap(float $wanted, float $reserve = 2.0, float $floor = 0.0): float
    {
        return max($floor, min($wanted, self::left() - $reserve));
    }

    /** No deadline any more (the end of the request's work; also what a test does between its cases). */
    public static function clear(): void
    {
        self::$deadline = null;
    }

    /**
     * When PHP's max_execution_time ends this request (microtime), or null: no limit, or none PHP can tell. After
     * raise() the clock started then; else at the start of the web request. A time that has passed already means the
     * clock runs otherwise than it seems (it was started anew elsewhere, or it counts CPU time only, as on Linux): then
     * it is not used. The console has no request start: there only raise() counts.
     */
    public static function phpLimitEnds(?float $now = null): ?float
    {
        $now ??= microtime(true);

        if (self::$raised !== null) {
            $ends = self::$raised['seconds'] > 0 ? self::$raised['at'] + self::$raised['seconds'] : null;

            return $ends !== null && $ends > $now ? $ends : null;
        }

        $limit = (int) ini_get('max_execution_time');

        if ($limit <= 0 || PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return null;
        }

        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('LARAVEL_START') ? LARAVEL_START : null);

        if (! is_numeric($start)) {
            return null;
        }

        $ends = (float) $start + $limit;

        return $ends > $now ? $ends : null;
    }
}
