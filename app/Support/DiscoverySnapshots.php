<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Exact answers that take too long to work out while a page waits on a big site: Home's discovery rails (the tags with
 * the most videos, the creators posting now, the trending actors), its shorts shelf and the Ctrl+K search chips. Up to
 * 1.2.2 a page with a cold cache worked them out over every video of their window (8-37 s of database time at 300,000
 * videos, look-around DB-X2); an earlier 1.2.3 build bounded them by counting only the newest videos and printed those
 * counts (the check's DB-V1-2). Now they show the same tags, creators, actors, videos, chips and counts as 1.2.2, and no
 * page waits for them on a big site:
 *  - where the work is small (a rail whose window holds at most INLINE_ROWS videos or view rows; a shelf or a chip check
 *    that settles its answer among the videos it reads first), the page does it and keeps the answer for the rail's own
 *    time, as before;
 *  - else the scheduler's task discovery:snapshots (App\Console\Commands\BuildDiscoverySnapshots: every minute while an
 *    answer is wanted, one run at a time, in the background) works it out and keeps it as a snapshot. A page shows the
 *    snapshot while it is no older than the rail's own time (the freshness these rails always had), the last one while
 *    a newer one is worked out (at most KEEP_SECONDS old), and before the first one only what is sure: no rail (Home
 *    shows its categories in that place), the short videos and the chips already found (never an empty chip, never a
 *    wrong count). The task works an answer out again before its snapshot stops being current, as long as pages asked
 *    for it in the last WANTED_SECONDS;
 *  - a site where no scheduler ran lately (no cron, and the site cannot start one itself) or whose task is paused in
 *    Tools > Scheduled tasks works the answer out in the page, as before 1.2.3.
 * Pages write only their own keys (":page", ":quick" and the list of wanted answers) and the task only the snapshots, so
 * a cron that runs as another system user never leaves a page a cache file it cannot replace.
 */
class DiscoverySnapshots
{
    /** The answers the task works out: name => [class, method]; the method takes the answer's parameters. */
    public const COMPUTATIONS = [
        'tags' => [\App\Services\SmartDiscoveryRailService::class, 'exactTags'],
        'creators' => [\App\Services\AdaptiveCreatorSpotlightService::class, 'exactCreators'],
        'actors' => [\App\Services\AdaptiveTrendingCollectionsService::class, 'exactCollections'],
        'shorts' => [\App\Services\PublicVideoFeedService::class, 'exactShorts'],
        'chips' => [SpotlightChips::class, 'exactChips'],
    ];

    /** The scheduled task, as Tools > Scheduled tasks names it (App\Console\Kernel). */
    public const TASK = 'discovery:snapshots --seconds=50';

    /** A rail whose window holds at most this many videos (or view rows) is worked out by the page itself. */
    public const INLINE_ROWS = 2000;

    /** The longest a snapshot is shown while a newer one is worked out (seconds). */
    public const KEEP_SECONDS = 21600;

    /** An answer is kept up to date while pages asked for it within this time (seconds; at least twice its own time). */
    public const WANTED_SECONDS = 3600;

    /** A page notes again that it wants an answer at most this often (seconds). */
    private const WANT_EVERY = 300;

    /** The task works an answer out again this long before it stops being current (seconds; at most half its time). */
    private const AHEAD_SECONDS = 90;

    /** A page keeps an answer it could not settle quickly this long (seconds). */
    private const QUICK_SECONDS = 60;

    /** An answer the task could not work out is tried again after this long (seconds). */
    public const RETRY_SECONDS = 600;

    private const PREFIX = 'discovery-snapshots:v1:';

    private const WANTED_KEY = self::PREFIX.'wanted';

    /** Tests: whether a scheduler runs the task (null: the site's own records, schedulerRuns()). */
    public static ?bool $schedulerRuns = null;

    /** Tests: the most rows a page counts itself (null: INLINE_ROWS, inlineRows()). */
    public static ?int $inlineRows = null;

    /**
     * The answer $name for $params (they hold the 'locale' it is for), current for $ttl seconds.
     *
     * @param  array<string, scalar>  $params
     * @param  Closure(): array{0: mixed, 1: bool}  $quick  what the page can work out in a bounded time: [the answer or
     *                                                      what is sure of it, whether that is the exact answer]
     */
    public static function answer(string $name, array $params, int $ttl, Closure $quick): mixed
    {
        $ttl = max(1, $ttl);
        $key = self::key($name, $params);

        // what this page's site worked out or took from a current snapshot, kept until it stops being current
        $kept = self::read($key.':page');

        if (is_array($kept) && array_key_exists('value', $kept)) {
            return $kept['value'];
        }

        $snapshot = self::snapshot($key);
        $age = $snapshot === null ? null : max(0, self::now() - $snapshot['at']);

        if ($snapshot !== null && $age <= $ttl) {
            self::want($name, $params, $ttl);
            self::write($key.':page', ['value' => $snapshot['value']], max(1, $ttl - $age));

            return $snapshot['value'];
        }

        $quickly = self::read($key.':quick');

        if (! is_array($quickly) || ! array_key_exists('value', $quickly)) {
            [$value, $settled] = $quick();

            if ($settled) {
                self::write($key.':page', ['value' => $value], $ttl);

                return $value;
            }

            $quickly = ['value' => $value];
            self::write($key.':quick', $quickly, self::QUICK_SECONDS);
        }

        if (! self::schedulerRuns()) {
            // nothing would work it out in the background: the page does, as before 1.2.3
            $value = self::compute($name, $params);
            self::write($key.':page', ['value' => $value], $ttl);

            return $value;
        }

        self::want($name, $params, $ttl);

        return $snapshot !== null && $age <= self::KEEP_SECONDS ? $snapshot['value'] : $quickly['value'];
    }

    /** Whether the task has work: an answer pages asked for lately whose snapshot is missing or about to go out of date. */
    public static function due(): bool
    {
        return self::dueAnswers() !== [];
    }

    /**
     * The answers to work out, the ones without a snapshot first, then the oldest snapshot first.
     *
     * @return list<array{key: string, name: string, params: array<string, scalar>, ttl: int, at: int, age: ?int}>
     */
    public static function dueAnswers(): array
    {
        $now = self::now();
        $due = [];

        foreach (self::wanted() as $key => $entry) {
            if (! isset(self::COMPUTATIONS[$entry['name']]) || $entry['at'] < $now - max(self::WANTED_SECONDS, 2 * $entry['ttl'])) {
                continue;
            }

            $snapshot = self::snapshot($key);
            $age = $snapshot === null ? null : max(0, $now - $snapshot['at']);

            if (($age === null || $age >= $entry['ttl'] - min(self::AHEAD_SECONDS, intdiv($entry['ttl'], 2))) && self::read($key.':failed') === null) {
                $due[] = ['key' => $key] + $entry + ['age' => $age];
            }
        }

        usort($due, fn (array $a, array $b): int => ($b['age'] ?? PHP_INT_MAX) <=> ($a['age'] ?? PHP_INT_MAX));

        return $due;
    }

    /**
     * Works out one answer of dueAnswers() and keeps it as its snapshot (dated when the work started); its seconds.
     *
     * @param  array{key: string, name: string, params: array<string, scalar>}  $answer
     */
    public static function refresh(array $answer): float
    {
        $started = microtime(true);
        $at = self::now();
        $value = self::compute($answer['name'], $answer['params']);

        Cache::put($answer['key'].':snapshot', ['at' => $at, 'value' => $value], self::KEEP_SECONDS + 3600);

        return round(microtime(true) - $started, 3);
    }

    /**
     * The task could not work out $answer (an error it reported): tried again after RETRY_SECONDS, not every minute.
     *
     * @param  array{key: string}  $answer
     */
    public static function failed(array $answer): void
    {
        try {
            Cache::put($answer['key'].':failed', self::now(), self::RETRY_SECONDS);
        } catch (Throwable) {
            // then the next run tries again
        }
    }

    /**
     * The exact answer $name for $params, worked out now in the answer's language.
     *
     * @param  array<string, scalar>  $params
     */
    public static function compute(string $name, array $params): mixed
    {
        [$class, $method] = self::COMPUTATIONS[$name];
        $before = app()->getLocale();
        $locale = (string) ($params['locale'] ?? $before);

        try {
            if ($locale !== '' && $locale !== $before) {
                app()->setLocale($locale);
            }

            return app($class)->{$method}($params);
        } finally {
            if (app()->getLocale() !== $before) {
                app()->setLocale($before);
            }
        }
    }

    /**
     * Forgets every kept answer and snapshot of $name for the parameter sets $params (a video left the site: the shorts
     * shelf stops linking to it at once); the next page works out what is sure and the task the rest.
     *
     * @param  list<array<string, scalar>>  $params
     */
    public static function forget(string $name, array $params): void
    {
        foreach ($params as $set) {
            $key = self::key($name, $set);

            foreach ([':page', ':quick', ':snapshot'] as $part) {
                try {
                    Cache::forget($key.$part);
                } catch (Throwable) {
                    // the next page works it out again
                }
            }
        }
    }

    /** The most videos (or view rows) of a rail's window the page counts itself (INLINE_ROWS). */
    public static function inlineRows(): int
    {
        return max(0, self::$inlineRows ?? self::INLINE_ROWS);
    }

    /**
     * Whether a scheduler starts the task: the system cron or the site itself ran the scheduler lately
     * (LaravelSchedulerCron) and the task is not paused (Tools > Scheduled tasks). The test suite answers no unless a
     * test says otherwise ($schedulerRuns): its scheduler stamps come and go with the scheduler's own tests.
     */
    public static function schedulerRuns(): bool
    {
        if (self::$schedulerRuns !== null) {
            return self::$schedulerRuns;
        }

        if (app()->runningUnitTests()) {
            return false;
        }

        try {
            return LaravelSchedulerCron::runStatus()['source'] !== 'none' && ! CronControl::isPaused(self::TASK);
        } catch (Throwable) {
            return false;
        }
    }

    /** The application's clock (Unix time): the cache's own. */
    private static function now(): int
    {
        return now()->getTimestamp();
    }

    /** @param  array<string, scalar>  $params */
    private static function key(string $name, array $params): string
    {
        ksort($params);

        return self::PREFIX.$name.':'.md5((string) json_encode($params));
    }

    /** @param  array<string, scalar>  $params */
    private static function want(string $name, array $params, int $ttl): void
    {
        $key = self::key($name, $params);
        $now = self::now();
        $wanted = self::wanted();

        if (isset($wanted[$key]) && $wanted[$key]['at'] >= $now - self::WANT_EVERY && $wanted[$key]['ttl'] === $ttl) {
            return;
        }

        // answers nobody asked for lately leave the list
        $wanted = array_filter($wanted, fn (array $entry): bool => $entry['at'] >= $now - max(self::WANTED_SECONDS, 2 * $entry['ttl']));
        ksort($params);
        $wanted[$key] = ['name' => $name, 'params' => $params, 'ttl' => $ttl, 'at' => $now];

        self::write(self::WANTED_KEY, $wanted, 86400);
    }

    /** @return array<string, array{name: string, params: array<string, scalar>, ttl: int, at: int}> */
    private static function wanted(): array
    {
        $wanted = self::read(self::WANTED_KEY);
        $list = [];

        foreach (is_array($wanted) ? $wanted : [] as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_string($entry['name'] ?? null) && is_array($entry['params'] ?? null)) {
                $list[$key] = [
                    'name' => $entry['name'],
                    'params' => $entry['params'],
                    'ttl' => max(1, (int) ($entry['ttl'] ?? 600)),
                    'at' => (int) ($entry['at'] ?? 0),
                ];
            }
        }

        return $list;
    }

    /** @return array{at: int, value: mixed}|null */
    private static function snapshot(string $key): ?array
    {
        $snapshot = self::read($key.':snapshot');

        return is_array($snapshot) && isset($snapshot['at']) && array_key_exists('value', $snapshot)
            ? ['at' => (int) $snapshot['at'], 'value' => $snapshot['value']]
            : null;
    }

    private static function read(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private static function write(string $key, mixed $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, max(1, $seconds));
        } catch (Throwable) {
            // a cache that refuses it: the page works it out again next time
        }
    }
}
