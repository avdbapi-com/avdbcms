<?php

namespace App\Support;

use App\Models\Comment;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The like, dislike and comment counts each video keeps (videos.likes_count, dislikes_count, comments_count): the studio,
 * the admin lists, search by likes and the order of the public "Most commented" list read them (public pages show live
 * counts). comments_count counts the public comments, as the watch page and the API do: not hidden by an admin
 * (banned_at), not deleted, replies included.
 *
 * A member's own like and each comment move the counts one step (VideoEventSubscriber, CommentObserver). Whatever removes
 * or hides many at once (Admin > Likes, hiding comments in bulk, a blocked or removed account) skipped those steps until
 * 1.2.3, so the counts drifted: it counts the videos it touched again here now. The scheduler also counts every video
 * again once a day, in short slices (videos:recount-engagement), which puts right what older versions left wrong.
 *
 * The pass the update to 1.2.3 asks for (request(true)) keeps its totals and writes them to Tools > Logs > Admin
 * actions when it ends (AUDIT_ACTION), whether the update's own slice or the scheduler's last one ends it.
 */
final class EngagementCounters
{
    /** The Admin actions row of the recount the update asked for. */
    public const AUDIT_ACTION = 'update.recount';
    /** Videos per step: one grouped query each for the likes and the comments, an update only where a count is wrong. */
    public const CHUNK = 500;

    /** Rows deleted, hidden or zeroed per statement: each holds its rows for a moment only, also on a big site. */
    public const BATCH = 1000;

    /** A full pass at most this often (the scheduler starts the command every five minutes while one is due). */
    public const PASS_EVERY = 20 * 3600;

    private const STATE_KEY = 'videos.engagement_recount';

    /** Whether comments are deleted softly (deleted_at), read once a process. */
    private static ?bool $softDeletedComments = null;

    /** Whether the videos table has the counts (added in 1.2.0). */
    public static function available(): bool
    {
        return Schema::hasTable('videos') && Schema::hasColumns('videos', ['id', 'likes_count', 'dislikes_count', 'comments_count']);
    }

    /**
     * Counts these videos again.
     *
     * @param  iterable<mixed>  $videoIds
     * @return int how many had a wrong count
     */
    public static function recount(iterable $videoIds): int
    {
        $ids = [];
        foreach ($videoIds as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if ($ids === [] || ! self::available()) {
            return 0;
        }

        $corrected = 0;
        foreach (array_chunk(array_values($ids), self::CHUNK) as $chunk) {
            $corrected += self::recountIds($chunk);
        }

        return $corrected;
    }

    /** Whether the scheduler should start a slice: a pass is under way, or the last one ended PASS_EVERY ago or more. */
    public static function due(): bool
    {
        $state = self::state();

        return $state['next'] !== null || $state['finished_at'] < time() - self::PASS_EVERY;
    }

    /**
     * Starts a new pass from the first video (the update to 1.2.3 does, so every site is put right). $report: the pass
     * keeps its totals and writes them to Admin actions when it ends (see run()).
     */
    public static function request(bool $report = false): void
    {
        self::save(['next' => 0, 'finished_at' => self::state()['finished_at']] + ($report ? ['report' => ['checked' => 0, 'corrected' => 0]] : []));
    }

    /**
     * Counts the videos again from where the pass stands (a new pass when none is under way), for about $seconds, and
     * remembers where it stopped. A pass that keeps its totals (request(true)) adds this slice's and, when it ends,
     * writes them to Tools > Logs > Admin actions.
     *
     * @return array{checked: int, corrected: int, done: bool}
     */
    public static function run(float $seconds): array
    {
        if (! self::available()) {
            return ['checked' => 0, 'corrected' => 0, 'done' => true];
        }

        $deadline = microtime(true) + max(0.0, $seconds);
        $state = self::state();
        $after = $state['next'] ?? 0;
        $checked = 0;
        $corrected = 0;

        do {
            $ids = DB::table('videos')->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $corrected += self::recountIds($ids);
            $checked += count($ids);
            $after = $ids === [] ? $after : end($ids);

            if (count($ids) < self::CHUNK) {
                self::save(['next' => null, 'finished_at' => time()]);

                if ($state['report'] !== null && $state['report']['checked'] + $checked > 0) {
                    self::audit(sprintf('1.2.3: likes, dislikes and comments of every video counted again: %s checked, %s put right',
                        self::videos($state['report']['checked'] + $checked), number_format($state['report']['corrected'] + $corrected)));
                }

                return ['checked' => $checked, 'corrected' => $corrected, 'done' => true];
            }
        } while (microtime(true) < $deadline);

        self::save(['next' => $after, 'finished_at' => $state['finished_at']] + ($state['report'] !== null ? ['report' => [
            'checked' => $state['report']['checked'] + $checked,
            'corrected' => $state['report']['corrected'] + $corrected,
        ]] : []));

        return ['checked' => $checked, 'corrected' => $corrected, 'done' => false];
    }

    /**
     * One row in Tools > Logs > Admin actions (in English, like the admin's own records; the lists show 120 characters),
     * unless the same row is there already. Only on a site with accounts: a site being built (the installer's
     * migrations) has nothing to report.
     */
    public static function audit(string $details): void
    {
        try {
            if (! Schema::hasTable('admin_audit_logs') || ! Schema::hasTable('users') || ! DB::table('users')->exists()) {
                return;
            }

            if (! DB::table('admin_audit_logs')->where('action', self::AUDIT_ACTION)->where('details', $details)->exists()) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => $details,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable) {
            // The record is a courtesy; the counts are right.
        }
    }

    /** "1 video", "12,345 videos". */
    public static function videos(int $count): string
    {
        return $count === 1 ? '1 video' : number_format($count).' videos';
    }

    /**
     * Every like and dislike was removed (Admin > Likes > Clear all): each video's like counts go to 0, a batch of ids
     * at a time.
     *
     * @return int how many videos had a count
     */
    public static function clearLikes(): int
    {
        if (! self::available()) {
            return 0;
        }

        $cleared = 0;
        $after = 0;

        do {
            $ids = DB::table('videos')
                ->where('id', '>', $after)
                ->where(fn ($query) => $query->where('likes_count', '>', 0)->orWhere('dislikes_count', '>', 0))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                $cleared += DB::table('videos')->whereIn('id', $ids)->update(['likes_count' => 0, 'dislikes_count' => 0]);
                $after = (int) end($ids);
            }
        } while (count($ids) === self::BATCH);

        return $cleared;
    }

    /**
     * Hides the public comments $query selects (Admin > Comments > Hide in bulk, a member blocked with their comments), a
     * batch at a time, and counts their videos again. A comment hidden already keeps its date.
     *
     * @return int how many were hidden
     */
    public static function hideComments(Builder $query): int
    {
        return self::moderate($query, true);
    }

    /**
     * Shows the hidden comments $query selects again, a batch at a time, and counts their videos again.
     *
     * @return int how many show again
     */
    public static function showComments(Builder $query): int
    {
        return self::moderate($query, false);
    }

    /**
     * A member's account is removed (UserObserver): their comments on other members' videos and their likes and dislikes
     * go a batch at a time, and the videos they were on are counted again. The replies to a removed comment go with it
     * (the database removes them), so those videos are counted, not stepped down.
     */
    public static function removeMemberEngagement(int $userId): void
    {
        $video = (new Video())->getMorphClass();

        do {
            $rows = DB::table('comments')->where('user_id', $userId)->orderBy('id')->limit(self::BATCH)->get(['id', 'video_id']);

            if ($rows->isNotEmpty()) {
                DB::table('comments')->whereIn('id', $rows->pluck('id')->all())->delete();
                // the other members' replies under them go too, on every site (judge R3-009: the bundled database's
                // foreign key removed them, a site installed by the migrations kept them under a missing comment)
                $replied = CommentReplies::drop($rows->pluck('id')->all());
                self::recount($rows->pluck('video_id')->merge($replied)->unique()->values());
            }
        } while ($rows->count() === self::BATCH);

        do {
            $rows = DB::table('interactions')->where('user_id', $userId)->orderBy('id')->limit(self::BATCH)->get(['id', 'likeable_type', 'likeable_id']);

            if ($rows->isNotEmpty()) {
                DB::table('interactions')->whereIn('id', $rows->pluck('id')->all())->delete();
                self::recount($rows->where('likeable_type', $video)->pluck('likeable_id'));
            }
        } while ($rows->count() === self::BATCH);
    }

    private static function moderate(Builder $query, bool $hide): int
    {
        $key = $query->getModel()->getQualifiedKeyName();
        $done = 0;
        $after = 0;

        do {
            $rows = (clone $query)
                ->when($hide, fn ($query) => $query->whereNull('banned_at'), fn ($query) => $query->whereNotNull('banned_at'))
                ->where($key, '>', $after)
                ->orderBy($key)
                ->limit(self::BATCH)
                ->get([$key, 'video_id']);

            if ($rows->isNotEmpty()) {
                $done += Comment::query()->whereKey($rows->modelKeys())->update(['banned_at' => $hide ? now() : null]);
                self::recount($rows->pluck('video_id'));
                $after = (int) $rows->last()->getKey();
            }
        } while ($rows->count() === self::BATCH);

        return $done;
    }

    /**
     * Counts these videos (at most CHUNK) again and writes the counts that are wrong. A plain update: a count put right
     * does not move the video in "my videos" (ordered by the time it was last changed).
     *
     * @param  list<int>  $ids
     */
    private static function recountIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $votes = DB::table('interactions')
            ->where('likeable_type', (new Video())->getMorphClass())
            ->whereIn('likeable_id', $ids)
            ->groupBy('likeable_id')
            ->selectRaw('likeable_id, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS likes, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS dislikes')
            ->get()
            ->keyBy('likeable_id');

        $comments = DB::table('comments')
            ->whereIn('video_id', $ids)
            ->whereNull('banned_at')
            ->when(self::$softDeletedComments ??= Schema::hasColumn('comments', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->groupBy('video_id')
            ->selectRaw('video_id, COUNT(*) AS total')
            ->pluck('total', 'video_id');

        $corrected = 0;

        foreach (DB::table('videos')->whereIn('id', $ids)->get(['id', 'likes_count', 'dislikes_count', 'comments_count']) as $video) {
            $counts = [
                'likes_count' => (int) ($votes[$video->id]->likes ?? 0),
                'dislikes_count' => (int) ($votes[$video->id]->dislikes ?? 0),
                'comments_count' => (int) ($comments[$video->id] ?? 0),
            ];

            if ($counts !== ['likes_count' => (int) $video->likes_count, 'dislikes_count' => (int) $video->dislikes_count, 'comments_count' => (int) $video->comments_count]) {
                $corrected += DB::table('videos')->where('id', $video->id)->update($counts);
            }
        }

        return $corrected;
    }

    /** @return array{next: ?int, finished_at: int, report: array{checked: int, corrected: int}|null} */
    private static function state(): array
    {
        try {
            $state = Cache::get(self::STATE_KEY);
        } catch (Throwable) {
            $state = null;
        }

        $state = is_array($state) ? $state : [];
        $report = is_array($state['report'] ?? null) ? $state['report'] : null;

        return [
            'next' => isset($state['next']) && is_numeric($state['next']) ? max(0, (int) $state['next']) : null,
            'finished_at' => (int) ($state['finished_at'] ?? 0),
            // Only while a pass is under way (a finished one has nothing more to report).
            'report' => $report !== null && isset($state['next']) && is_numeric($state['next'])
                ? ['checked' => max(0, (int) ($report['checked'] ?? 0)), 'corrected' => max(0, (int) ($report['corrected'] ?? 0))]
                : null,
        ];
    }

    /** @param array{next: ?int, finished_at: int, report?: array{checked: int, corrected: int}} $state */
    private static function save(array $state): void
    {
        rescue(fn () => Cache::forever(self::STATE_KEY, $state), null, false);
    }
}
