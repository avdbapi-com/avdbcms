<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The times of history, subscription and saved-playlist rows that 1.2.0 to 1.2.2 left to the database's own clock.
 *
 * views.view_at, subscriptions.subscribe_at / read_at and favorites_playlist.added_at default to CURRENT_TIMESTAMP, which
 * 1.2.0 to 1.2.2 used for every row they wrote (ViewService::incrementView(), the two subscribe toggles and the saved
 * playlists' toggle send no time): the row holds the moment it was written (the columns are TIMESTAMPs), and the site
 * reads it back through the database server's own zone (the site sets no session zone). The one exception is
 * subscriptions.read_at after a visit of the channel: UserController::updateReadDate() wrote PHP's now() in the site's
 * zone (a web request, where the admin's Time zone applied). 1.2.3 writes these columns with the site's clock, like
 * every other time it writes, and reads every row as the site's time. On a site whose database runs in another zone, the
 * older rows were off by the difference: History listed an older view first under the next day ("Watched at 01:05"),
 * the studio said "Subscribed 5 hours from now" and the Dashboard's last 28 days missed those subscribers.
 *
 * The update to 1.2.3 (migration 2026_10_27_000000_fix_old_row_times, start()) notes for each of the three columns the
 * last row the database's clock dated (its id: ids grow with time) and the site's time zone at that moment, and the
 * scheduler (history:fix-old-times, run()) writes those rows again in that zone, a slice at a time, so they read like the
 * rows written since:
 *  - the site's time zone is read the way a web request reads it (siteZone(): the admin's Time zone setting, else the
 *    configuration's zone), never only the configuration of the console process that runs the update or the scheduler
 *    (judge R2A-001: a console process that had not applied the admin's settings put every old row in config/app.php's
 *    Europe/Paris, one way, and the admin log said it had worked);
 *  - the moment of each row comes from the database itself (UNIX_TIMESTAMP of a TIMESTAMP column is the moment it
 *    stores, daylight saving included; SQLite's CURRENT_TIMESTAMP is UTC), and is written back as the wall-clock time
 *    of the noted zone, the way the site writes every time; a row that already reads so (a database that runs in the
 *    site's zone) is not written;
 *  - every slice is claimed first (compare-and-set on its row of the progress table) and, where the table keeps
 *    transactions, claimed, written and counted in one transaction: a row is never converted twice, by a second run, a
 *    second process, a run after a crash or a second update; on a table without transactions a slice stopped half way
 *    keeps the database's time (as before 1.2.3), it is never shifted twice;
 *  - subscriptions.read_at only while it is still the database's default (the same moment as subscribe_at): a visit of
 *    the channel since wrote it with the site's clock (1.2.2 did too);
 *  - the rows are noted once (start() never moves a noted row), so a run of the update again never takes rows the
 *    site's clock wrote since;
 *  - Tools > Logs > Admin actions gets one line per column that had rows: how many were put right and the zone they
 *    were put in, or that none needed it (auditDetails()).
 * Until start() has noted the rows, the site keeps leaving these columns to the database's clock (stamp()), so every row
 * up to the noted ones has the same clock. A site without the cron job keeps the old rows as 1.2.2 showed them.
 *
 * Two more kinds of rows, the same way (judge R3-005, R3-035; migration 2026_10_29_100000_fix_more_old_row_times notes
 * them on a site whose update to 1.2.3 noted the first three only):
 *  - activities.perform_at of a like or dislike (subject App\Models\Interaction): 1.2.0 to 1.2.2 gave the activity the
 *    interaction's created_at, which an interaction does not have, so the column's CURRENT_TIMESTAMP filled it (on a
 *    change of the like too). 1.2.3 gives it the interaction's own moment (its perform_at, written with the site's
 *    clock), so a row that already holds its interaction's moment is the site's time and is left alone, also one 1.2.3
 *    wrote before the note or rewrote since; the others are put in the noted zone like the rest. The comments' rows had
 *    their comment's time all along;
 *  - playlist_has_videos.added_at ("Date added" of a playlist): every 1.2.x way of adding a video to a playlist (Save to
 *    playlist, the studio's playlist editor and new playlist, the video's playlists on the upload and details pages) left
 *    it to CURRENT_TIMESTAMP, except Watch later's own button, which wrote PHP's now(). Watch later (a playlist the member
 *    cannot delete, or one titled "Watch Later": the one Watch later's button uses) mixes both clocks with no way to tell
 *    them apart, so its rows are left as they are and 1.2.3 always writes the site's clock there (playlistStamp()); the
 *    rows of every other playlist are put right, and 1.2.3 writes them with the site's clock once they are noted. On a
 *    site that ran an earlier build of 1.2.3 (which wrote the site's clock in the studio's editor already) these rows
 *    are noted as done: they cannot be told apart either.
 */
final class LegacyRowTimes
{
    /** The progress table: one row per column (name "table.column"), made by the update to 1.2.3. */
    public const TABLE = 'legacy_time_fixes';

    /** table => the column whose rows are put right (subscriptions: read_at follows subscribe_at, see above) */
    public const COLUMNS = [
        'views' => 'view_at',
        'subscriptions' => 'subscribe_at',
        'favorites_playlist' => 'added_at',
        'activities' => 'perform_at',
        'playlist_has_videos' => 'added_at',
    ];

    /** The activities put right: those of likes and dislikes (the subject's morph class, as 1.2.x wrote it). */
    public const INTERACTION_SUBJECT = 'App\\Models\\Interaction';

    /** The title of the Watch later playlist (App\Playlists\Types\WatchLaterPlaylist::getName(), 1.2.0 to 1.2.3). */
    public const WATCH_LATER_TITLE = 'Watch Later';

    /** Rows per slice: one read and one update each. */
    public const CHUNK = 1000;

    /**
     * The action of the line Tools > Logs > Admin actions gets when a column is done (its label: "Update: older times set
     * to the site time zone", App\Support\AuditActions::LABELS).
     */
    public const AUDIT_ACTION = 'update.row_times';

    /** "The rows are noted" (cache, for 10 minutes): writers ask it on every write. */
    private const READY_KEY = 'legacy_row_times.ready';

    private const READY_SECONDS = 600;

    /** The columns every build of 1.2.3 noted with the progress table itself (their rows come with it). */
    private const FIRST_NAMES = ['views.view_at', 'subscriptions.subscribe_at', 'favorites_playlist.added_at'];

    /** The names the progress table holds (cache): the later columns are noted by their own update step. */
    private const NOTED_KEY = 'legacy_row_times.noted.v2';

    /** @var array<string, bool> per table: whether it keeps transactions (InnoDB, SQLite) */
    private static array $transactional = [];

    /**
     * The times a writer sets on a new row of $table: the site's clock once the update has noted the old rows (the
     * columns of COLUMNS, and read_at for subscriptions), else none (the database's clock, as every row before them).
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    public static function stamp(string $table): array
    {
        if (! isset(self::COLUMNS[$table]) || ! self::noted($table.'.'.self::COLUMNS[$table])) {
            return [];
        }

        $now = now();

        return $table === 'subscriptions'
            ? ['subscribe_at' => $now, 'read_at' => $now]
            : [self::COLUMNS[$table] => $now];
    }

    /**
     * The "Date added" a writer sets on a new row of $playlist (playlist_has_videos.added_at): the site's clock for Watch
     * later always (its button wrote it since 1.2.0), for every other playlist once the update has noted the old rows,
     * else none (the database's clock, as every row before them).
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    public static function playlistStamp(?object $playlist): array
    {
        return ($playlist !== null && self::keepsSiteClock($playlist)) || self::noted('playlist_has_videos.added_at')
            ? ['added_at' => now()]
            : [];
    }

    /**
     * Whether a playlist's rows hold the site's clock whatever wrote them: Watch later (a playlist the member cannot
     * delete, or one titled like it, as Watch later's own button finds it). Its old rows are never put right.
     */
    public static function keepsSiteClock(object $playlist): bool
    {
        $deletable = $playlist->is_deletable ?? true;

        return ! (bool) $deletable || strcasecmp(trim((string) ($playlist->title ?? '')), self::WATCH_LATER_TITLE) === 0;
    }

    /**
     * Whether the update has noted the old rows of the column $name ("table.column"): the first three come with the
     * progress table, the later ones with their own row in it (kept 10 minutes once all are there, 5 seconds before: a
     * list read just before the update step noted them is soon read again).
     */
    public static function noted(string $name): bool
    {
        if (in_array($name, self::FIRST_NAMES, true)) {
            return self::ready();
        }

        try {
            $names = Cache::get(self::NOTED_KEY);
        } catch (Throwable) {
            $names = null;
        }

        if (! is_array($names)) {
            try {
                $names = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->pluck('name')->map(fn ($name) => (string) $name)->all() : [];
            } catch (Throwable) {
                return false;
            }

            $all = count(array_diff(self::names(), $names)) === 0;
            rescue(fn () => Cache::put(self::NOTED_KEY, $names, $all ? self::READY_SECONDS : 5), null, false);
        }

        return in_array($name, $names, true);
    }

    /** Whether the update has noted the old rows (the progress table exists: it appears with its rows, see start()). */
    public static function ready(): bool
    {
        try {
            if (Cache::get(self::READY_KEY) === true) {
                return true;
            }
        } catch (Throwable) {
            // no cache: ask the database
        }

        try {
            $ready = Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }

        if ($ready) {
            rescue(fn () => Cache::put(self::READY_KEY, true, self::READY_SECONDS), null, false);
        }

        return $ready;
    }

    /**
     * The site's time zone, read the way a web request reads it (AppServiceProvider::applySettings()): the admin's Time
     * zone (setting "timezone" of the group "localization"), from the web's cached settings or the table, read only
     * (SiteSettingsSource::console()), when it is a zone PHP knows (the web's own test); else the configuration's zone,
     * which is what a web request uses then. Not the console process's configuration alone: the update's migrate, a
     * command started by hand and a process whose settings could not all be applied (round 1b's R1R-06 applies them to
     * the scheduler's processes when it can) still have config/app.php's Europe/Paris there.
     */
    public static function siteZone(): string
    {
        try {
            $stored = SiteSettingsSource::console()?->group('localization')['timezone'] ?? null;
        } catch (Throwable) {
            $stored = null;
        }

        if (! empty($stored) && is_string($stored) && self::phpKnows($stored)) {
            return $stored;
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * Notes, for each column, the last row the database's clock wrote and the site's time zone, once (the update to
     * 1.2.3). The progress table gets its rows under another name and appears at once by a rename, so a writer never sees
     * it without them: rows written between the note and the rename keep the database's clock and are left as they are
     * (never shifted twice).
     */
    public static function start(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        $building = self::TABLE.'_new';
        $now = now();
        // the zone the site writes its own times in from now on: the old rows join it, whatever the admin chooses later
        $zone = self::siteZone();
        $rows = [];

        // first the last rows, then the table: what is written meanwhile is newer than the noted rows
        foreach (self::COLUMNS as $table => $column) {
            $boundary = 0;

            if (Schema::hasTable($table) && Schema::hasColumns($table, ['id', $column])) {
                $boundary = (int) DB::table($table)->max('id');
            }

            $rows[] = [
                'name' => $table.'.'.$column,
                'boundary' => $boundary,
                'next' => 0,
                'changed' => 0,
                'checked' => 0,
                'zone' => $zone,
                'finished_at' => $boundary === 0 || ! self::convertible() ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Schema::dropIfExists($building);
        Schema::create($building, function ($table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->unsignedBigInteger('boundary');
            $table->unsignedBigInteger('next')->default(0);
            $table->unsignedBigInteger('changed')->default(0);
            $table->unsignedBigInteger('checked')->default(0);
            $table->string('zone', 64)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        DB::table($building)->insert($rows);
        Schema::rename($building, self::TABLE);
        rescue(fn () => Cache::forget(self::READY_KEY), null, false);
        rescue(fn () => Cache::forget(self::NOTED_KEY), null, false);
    }

    /**
     * Notes the columns an earlier build of 1.2.3 did not note yet, once (migration 2026_10_29_100000): on a site without
     * the progress table, start() notes every column. A column already noted is never noted again (its rows up to the
     * note would be taken twice). Of the later columns, the likes' and dislikes' activities are noted like the others
     * (a row that holds its interaction's moment is left alone: what that earlier build wrote); the playlists' rows are
     * noted as done, because that earlier build already wrote the site's clock in some of them (see the class).
     */
    public static function noteMissing(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            self::start();

            return;
        }

        $noted = DB::table(self::TABLE)->pluck('name')->map(fn ($name) => (string) $name)->all();
        $extra = Schema::hasColumns(self::TABLE, ['checked', 'zone']);
        $now = now();
        $zone = self::siteZone();

        foreach (self::COLUMNS as $table => $column) {
            $name = $table.'.'.$column;

            if (in_array($name, $noted, true)) {
                continue;
            }

            $boundary = Schema::hasTable($table) && Schema::hasColumns($table, ['id', $column]) ? (int) DB::table($table)->max('id') : 0;
            $row = [
                'name' => $name,
                'boundary' => $boundary,
                'next' => 0,
                'changed' => 0,
                'finished_at' => $boundary === 0 || $table === 'playlist_has_videos' || ! self::convertible() ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($extra) {
                $row += ['checked' => 0, 'zone' => $zone];
            }

            // insertOrIgnore: a second process noting the same column at the same moment adds nothing (name is unique)
            DB::table(self::TABLE)->insertOrIgnore([$row]);
        }

        rescue(fn () => Cache::forget(self::READY_KEY), null, false);
        rescue(fn () => Cache::forget(self::NOTED_KEY), null, false);
    }

    /** @return list<string> every column's name ("table.column") */
    private static function names(): array
    {
        return array_map(fn (string $table): string => $table.'.'.self::COLUMNS[$table], array_keys(self::COLUMNS));
    }

    /** Whether some rows still wait (the scheduler starts history:fix-old-times then). */
    public static function due(): bool
    {
        try {
            return Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->whereNull('finished_at')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Puts the noted rows right for about $seconds, a slice at a time, where the last run stopped.
     *
     * @return array{checked: int, changed: int, done: bool, zone: ?string}
     */
    public static function run(float $seconds): array
    {
        $result = ['checked' => 0, 'changed' => 0, 'done' => true, 'zone' => null];

        if (! self::due()) {
            return $result;
        }

        $deadline = microtime(true) + max(0.0, $seconds);
        // a progress table an earlier build of 1.2.3 made has no zone and no checked count: the zone of now, no count
        $extra = Schema::hasColumns(self::TABLE, ['checked', 'zone']);

        foreach (self::COLUMNS as $table => $column) {
            $name = $table.'.'.$column;

            while (true) {
                $state = DB::table(self::TABLE)->where('name', $name)->first($extra ? ['next', 'boundary', 'finished_at', 'zone'] : ['next', 'boundary', 'finished_at']);

                if ($state === null || $state->finished_at !== null) {
                    break;
                }

                $zone = self::notedZone($extra ? $state->zone : null);
                $result['zone'] = $zone;

                if (microtime(true) >= $deadline) {
                    $result['done'] = false;

                    return $result;
                }

                $slice = self::slice($table, $column, (int) $state->next, (int) $state->boundary, $zone);

                if ($slice === null) {
                    self::finish($name, $table, $zone, $extra);
                    break;
                }

                [$lastId, $changes] = $slice;
                // claim, write and count as one step (one transaction where the table keeps them): a crash in between
                // leaves the slice as it was, for the next run; null when another run took the slice meanwhile
                $step = static function () use ($name, $state, $lastId, $table, $column, $changes, $extra): ?int {
                    $claimed = DB::table(self::TABLE)
                        ->where('name', $name)
                        ->where('next', (int) $state->next)
                        ->whereNull('finished_at')
                        ->update(['next' => $lastId, 'updated_at' => now()]) === 1;

                    if (! $claimed) {
                        return null;
                    }

                    $written = self::write($table, $column, $changes);
                    $counts = ['changed' => DB::raw('changed + '.$written)];

                    if ($extra) {
                        $counts['checked'] = DB::raw('checked + '.count($changes['checked']));
                    }

                    DB::table(self::TABLE)->where('name', $name)->update($counts);

                    return $written;
                };

                $written = self::transactional($table) ? DB::transaction($step) : $step();

                if ($written === null) {
                    // another run took this slice meanwhile: read where it stands now
                    continue;
                }

                $result['checked'] += count($changes['checked']);
                $result['changed'] += $written;
            }
        }

        $result['done'] = ! self::due();

        return $result;
    }

    /**
     * The words of the column's line in Tools > Logs > Admin actions, stored in English like every admin record (the log
     * shows them in the admin's language: App\Support\AuditDetails):
     *  - rows put right: "1.2.3: 1,204 views in History recorded before the update now show their times in the site time
     *    zone (Asia/Bangkok)": the number written again and the zone they were written in;
     *  - none needed it (the database runs in the site's zone): "1.2.3: the 1,300 views in History recorded before the
     *    update already showed their times in the site time zone (UTC), nothing was changed" (a translation key with
     *    :version, :count and :zone, by the number checked).
     * The likes' and dislikes' activities say "likes and dislikes in Your activity", the playlists' rows "videos added to
     * playlists".
     */
    public static function auditDetails(string $table, int $changed, ?int $checked = null, ?string $zone = null): string
    {
        $zone ??= self::siteZone();

        if ($changed > 0) {
            $one = $changed === 1;
            $what = match ($table) {
                'views' => $one ? 'view in History' : 'views in History',
                'subscriptions' => $one ? 'subscription' : 'subscriptions',
                'activities' => $one ? 'like or dislike in Your activity' : 'likes and dislikes in Your activity',
                'playlist_has_videos' => $one ? 'video added to a playlist' : 'videos added to playlists',
                default => $one ? 'saved playlist' : 'saved playlists',
            };

            return sprintf('1.2.3: %s %s recorded before the update now %s the site time zone (%s)', number_format($changed), $what, $one ? 'shows its time in' : 'show their times in', $zone);
        }

        $count = max(0, (int) $checked);
        $replace = ['version' => '1.2.3', 'count' => number_format($count), 'zone' => $zone];

        return match ($table) {
            'views' => trans_choice(':version: the 1 view in History recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count views in History recorded before the update already showed their times in the site time zone (:zone), nothing was changed', $count, $replace, 'en'),
            'subscriptions' => trans_choice(':version: the 1 subscription recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count subscriptions recorded before the update already showed their times in the site time zone (:zone), nothing was changed', $count, $replace, 'en'),
            'activities' => trans_choice(':version: the 1 like or dislike in Your activity recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count likes and dislikes in Your activity recorded before the update already showed their times in the site time zone (:zone), nothing was changed', $count, $replace, 'en'),
            'playlist_has_videos' => trans_choice(':version: the 1 video added to a playlist recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count videos added to playlists recorded before the update already showed their times in the site time zone (:zone), nothing was changed', $count, $replace, 'en'),
            default => trans_choice(':version: the 1 saved playlist recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count saved playlists recorded before the update already showed their times in the site time zone (:zone), nothing was changed', $count, $replace, 'en'),
        };
    }

    /** The zone noted by the update when PHP knows it (a value read back from the table), else the site's zone now. */
    private static function notedZone(mixed $noted): string
    {
        return is_string($noted) && $noted !== '' && self::phpKnows($noted) ? $noted : self::siteZone();
    }

    /**
     * Whether PHP knows the zone, by the web's own test (AppServiceProvider::applySettings(): date_default_timezone_set()
     * accepts it); the process's default zone is put back.
     */
    private static function phpKnows(string $zone): bool
    {
        $current = date_default_timezone_get();
        $known = @date_default_timezone_set($zone);

        if ($known) {
            date_default_timezone_set($current);
        }

        return $known;
    }

    /**
     * The next slice of noted rows after $after: [its last id, ['checked' => ids, 'rows' => [id => site time]]] (only
     * the rows whose time changes), or null when none is left.
     *
     * @return array{0: int, 1: array{checked: list<int>, rows: array<int, string>}}|null
     */
    private static function slice(string $table, string $column, int $after, int $boundary, string $zone): ?array
    {
        if ($after >= $boundary) {
            return null;
        }

        $wrapped = DB::getQueryGrammar()->wrap($table.'.'.$column);
        $query = self::candidates($table)
            ->where($table.'.id', '>', $after)
            ->where($table.'.id', '<=', $boundary)
            ->orderBy($table.'.id')
            ->limit(self::CHUNK)
            ->addSelect([$table.'.id', $table.'.'.$column.' as at_value']);

        if (self::mysql()) {
            // the moment the TIMESTAMP column stores (UNIX_TIMESTAMP returns it as it is: no time zone in between)
            $query->selectRaw('UNIX_TIMESTAMP('.$wrapped.') AS at_moment');
        }

        if ($table === 'activities') {
            // the like's own moment, written with the site's clock (see the class)
            $query->addSelect('interactions.perform_at as subject_at');
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $checked = [];
        $changed = [];

        foreach ($rows as $row) {
            $checked[] = (int) $row->id;
            $stored = $row->at_value === null ? '' : substr((string) $row->at_value, 0, 19);

            if ($stored === '') {
                continue;
            }

            // an activity that holds its like's own moment has the site's time already (1.2.3 wrote it, or rewrote it)
            if ($table === 'activities' && $row->subject_at !== null && substr((string) $row->subject_at, 0, 19) === $stored) {
                continue;
            }

            $moment = self::mysql()
                ? (is_numeric($row->at_moment ?? null) ? (int) $row->at_moment : 0)
                // SQLite: CURRENT_TIMESTAMP is the UTC time
                : (int) (strtotime($stored.' UTC') ?: 0);

            if ($moment <= 0) {
                continue;
            }

            $site = CarbonImmutable::createFromTimestamp($moment, $zone)->format('Y-m-d H:i:s');

            if ($site !== $stored) {
                $changed[(int) $row->id] = $site;
            }
        }

        return [(int) $rows->last()->id, ['checked' => $checked, 'rows' => $changed]];
    }

    /**
     * The rows of $table this conversion is about: every row, except for activities (those of likes and dislikes, with
     * their like, see the class) and playlist rows (those of every playlist but Watch later: keepsSiteClock()).
     */
    private static function candidates(string $table): \Illuminate\Database\Query\Builder
    {
        return match ($table) {
            'activities' => DB::table('activities')
                ->leftJoin('interactions', 'interactions.id', '=', 'activities.subject_id')
                ->where('activities.subject_type', self::INTERACTION_SUBJECT),
            'playlist_has_videos' => DB::table('playlist_has_videos')
                ->join('playlists', 'playlists.id', '=', 'playlist_has_videos.playlist_id')
                ->where('playlists.is_deletable', true)
                ->whereRaw('LOWER(TRIM(playlists.title)) <> ?', [strtolower(self::WATCH_LATER_TITLE)]),
            default => DB::table($table),
        };
    }

    /**
     * Writes the slice's new times in one statement; for subscriptions read_at takes the new time too while it is still
     * the subscription's own moment (the database's default), else it keeps the site time a visit wrote.
     *
     * @param  array{checked: list<int>, rows: array<int, string>}  $changes
     */
    private static function write(string $table, string $column, array $changes): int
    {
        if ($changes['rows'] === []) {
            return 0;
        }

        $grammar = DB::getQueryGrammar();
        $col = $grammar->wrap($column);
        $id = $grammar->wrap('id');
        $cases = [];
        $caseBindings = [];

        foreach ($changes['rows'] as $rowId => $time) {
            $cases[] = 'WHEN ? THEN ?';
            array_push($caseBindings, $rowId, $time);
        }

        $case = 'CASE '.$id.' '.implode(' ', $cases).' ELSE '.$col.' END';
        $ids = array_keys($changes['rows']);
        $set = [];
        $bindings = [];

        if ($table === 'subscriptions') {
            // assigned first: MySQL reads the columns of the later assignments after the earlier ones were set
            $readAt = $grammar->wrap('read_at');
            $set[] = $readAt.' = CASE WHEN '.$readAt.' = '.$col.' THEN '.$case.' ELSE '.$readAt.' END';
            array_push($bindings, ...$caseBindings);
        }

        $set[] = $col.' = '.$case;
        array_push($bindings, ...$caseBindings);

        $sql = 'UPDATE '.$grammar->wrapTable($table).' SET '.implode(', ', $set)
            .' WHERE '.$id.' IN ('.implode(', ', array_fill(0, count($ids), '?')).')';

        return DB::update($sql, [...$bindings, ...$ids]);
    }

    /**
     * The column is done: noted as finished, with one line in Tools > Logs > Admin actions when it had rows (how many
     * were put right and the zone, or that none needed it).
     */
    private static function finish(string $name, string $table, string $zone, bool $extra): void
    {
        $updated = DB::table(self::TABLE)->where('name', $name)->whereNull('finished_at')->update(['finished_at' => now(), 'updated_at' => now()]);

        if ($updated !== 1) {
            return;
        }

        $state = DB::table(self::TABLE)->where('name', $name)->first($extra ? ['changed', 'checked', 'boundary'] : ['changed', 'boundary']);
        $changed = (int) ($state->changed ?? 0);
        // a progress table without the count (an earlier build of 1.2.3): the noted rows still there
        $checked = $extra ? (int) ($state->checked ?? 0) : (int) self::candidates($table)->where($table.'.id', '<=', (int) ($state->boundary ?? 0))->count();

        if ($changed < 1 && $checked < 1) {
            return;
        }

        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => self::auditDetails($table, $changed, $checked, $zone),
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable) {
            // the log line is a courtesy; the rows are right
        }
    }

    /**
     * Whether rows of this database can be put right: MySQL / MariaDB (the stored moment of a TIMESTAMP column) and
     * SQLite (UTC). Another database keeps them as they are.
     */
    private static function convertible(): bool
    {
        return self::mysql() || DB::connection()->getDriverName() === 'sqlite';
    }

    private static function mysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Whether $table keeps transactions: SQLite and InnoDB do (MyISAM does not). */
    private static function transactional(string $table): bool
    {
        if (isset(self::$transactional[$table])) {
            return self::$transactional[$table];
        }

        if (! self::mysql()) {
            return self::$transactional[$table] = true;
        }

        try {
            $engine = DB::selectOne(
                'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [DB::connection()->getTablePrefix().$table]
            )?->engine;
        } catch (Throwable) {
            $engine = null;
        }

        return self::$transactional[$table] = is_string($engine) && strcasecmp($engine, 'InnoDB') === 0;
    }
}
