<?php

namespace App\Support;

use App\Jobs\RetryVideoSearchIndex;
use App\Models\Video;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The optional external search index of the videos (Meilisearch, Algolia: Settings > Search) and the changes it must hear
 * of (1.2.3 round 3, KS-F2).
 *
 * Scout follows a video that is saved or deleted as a model; the plain updates of many videos at once (the admin's bulk
 * bar: Ban, Unban, the visibility and the category; Members > Block with the member's videos; the update's
 * content-safety pass) never reach it, so its quick search kept offering banned videos. follow() tells it of those.
 *
 * A search server that does not answer never stops the change on the site: guard() (around every sync of Scout, through
 * Video::queueMakeSearchable() and queueRemoveFromSearch()) notes it, logs it once per change and queues a retry
 * (RetryVideoSearchIndex, a few tries over a few hours, then Background jobs > Failed with its Retry), and the admin's
 * answer says so (missed()). On a site whose background jobs run inline (QUEUE_CONNECTION=sync, not the installer's
 * choice) nothing is queued: those videos are corrected by their next save or a rebuild of the index.
 * The import of the whole index (scout:import: the nightly run, the admin's Cron page) is not guarded (unguarded()):
 * it fails as before when the server does not answer, instead of saying every video was imported.
 * The built-in search (collection, database, null) reads the database and is never asked.
 */
final class VideoSearchIndex
{
    /** Videos per read of the database and per call of the search server. */
    private const CHUNK = 500;

    /** Whether a sync since watch() missed the search server. */
    private static bool $missed = false;

    /** Whether the syncs run now must fail as Scout's do (the import of the whole index). */
    private static bool $unguarded = false;

    /** Whether an external search index is in use (the built-in engines read the database: nothing to tell). */
    public static function external(): bool
    {
        return ! in_array((string) config('scout.driver', 'collection'), ['', 'collection', 'database', 'null'], true);
    }

    /** Starts noting whether the search server misses a change (the admin's answer reads missed() afterwards). */
    public static function watch(): void
    {
        self::$missed = false;
    }

    /** Whether the search server did not answer since watch(): the change is saved, and a retry is queued. */
    public static function missed(): bool
    {
        return self::$missed;
    }

    /**
     * The admin's answer to a change of videos, with a sentence when the search server did not answer.
     */
    public static function answer(string $message): string
    {
        return self::$missed
            ? $message.' '.__('The search server did not answer: the change is saved, and the search is updated again in the background.')
            : $message;
    }

    /**
     * Brings the external index in line with these videos after a plain update: the ones visitors may find are written,
     * the others (banned, private, deleted ...) removed. Never throws (guard()).
     *
     * @param  iterable<int|string>  $ids
     */
    public static function follow(iterable $ids): void
    {
        if (! self::external()) {
            return;
        }

        foreach (array_chunk(self::ids($ids), self::CHUNK) as $chunk) {
            [$found, $gone] = self::split($chunk);
            $found->searchable();
            $gone->unsearchable();
        }
    }

    /**
     * Tells the index of these videos now, or throws when the search server does not answer (RetryVideoSearchIndex: the
     * queue tries again).
     *
     * @param  iterable<int|string>  $ids
     */
    public static function sync(iterable $ids): void
    {
        if (! self::external()) {
            return;
        }

        foreach (array_chunk(self::ids($ids), self::CHUNK) as $chunk) {
            [$found, $gone] = self::split($chunk);
            $found->searchableSync();
            $gone->unsearchableSync();
        }
    }

    /**
     * Runs one sync of Scout (the Video model hands every one of them here): when the external search server does not
     * answer, the change on the site stands, the failure is logged, a retry of these videos is queued and missed() says
     * so. Any other engine's error is not this class's to keep.
     *
     * @param  iterable<Video>  $models
     */
    public static function guard(callable $sync, iterable $models): void
    {
        if (self::$unguarded) {
            $sync();

            return;
        }

        try {
            $sync();
        } catch (Throwable $e) {
            if (! self::external()) {
                throw $e;
            }

            self::$missed = true;
            report($e);
            self::later($models);
        }
    }

    /**
     * Runs $callback with Scout's own failures: a search server that does not answer stops it (Video::makeAllSearchable(),
     * the import of the whole index; one retry per chunk of a whole site would only ask the same server again).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function unguarded(callable $callback): mixed
    {
        $before = self::$unguarded;
        self::$unguarded = true;

        try {
            return $callback();
        } finally {
            self::$unguarded = $before;
        }
    }

    /** @param iterable<Video> $models */
    private static function later(iterable $models): void
    {
        $ids = [];

        foreach ($models as $model) {
            if ($model instanceof Video && $model->getKey() !== null) {
                $ids[] = (int) $model->getKey();
            }
        }

        // inline background jobs would only ask the same server again at once (and wait for it twice)
        if ($ids === [] || in_array((string) config('queue.default'), ['sync', 'null'], true)) {
            return;
        }

        try {
            RetryVideoSearchIndex::dispatch(array_values(array_unique($ids)))->delay(now()->addMinute());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The videos visitors may find are the ones the import of the whole index writes (Video::active(): public or due,
     * of a member who is still active; Video::makeAllSearchableUsing()); every other id is removed by its number alone:
     * banned, private, in the trash, of a member who is gone, or deleted for good since (a delete while the search
     * server did not answer leaves only the number to its retry).
     *
     * @param  list<int>  $ids
     * @return array{0: Collection<int, Video>, 1: Collection<int, Video>} the ones visitors may find, the others
     */
    private static function split(array $ids): array
    {
        $found = Video::query()->withoutGlobalScope('currentTranslation')->active()->whereIn('videos.id', $ids)->get();
        $foundIds = array_map('intval', $found->modelKeys());
        $gone = (new Video)->newCollection(array_map(
            function (int $id): Video {
                $video = new Video;
                $video->forceFill([$video->getScoutKeyName() => $id]);

                return $video;
            },
            array_values(array_diff($ids, $foundIds)),
        ));

        return [$found->values(), $gone];
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return list<int>
     */
    private static function ids(iterable $ids): array
    {
        $list = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $list[(int) $id] = (int) $id;
            }
        }

        return array_values($list);
    }
}
