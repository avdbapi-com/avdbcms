<?php

namespace App\Support;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The update to 1.2.3 on a site that has videos already: the videos whose title, tags or category use a word of the
 * hard list of App\Support\ContentSafety (words that present a child or a school-age minor) are banned, with the
 * existing ban (App\Support\VideoBans: each keeps its status for Unban) and a stored reason, REASON. So they leave
 * every public page, list, feed, sitemap, search and the API at once, and the admin reviews them in Content > Videos
 * (Banned; Home's "Needs you" links to them, ?reason=FILTER) and unbans any that were hidden by mistake. Tags and
 * categories named with such a word are hidden from visitors by ContentSafety itself (never deleted: renaming one shows
 * it again); this pass counts them. The words of the site's own list (the default list) do not change existing videos:
 * they only keep new imports out.
 *
 * In slices (the migration 2026_10_28_120000_hide_videos_with_blocked_words runs it within the time of a request of
 * Finish; the scheduler's videos:hide-blocked-words carries on on a site too big for that): first the tags (their names,
 * TAG_CHUNK at a time), then the videos in id order, CHUNK at a time, with the place reached kept in the table PASSES.
 * A slice is safe to run twice and by two processes at once: a video banned already is never banned again (its date and
 * kept status stay), its reason is written once, and the place only moves forward. A video the admin unbanned is never
 * banned again by this pass (the place has moved past it). When the pass ends, Tools > Logs > Admin actions gets one row
 * with the counts (AUDIT_ACTION), when it hid anything.
 */
final class BlockedWordsPass
{
    /** The one pass of the update to 1.2.3 (a row of PASSES). */
    public const NAME = '1.2.3';

    /** The progress of the pass: one row per pass. */
    public const PASSES = 'blocked_word_passes';

    /** The stored reason of a ban: video_id => reason, with the date of the ban it belongs to (videos.banned_at). */
    public const REASONS = 'video_ban_reasons';

    /** The reason this pass stores (English, shown translated in Content > Videos). */
    public const REASON = 'Blocked words (1.2.3)';

    /** The value of Content > Videos' ?reason= that lists the videos this pass banned. */
    public const FILTER = 'blocked-words';

    /** The Admin actions row (its label: App\Support\AuditActions). */
    public const AUDIT_ACTION = 'update.blocked_words';

    /** Home's notice, which the admin hides with its own button (App\Http\Controllers\Admin\AdminController::NOTICES). */
    public const NOTICE = 'blocked-words';

    /** The seconds the update's own slice works within a request of Finish (the scheduler goes on with the rest). */
    public const SECONDS = 10.0;

    /**
     * The seconds a visit of the admin's Home goes on with an unfinished pass, after the page is sent (a site too big
     * for the update's own slice whose cron job does not run yet: App\Http\Controllers\Admin\AdminController::index()).
     */
    public const VISIT_SECONDS = 5.0;

    /** Videos per slice: one read of the videos, one of their tags, a ban where one matches (a few dozen ms each). */
    public const CHUNK = 2000;

    /** Tags per slice of the first stage (their names only). */
    public const TAG_CHUNK = 5000;

    /** Tag ids per query of a slice (well inside the bound-parameter limits of every database). */
    private const TAG_IDS_PER_QUERY = 500;

    /** @var array{at: float, yes: bool}|null available() of this process */
    private static ?array $available = null;

    /** Whether the update made the tables (until "Finish update", a site has none: nothing to show or filter). */
    public static function available(): bool
    {
        $now = microtime(true);

        // A positive answer is kept; a negative one for a minute (the update may add the tables meanwhile).
        if (self::$available !== null && (self::$available['yes'] || $now - self::$available['at'] < 60)) {
            return self::$available['yes'];
        }

        try {
            $yes = Schema::hasTable(self::PASSES) && Schema::hasTable(self::REASONS);
        } catch (Throwable) {
            $yes = false;
        }

        self::$available = ['at' => $now, 'yes' => $yes];

        return $yes;
    }

    /** Forget what this process remembered (tests). */
    public static function forget(): void
    {
        self::$available = null;
    }

    /** Notes the pass (once: a pass that ran, or runs, keeps its place). */
    public static function start(): void
    {
        if (! self::available()) {
            return;
        }

        DB::table(self::PASSES)->insertOrIgnore([
            'name' => self::NAME,
            'stage' => 'tags',
            'next_id' => 0,
            'hard_tags' => '[]',
            'hard_categories' => '[]',
            'videos_checked' => 0,
            'videos_hidden' => 0,
            'tags_hidden' => 0,
            'categories_hidden' => 0,
            'started_at' => now(),
            'finished_at' => null,
            'updated_at' => now(),
        ]);
    }

    /** Whether the scheduler should go on with the pass (it is noted and not finished). */
    public static function due(): bool
    {
        $state = self::available() ? self::state() : null;

        return $state !== null && $state->finished_at === null;
    }

    /**
     * Goes on with the pass for about $seconds (at least one slice): the tags first, then the videos.
     *
     * @return array{checked: int, hidden: int, done: bool} videos looked at and banned by this call, and whether the
     *                                                        pass has ended
     */
    public static function run(float $seconds): array
    {
        if (! self::available()) {
            return ['checked' => 0, 'hidden' => 0, 'done' => true];
        }

        $deadline = microtime(true) + max(0.0, $seconds);
        $checked = 0;
        $hidden = 0;

        try {
            do {
                $state = self::state();

                if ($state === null || $state->finished_at !== null) {
                    break;
                }

                if ($state->stage === 'tags') {
                    self::tagsSlice($state);

                    continue;
                }

                [$looked, $banned] = self::videosSlice($state);
                $checked += $looked;
                $hidden += $banned;
            } while (microtime(true) < $deadline);
        } finally {
            if ($hidden > 0) {
                // the lists that keep a copy: the sitemaps, the admin's numbers (the public feeds keep theirs 30 seconds)
                rescue(fn () => SeoSitemapCache::clear(), null, false);
                rescue(fn () => AdminCache::bumpVersion(), null, false);
            }
        }

        $state = self::state();

        return ['checked' => $checked, 'hidden' => $hidden, 'done' => $state === null || $state->finished_at !== null];
    }

    /**
     * The videos this pass banned that are still banned for its reason (Home's notice): those the review list shows (a
     * deleted video is banned too, so it stays hidden if it is restored, but there is nothing to review).
     */
    public static function hiddenCount(): int
    {
        if (! self::available()) {
            return 0;
        }

        try {
            return (int) DB::table(self::REASONS.' as r')
                ->join('videos as v', 'v.id', '=', 'r.video_id')
                ->where('r.reason', self::REASON)
                ->where('v.status', VideoStatus::BANNED->value)
                ->whereNull('v.deleted_at')
                ->whereColumn('v.banned_at', 'r.banned_at')
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The reason of the bans of these videos that have one (Content > Videos: "Banned" and why), translated.
     *
     * @param  iterable<Video>  $videos
     * @return array<int, string> video id => reason
     */
    public static function reasons(iterable $videos): array
    {
        $ids = [];

        foreach ($videos as $video) {
            if ($video instanceof Video && $video->status === VideoStatus::BANNED) {
                $ids[] = (int) $video->getKey();
            }
        }

        if ($ids === [] || ! self::available()) {
            return [];
        }

        try {
            $rows = DB::table(self::REASONS.' as r')
                ->join('videos as v', 'v.id', '=', 'r.video_id')
                ->whereIn('r.video_id', $ids)
                ->whereColumn('v.banned_at', 'r.banned_at')
                ->pluck('r.reason', 'r.video_id');
        } catch (Throwable) {
            return [];
        }

        return $rows->mapWithKeys(fn ($reason, $id): array => [(int) $id => self::label((string) $reason)])->all();
    }

    /** A stored reason in the admin's language (this pass's reason written out, so the translation tools find it). */
    public static function label(string $reason): string
    {
        return $reason === self::REASON ? __('Blocked words (1.2.3)') : __($reason);
    }

    /** Narrows a video query to the videos banned for this pass's reason (Content > Videos' ?reason=FILTER). */
    public static function constrain(Builder $query): Builder
    {
        if (! self::available()) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->whereExists(fn ($exists) => $exists->select(DB::raw(1))
            ->from(self::REASONS.' as r')
            ->whereColumn('r.video_id', $table.'.id')
            ->whereColumn('r.banned_at', $table.'.banned_at')
            ->where('r.reason', self::REASON));
    }

    /** The pass's row, or null. */
    public static function state(): ?object
    {
        try {
            return DB::table(self::PASSES)->where('name', self::NAME)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** One slice of the first stage: the names of TAG_CHUNK tags; at the end of the tags, the categories. */
    private static function tagsSlice(object $state): void
    {
        $after = (int) $state->next_id;
        $hard = self::ids($state->hard_tags);

        try {
            $rows = Schema::hasTable('tags')
                ? DB::table('tags')->where('id', '>', $after)->orderBy('id')->limit(self::TAG_CHUNK)->get(['id', 'name'])
                : collect();
        } catch (Throwable) {
            $rows = collect();
        }

        foreach ($rows as $row) {
            if (ContentSafety::hides((string) $row->name)) {
                $hard[] = (int) $row->id;
            }
        }

        $hard = array_values(array_unique($hard));
        $last = $rows->isEmpty() ? $after : (int) $rows->last()->id;
        $values = ['next_id' => $last, 'hard_tags' => json_encode($hard), 'updated_at' => now()];

        if ($rows->count() < self::TAG_CHUNK) {
            // the tags are done: the categories (a few rows), then the videos from the first one
            $categories = self::hardCategories();
            $values = [
                'stage' => 'videos',
                'next_id' => 0,
                'hard_tags' => json_encode($hard),
                'hard_categories' => json_encode($categories),
                'tags_hidden' => count($hard),
                'categories_hidden' => count($categories),
                'updated_at' => now(),
            ];
        }

        // Only from the place this slice started: a process that went on meanwhile keeps its own (it read the same tags).
        DB::table(self::PASSES)->where('name', self::NAME)->where('stage', 'tags')->where('next_id', $after)->update($values);
    }

    /**
     * One slice of videos: those whose title, category or one of their tags uses a word of the hard list are banned
     * (one ban for the slice) and get the reason.
     *
     * @return array{0: int, 1: int} videos looked at, videos banned
     */
    private static function videosSlice(object $state): array
    {
        $after = (int) $state->next_id;
        $rows = DB::table('videos')->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->get(['id', 'title', 'category_id', 'status']);
        $hardCategories = array_flip(self::ids($state->hard_categories));
        $hardTags = self::ids($state->hard_tags);
        $match = [];

        foreach ($rows as $row) {
            if (isset($hardCategories[(int) $row->category_id]) || ContentSafety::hardMatch((string) $row->title) !== null) {
                $match[(int) $row->id] = true;
            }
        }

        if ($hardTags !== [] && $rows->isNotEmpty()) {
            // the slice is every video from its first id to its last: one range of the (video_id, tag_id) index
            $range = [(int) $rows->first()->id, (int) $rows->last()->id];

            foreach (array_chunk($hardTags, self::TAG_IDS_PER_QUERY) as $tags) {
                foreach (DB::table('video_tags')->whereBetween('video_id', $range)->whereIn('tag_id', $tags)->distinct()->pluck('video_id') as $id) {
                    $match[(int) $id] = true;
                }
            }
        }

        // not banned yet: a video banned before keeps its ban (its date, its kept status) and gets no reason of ours
        $candidates = $rows->filter(fn ($row): bool => isset($match[(int) $row->id]) && (int) $row->status !== VideoStatus::BANNED->value)
            ->map(fn ($row): int => (int) $row->id)->values()->all();
        $last = $rows->isEmpty() ? $after : (int) $rows->last()->id;

        // One transaction for the slice: the bans, their reasons, the count and the place go in together (a process
        // killed in the middle leaves the slice as it was, and the next run does it again; never a ban without its
        // reason, which the review list, Home's count and the Admin actions row would miss).
        $banned = DB::transaction(function () use ($candidates, $after, $last, $rows): int {
            $banned = $candidates === [] ? 0 : self::ban($candidates);

            if ($banned > 0) {
                DB::table(self::PASSES)->where('name', self::NAME)->update(['videos_hidden' => DB::raw('videos_hidden + '.$banned), 'updated_at' => now()]);
            }

            // The place moves forward only (a second process that read the same slice finds it moved and goes on from
            // there).
            DB::table(self::PASSES)->where('name', self::NAME)->where('next_id', '<', max($last, $after + 1))
                ->update(['next_id' => $last, 'videos_checked' => DB::raw('videos_checked + '.$rows->count()), 'updated_at' => now()]);

            return $banned;
        });

        if ($banned > 0) {
            self::leaveSearchIndex($candidates);
        }

        if ($rows->count() < self::CHUNK) {
            self::finish();
        }

        return [$rows->count(), $banned];
    }

    /**
     * Bans these videos (deleted ones too: a restored video stays banned) and notes the reason of each ban made here.
     * Runs inside the slice's transaction.
     *
     * @param  list<int>  $ids
     * @return int how many were banned
     */
    private static function ban(array $ids): int
    {
        // the second of the ban (VideoBans::ban() dates it now()): a ban made at the same time by an admin is not ours
        $since = now()->subSecond();
        $banned = VideoBans::ban(Video::withTrashed()->whereIn('id', $ids));

        if ($banned === 0) {
            return 0;
        }

        $rows = DB::table('videos')->whereIn('id', $ids)->where('status', VideoStatus::BANNED->value)
            ->where('banned_at', '>=', $since)->get(['id', 'banned_at']);
        $now = now();

        if ($rows->isNotEmpty()) {
            DB::table(self::REASONS)->upsert(
                $rows->map(fn ($row): array => ['video_id' => (int) $row->id, 'reason' => self::REASON, 'banned_at' => $row->banned_at, 'created_at' => $now])->all(),
                ['video_id'],
                ['reason', 'banned_at', 'created_at']
            );
        }

        return $banned;
    }

    /**
     * The videos this pass banned leave an external search index (the optional Meilisearch / Algolia search of
     * Settings): the ban is a plain update, which the index never hears of, so its quick search would offer them until
     * the index is made again. The built-in search reads the database and needs nothing. A search server that does not
     * answer never stops the pass: the videos are tried again from the queue (App\Support\VideoSearchIndex, round 3).
     *
     * @param  list<int>  $ids
     */
    private static function leaveSearchIndex(array $ids): void
    {
        VideoSearchIndex::follow($ids);
    }

    /** The end of the pass (once): the Admin actions row with the counts, and the menus' copies of the categories. */
    private static function finish(): void
    {
        $ended = DB::table(self::PASSES)->where('name', self::NAME)->whereNull('finished_at')
            ->update(['stage' => 'done', 'finished_at' => now(), 'updated_at' => now()]);

        if ($ended !== 1) {
            return;
        }

        $state = self::state();

        if ($state === null) {
            return;
        }

        // the side menu, the header and the category chips keep their categories (a category named with a hard word is
        // hidden as soon as these are made again)
        foreach (['categories', 'categories_chips_all'] as $key) {
            rescue(fn () => Cache::forget($key), null, false);
        }

        $videos = (int) $state->videos_hidden;
        $tags = (int) $state->tags_hidden;
        $categories = (int) $state->categories_hidden;

        if ($videos + $tags + $categories > 0) {
            self::audit(sprintf(
                '1.2.3: %s banned (reason "%s") because their titles, tags or categories use words that are always blocked; %s and %s hidden from visitors',
                $videos === 1 ? '1 video' : number_format($videos).' videos',
                self::REASON,
                $tags === 1 ? '1 tag' : number_format($tags).' tags',
                $categories === 1 ? '1 category' : number_format($categories).' categories'
            ));
        }
    }

    /** The categories whose name uses a word of the hard list (read now, not from a process's copy). */
    private static function hardCategories(): array
    {
        try {
            return DB::table('categories')->orderBy('id')->get(['id', 'title'])
                ->filter(fn ($row): bool => ContentSafety::hides((string) $row->title))
                ->map(fn ($row): int => (int) $row->id)->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * One row in Tools > Logs > Admin actions (in English, like the admin's own records), unless the same row is there
     * already; only on a site with accounts (a site being built has nothing to report).
     */
    private static function audit(string $details): void
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
            // The record is a courtesy; the videos are hidden.
        }
    }

    /** @return list<int> */
    private static function ids(mixed $json): array
    {
        $ids = is_string($json) ? json_decode($json, true) : null;

        return is_array($ids) ? array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric')))) : [];
    }
}
