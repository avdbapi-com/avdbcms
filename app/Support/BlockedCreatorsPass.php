<?php

namespace App\Support;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The refresh of 1.2.3 (N2-SAFE-01) on a site whose importers made creator channels already: the AVDBAPI and XVIDAPI
 * importers made a public channel from each studio, director or actor name without the blocked-words check, so a site
 * could have a channel whose handle or description uses a word of the hard list of App\Support\ContentSafety (words
 * that present a child or a school-age minor), public, linked from its videos' pages, in the profiles sitemap and
 * indexable. The importers no longer make or reuse such a channel (the video goes to the administrator's account); this
 * pass blocks the ones made before: every channel the importers made (IMPORTER_EMAIL_ENDINGS, the AVDBAPI creator key;
 * never a member's own account, never an administrator) whose handle, name or description uses such a word is blocked
 * with the existing block of Members (users.banned_at) and a stored reason, REASON, so its page answers 410, and it
 * leaves the profiles sitemap, the creators row and the public API at once. A blocked member's public videos stay
 * reachable by their address and in the video sitemap (VideoPolicy::show, the sitemap's own filter), so its videos are
 * banned too, with the existing ban (App\Support\VideoBans: each keeps its status for Unban) and the stored reason
 * VIDEO_REASON. Unblock in Members (one member or the bulk bar) gives the member back and unbans the videos this pass
 * banned for them (restoreVideos()). Texts the owner or a member wrote are never read: only the importers' accounts.
 *
 * In slices, like App\Support\BlockedWordsPass (the migration 2026_11_03_120000_hide_imported_creators_with_blocked_words
 * runs it within the time of a request of Finish; the scheduler's videos:hide-blocked-words and a visit of the admin's
 * Home carry on on a site too big for that): the members in id order, CHUNK at a time, with the place reached kept in
 * the row NAME of BlockedWordsPass::PASSES. A slice is safe to run twice and by two processes at once: a member blocked
 * already is never blocked again (the date stays), a video banned already keeps its ban, each channel's block and its
 * videos' bans are one transaction, and the place only moves forward. A member the admin unblocked is never blocked
 * again by this pass (the place has moved past them). When the pass ends, Tools > Logs > Admin actions gets one row
 * with the counts (AUDIT_ACTION), when it blocked anything, and Home says how many under "Needs you" (NOTICE), with the
 * list to review them (Members > Blocked, ?reason=FILTER), until the administrator dismisses it.
 */
final class BlockedCreatorsPass
{
    /** The one pass of the refresh of 1.2.3 (a row of BlockedWordsPass::PASSES). */
    public const NAME = '1.2.3-creators';

    /** The stored reason of a member's block: user_id => reason, with the date of the block it belongs to (users.banned_at). */
    public const REASONS = 'user_ban_reasons';

    /** The reason this pass stores for a member (English, shown translated in Members; the words of the 1.2.3 pass). */
    public const REASON = 'Blocked words (1.2.3)';

    /** The reason this pass stores for a blocked channel's videos (English, shown translated in Content > Videos). */
    public const VIDEO_REASON = 'Blocked creator channel (1.2.3)';

    /** The value of Members' ?reason= that lists the members this pass blocked. */
    public const FILTER = 'blocked-words';

    /** The Admin actions row (its label: App\Support\AuditActions). */
    public const AUDIT_ACTION = 'update.blocked_creators';

    /** Home's notice, which the admin hides with its own button (App\Http\Controllers\Admin\AdminController::NOTICES). */
    public const NOTICE = 'blocked-creators';

    /** The seconds the update's own slice works within a request of Finish (the scheduler goes on with the rest). */
    public const SECONDS = 10.0;

    /** Members per slice: one read of the members, a block per channel that matches (a few dozen ms each). */
    public const CHUNK = 2000;

    /**
     * What marks an account the importers made: the e-mail address App\Console\Commands\ImportXvidVideos gives a
     * studio's or actor's channel ('creator.<hash>@xvidapi.local') and the one ImportAvdbVideos gives a director's or
     * actor's ('avdb-<hash>@creators.avdbclip.invalid'); an AVDBAPI channel also carries users.avdb_creator_key.
     */
    public const IMPORTER_EMAIL_ENDINGS = ['@xvidapi.local', '@creators.avdbclip.invalid'];

    /** Reason rows per query (well inside the bound-parameter limits of every database). */
    private const REASON_ROWS_PER_QUERY = 500;

    /** @var array{at: float, yes: bool}|null available() of this process */
    private static ?array $available = null;

    /** @var bool|null whether users has the AVDBAPI creator columns (avdb_creator_key, avdb_creator_name) */
    private static ?bool $creatorColumns = null;

    /** Whether the update made the table and columns (until "Finish update", a site has none: nothing to show or filter). */
    public static function available(): bool
    {
        $now = microtime(true);

        // A positive answer is kept; a negative one for a minute (the update may add the table meanwhile).
        if (self::$available !== null && (self::$available['yes'] || $now - self::$available['at'] < 60)) {
            return self::$available['yes'];
        }

        try {
            $yes = Schema::hasTable(self::REASONS) && Schema::hasTable(BlockedWordsPass::PASSES)
                && Schema::hasColumn(BlockedWordsPass::PASSES, 'members_hidden');
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
        self::$creatorColumns = null;
    }

    /** Notes the pass (once: a pass that ran, or runs, keeps its place). */
    public static function start(): void
    {
        if (! self::available()) {
            return;
        }

        DB::table(BlockedWordsPass::PASSES)->insertOrIgnore([
            'name' => self::NAME,
            'stage' => 'members',
            'next_id' => 0,
            'hard_tags' => '[]',
            'hard_categories' => '[]',
            'videos_checked' => 0,
            'videos_hidden' => 0,
            'tags_hidden' => 0,
            'categories_hidden' => 0,
            'members_checked' => 0,
            'members_hidden' => 0,
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
     * Goes on with the pass for about $seconds (at least one slice).
     *
     * @return array{checked: int, hidden: int, videos: int, done: bool} members looked at, channels blocked and videos
     *                                                                     banned by this call, and whether the pass has
     *                                                                     ended
     */
    public static function run(float $seconds): array
    {
        if (! self::available()) {
            return ['checked' => 0, 'hidden' => 0, 'videos' => 0, 'done' => true];
        }

        $deadline = microtime(true) + max(0.0, $seconds);
        $checked = 0;
        $hidden = 0;
        $videos = 0;

        try {
            do {
                $state = self::state();

                if ($state === null || $state->finished_at !== null) {
                    break;
                }

                [$looked, $blocked, $banned] = self::slice($state);
                $checked += $looked;
                $hidden += $blocked;
                $videos += $banned;
            } while (microtime(true) < $deadline);
        } finally {
            if ($hidden > 0 || $videos > 0) {
                // the lists that keep a copy: the sitemaps, the admin's numbers (the public feeds keep theirs 30 seconds)
                rescue(fn () => SeoSitemapCache::clear(), null, false);
                rescue(fn () => AdminCache::bumpVersion(), null, false);
            }
        }

        $state = self::state();

        return ['checked' => $checked, 'hidden' => $hidden, 'videos' => $videos, 'done' => $state === null || $state->finished_at !== null];
    }

    /**
     * The members this pass blocked that are still blocked for its reason and not deleted (Home's notice: those the
     * review list shows).
     */
    public static function hiddenCount(): int
    {
        if (! self::available()) {
            return 0;
        }

        try {
            return (int) DB::table(self::REASONS.' as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.reason', self::REASON)
                ->whereNotNull('u.banned_at')
                ->whereNull('u.deleted_at')
                ->whereColumn('u.banned_at', 'r.banned_at')
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The reason of the blocks of these members that have one (Members: "Blocked" and why), translated.
     *
     * @param  iterable<User>  $users
     * @return array<int, string> user id => reason
     */
    public static function reasons(iterable $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            if ($user instanceof User && $user->banned_at !== null) {
                $ids[] = (int) $user->getKey();
            }
        }

        if ($ids === [] || ! self::available()) {
            return [];
        }

        try {
            $rows = DB::table(self::REASONS.' as r')
                ->join('users as u', 'u.id', '=', 'r.user_id')
                ->whereIn('r.user_id', $ids)
                ->whereColumn('u.banned_at', 'r.banned_at')
                ->pluck('r.reason', 'r.user_id');
        } catch (Throwable) {
            return [];
        }

        return $rows->mapWithKeys(fn ($reason, $id): array => [(int) $id => self::label((string) $reason)])->all();
    }

    /** A stored reason in the admin's language (both of this pass's reasons written out, so the translation tools find them). */
    public static function label(string $reason): string
    {
        return match ($reason) {
            self::REASON => __('Blocked words (1.2.3)'),
            self::VIDEO_REASON => __('Blocked creator channel (1.2.3)'),
            default => __($reason),
        };
    }

    /** Narrows a member query to the members blocked for this pass's reason (Members' ?reason=FILTER). */
    public static function constrain(Builder $query): Builder
    {
        if (! self::available()) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->whereExists(fn ($exists) => $exists->select(DB::raw(1))
            ->from(self::REASONS.' as r')
            ->whereColumn('r.user_id', $table.'.id')
            ->whereColumn('r.banned_at', $table.'.banned_at')
            ->where('r.reason', self::REASON));
    }

    /**
     * Unblock in Members: the videos this pass banned for these members (their ban still the pass's, VIDEO_REASON with
     * the ban's date) go back to the status they had (App\Support\VideoBans::unban), and the external search hears of
     * it. A video the admin banned, before or since, keeps the admin's ban; a deleted video stays banned.
     *
     * @param  list<int>  $userIds
     * @return list<int> the videos unbanned
     */
    public static function restoreVideos(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === [] || ! self::available() || ! BlockedWordsPass::available()) {
            return [];
        }

        $ids = Video::query()
            ->whereIn('user_id', $userIds)
            ->where('status', VideoStatus::BANNED->value)
            ->whereExists(fn ($exists) => $exists->select(DB::raw(1))
                ->from(BlockedWordsPass::REASONS.' as r')
                ->whereColumn('r.video_id', 'videos.id')
                ->whereColumn('r.banned_at', 'videos.banned_at')
                ->where('r.reason', self::VIDEO_REASON))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        VideoBans::unban(Video::query()->whereIn('id', $ids));
        VideoSearchIndex::follow($ids);
        rescue(fn () => SeoSitemapCache::clear(), null, false);

        return $ids;
    }

    /** The pass's row, or null. */
    public static function state(): ?object
    {
        try {
            return DB::table(BlockedWordsPass::PASSES)->where('name', self::NAME)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether this account was made by an importer (the e-mail address it was given, or the AVDBAPI creator key). */
    public static function importerMade(object $row): bool
    {
        $email = strtolower(trim((string) ($row->email ?? '')));

        foreach (self::IMPORTER_EMAIL_ENDINGS as $ending) {
            if ($email !== '' && str_ends_with($email, $ending)) {
                return true;
            }
        }

        return trim((string) ($row->avdb_creator_key ?? '')) !== '';
    }

    /** The word of the hard list this account's handle, name or description uses, or null. */
    public static function hardWord(object $row): ?string
    {
        return ContentSafety::hardMatchAny([$row->username ?? null, $row->description ?? null, $row->avdb_creator_name ?? null]);
    }

    /**
     * One slice: CHUNK members in id order; each channel the importers made whose texts use a word of the hard list is
     * blocked with its videos (one transaction each), then the place moves forward.
     *
     * @return array{0: int, 1: int, 2: int} members looked at, channels blocked, videos banned
     */
    private static function slice(object $state): array
    {
        $after = (int) $state->next_id;
        $columns = ['id', 'email', 'username', 'description', 'is_admin', 'banned_at'];

        if (self::creatorColumns()) {
            $columns[] = 'avdb_creator_key';
            $columns[] = 'avdb_creator_name';
        }

        $rows = DB::table('users')->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->get($columns);
        $blocked = 0;
        $banned = 0;
        $followed = [];

        foreach ($rows as $row) {
            // never a member's own account, never an administrator
            if ((bool) $row->is_admin || ! self::importerMade($row) || self::hardWord($row) === null) {
                continue;
            }

            [$member, $videos, $ids] = self::block($row);
            $blocked += $member;
            $banned += $videos;
            $followed = [...$followed, ...$ids];
        }

        $last = $rows->isEmpty() ? $after : (int) $rows->last()->id;

        // The place moves forward only (a second process that read the same slice finds it moved and goes on from there).
        DB::table(BlockedWordsPass::PASSES)->where('name', self::NAME)->where('next_id', '<', max($last, $after + 1))
            ->update(['next_id' => $last, 'members_checked' => DB::raw('members_checked + '.$rows->count()), 'updated_at' => now()]);

        if ($followed !== []) {
            // the optional external search (Meilisearch, Algolia) hears of the videos banned here
            VideoSearchIndex::follow($followed);
        }

        if ($rows->count() < self::CHUNK) {
            self::finish();
        }

        return [$rows->count(), $blocked, $banned];
    }

    /**
     * Blocks one channel (unless it is blocked already: the date stays) and bans its videos that are not banned yet
     * (deleted ones too: a restored video stays banned), each with its stored reason, in one transaction with the
     * counts: a process killed in the middle leaves the channel as it was, and the next run does it again. The videos
     * are banned also when an administrator blocked the member before without them: a channel named with such a word
     * keeps no public video (Unblock gives them back, restoreVideos()).
     *
     * @return array{0: int, 1: int, 2: list<int>} 1 when the member was blocked here, the videos banned here, their ids
     */
    private static function block(object $row): array
    {
        return DB::transaction(function () use ($row): array {
            $id = (int) $row->id;
            $now = now();
            $member = 0;

            if ($row->banned_at === null) {
                $member = DB::table('users')->where('id', $id)->whereNull('banned_at')->update(['banned_at' => $now]);

                if ($member === 1) {
                    $bannedAt = DB::table('users')->where('id', $id)->value('banned_at');
                    DB::table(self::REASONS)->upsert(
                        [['user_id' => $id, 'reason' => self::REASON, 'banned_at' => $bannedAt, 'created_at' => $now]],
                        ['user_id'],
                        ['reason', 'banned_at', 'created_at']
                    );
                }
            }

            // the second of the ban (VideoBans::ban() dates it now()): a ban made at the same time by an admin is not ours
            $since = now()->subSecond();
            $banned = VideoBans::ban(Video::withTrashed()->where('user_id', $id));
            $ids = [];

            if ($banned > 0) {
                $videos = DB::table('videos')->where('user_id', $id)->where('status', VideoStatus::BANNED->value)
                    ->where('banned_at', '>=', $since)->get(['id', 'banned_at']);

                foreach ($videos->chunk(self::REASON_ROWS_PER_QUERY) as $chunk) {
                    DB::table(BlockedWordsPass::REASONS)->upsert(
                        $chunk->map(fn ($video): array => ['video_id' => (int) $video->id, 'reason' => self::VIDEO_REASON, 'banned_at' => $video->banned_at, 'created_at' => $now])->values()->all(),
                        ['video_id'],
                        ['reason', 'banned_at', 'created_at']
                    );
                }

                $ids = $videos->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }

            if ($member > 0 || $banned > 0) {
                DB::table(BlockedWordsPass::PASSES)->where('name', self::NAME)->update([
                    'members_hidden' => DB::raw('members_hidden + '.$member),
                    'videos_hidden' => DB::raw('videos_hidden + '.$banned),
                    'updated_at' => $now,
                ]);
            }

            return [$member, $banned, $ids];
        });
    }

    /** The end of the pass (once): the Admin actions row with the counts. */
    private static function finish(): void
    {
        $ended = DB::table(BlockedWordsPass::PASSES)->where('name', self::NAME)->whereNull('finished_at')
            ->update(['stage' => 'done', 'finished_at' => now(), 'updated_at' => now()]);

        if ($ended !== 1) {
            return;
        }

        $state = self::state();

        if ($state === null) {
            return;
        }

        $members = (int) $state->members_hidden;
        $videos = (int) $state->videos_hidden;

        if ($members + $videos > 0) {
            self::audit(sprintf(
                '1.2.3 refresh: %s made by the importers blocked (reason "%s") because their names or descriptions use words that are always blocked, with %s of their videos banned (reason "%s")',
                $members === 1 ? '1 creator channel' : number_format($members).' creator channels',
                self::REASON,
                number_format($videos),
                self::VIDEO_REASON
            ));
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
            // The record is a courtesy; the channels are blocked.
        }
    }

    /** Whether users has the AVDBAPI creator columns (the migration of 1.2.0 adds them; read once per process). */
    private static function creatorColumns(): bool
    {
        if (self::$creatorColumns !== null) {
            return self::$creatorColumns;
        }

        try {
            return self::$creatorColumns = Schema::hasColumns('users', ['avdb_creator_key', 'avdb_creator_name']);
        } catch (Throwable) {
            return self::$creatorColumns = false;
        }
    }
}
