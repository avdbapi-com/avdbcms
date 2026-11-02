<?php

namespace App\Support\Studio;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The studio dashboard's period and figures (member design 6.2; studio-12, studio-29).
 *
 * One source per number, the same as Analytics: Views = the daily totals of the member's videos in the period
 * (video_view_daily_stats), with the change on the period before; New subscribers, Comments and Likes = the rows of the
 * period. Each tile's sparkline is the same daily data in at most 14 points, so it adds up to the tile. Four grouped
 * queries (plus the subscriber total), cached for 5 minutes per member and period. With the admin's "Daily view totals"
 * off, Views is every view the videos ever had (videos.views) and there is no sparkline. The lists and boxes are cached
 * the same 5 minutes (widgets(); studio-29: a returning member's dashboard needs a handful of queries, also after
 * people watched the member's videos). Every entry is one fixed key per member holding plain values.
 */
final class Dashboard
{
    public const PERIODS = ['7', '28', '90', 'custom'];

    public const DEFAULT_PERIOD = '28';

    public const CACHE_SECONDS = 300;

    /**
     * The period of the dashboard from the query: 7 / 28 / 90 days ending today, or custom days (from / to, Y-m-d,
     * at most 366 days, never after today).
     *
     * @return array{key: string, start: CarbonImmutable, end: CarbonImmutable, days: int, label: string, from: string, to: string}
     */
    public static function period(?string $key, mixed $from = null, mixed $to = null, ?CarbonImmutable $now = null): array
    {
        $zone = ZoneLabel::zone();
        $today = ($now ?? CarbonImmutable::now($zone))->setTimezone($zone)->startOfDay();
        $key = in_array($key, self::PERIODS, true) ? $key : self::DEFAULT_PERIOD;

        if ($key === 'custom') {
            $start = self::day($from);
            $end = self::day($to);

            if ($start === null || $end === null) {
                $key = self::DEFAULT_PERIOD;
            } else {
                if ($end->greaterThan($today)) {
                    $end = $today;
                }

                if ($start->greaterThan($end)) {
                    [$start, $end] = [$end, $start];
                }

                if ((int) $start->diffInDays($end) > 365) {
                    $start = $end->subDays(365);
                }
            }
        }

        if ($key !== 'custom') {
            $end = $today;
            $start = $today->subDays(((int) $key) - 1);
        }

        $days = (int) $start->diffInDays($end) + 1;

        return [
            'key' => $key,
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'label' => $start->year === $end->year
                ? __(':from – :to', ['from' => StudioFormat::shortDate($start), 'to' => StudioFormat::date($end)])
                : __(':from – :to', ['from' => StudioFormat::date($start), 'to' => StudioFormat::date($end)]),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }

    /**
     * The member changed something on a studio page (a video saved, deleted or made public, a playlist, a removed
     * comment): the cached dashboard (figures and lists) is built again on the next view instead of showing the old
     * state for up to 5 minutes. A version number per member, part of every stamp below.
     */
    public static function forget(User|int|null $user): void
    {
        $id = $user instanceof User ? (int) $user->getKey() : (int) $user;

        if ($id <= 0) {
            return;
        }

        try {
            Cache::forever(self::versionKey($id), (string) hrtime(true));
        } catch (Throwable) {
            // no cache: nothing was kept either
        }
    }

    /**
     * The member's videos as the dashboard's cache sees them (one grouped query, the dashboard's first): how many, the
     * newest, the sum of their statuses, how many finished uploading and how many went public. An upload, a removal, a
     * status change (published, failed, banned, scheduled) or a finished upload renews the cache at once; a counted view
     * does not (it only moves views and updated_at: before, any view of any of the member's videos renewed it, so a
     * returning member ran every query again and each view left a new entry behind).
     *
     * @return array{total: int, newest: int, statuses: int, uploaded: int, published: int}
     */
    public static function videosStamp(User $user): array
    {
        $row = Video::query()
            ->where('user_id', $user->getKey())
            ->toBase()
            ->selectRaw('COUNT(*) AS total, MAX(id) AS newest, SUM(status) AS statuses, COUNT(uploaded_at) AS uploaded, COUNT(published_at) AS published')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'newest' => (int) ($row->newest ?? 0),
            'statuses' => (int) ($row->statuses ?? 0),
            'uploaded' => (int) ($row->uploaded ?? 0),
            'published' => (int) ($row->published ?? 0),
        ];
    }

    /**
     * The dashboard's lists and boxes (latest videos, comments, subscribers, "Needs your attention", tips), built once
     * per member for 5 minutes (6.2, studio-29), in ONE cache entry per member that each rebuild overwrites (a file
     * store keeps one file per key: a key per state left a new file behind on every change, never read again). The
     * entry's stamp: the member's videos stamp (videosStamp()), the version the member's own changes bump (forget()),
     * the settings that shape the lists, and the language, address and time zone the texts and links are made for.
     * Comments, likes and subscribers of OTHER people show within 5 minutes. $build must return plain values only (no
     * model, no users row: the cache is a file on the server).
     *
     * @param  array<string, int>  $videos
     * @param  array<string, mixed>  $settings
     * @param  \Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function widgets(User $user, array $videos, array $settings, \Closure $build): array
    {
        $id = (int) $user->getKey();

        return self::entry('avd:studio:dash:w:'.$id, [
            $videos,
            self::version($id),
            $settings,
            app()->getLocale(),
            request()->getSchemeAndHttpHost().request()->getBaseUrl(),
            ZoneLabel::zone()->getName(),
        ], $build);
    }

    /**
     * One fixed cache entry holding [stamp, data]: the data while the stamp is the same and the entry younger than 5
     * minutes, else $build() written over the same entry.
     *
     * @param  array<int, mixed>  $parts
     * @param  \Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private static function entry(string $key, array $parts, \Closure $build): array
    {
        $stamp = md5((string) json_encode($parts));

        try {
            $entry = Cache::get($key);

            if (is_array($entry) && ($entry['stamp'] ?? null) === $stamp && is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        } catch (Throwable) {
            return $build();
        }

        $data = $build();

        try {
            Cache::put($key, ['stamp' => $stamp, 'data' => $data], self::CACHE_SECONDS);
        } catch (Throwable) {
            // no cache: the next view builds them again
        }

        return $data;
    }

    private static function version(int $id): string
    {
        try {
            return (string) Cache::get(self::versionKey($id), '0');
        } catch (Throwable) {
            return '0';
        }
    }

    private static function versionKey(int $id): string
    {
        return 'avd:studio:dash:v:'.$id;
    }

    /**
     * The figures of a period (plain numbers), in one cache entry per member and period kind (7, 28, 90 days, custom)
     * that each rebuild overwrites: a new day or other custom dates never leave an entry behind.
     *
     * @param  array{key: string, start: CarbonImmutable, end: CarbonImmutable, days: int}  $period
     * @return array<string, mixed>
     */
    public static function figures(User $user, array $period): array
    {
        $daily = StudioSettings::dailyStatsEnabled();
        $id = (int) $user->getKey();

        return self::entry('avd:studio:dash:f:'.$id.':'.$period['key'], [
            $period['start']->toDateString(),
            $period['end']->toDateString(),
            $daily,
            self::version($id),
        ], fn (): array => self::compute($user, $period, $daily));
    }

    /** @return array<string, mixed> */
    private static function compute(User $user, array $period, bool $daily): array
    {
        $start = $period['start'];
        $end = $period['end'];
        $days = $period['days'];
        $prevStart = $start->subDays($days);
        $endExclusive = $end->addDay();
        $id = (int) $user->getKey();

        // 1.2.3 round 1 (R1-074): the five grouped queries of the figures go in ONE round trip (a UNION ALL of the same
        // queries, each row tagged with its figure), so a cold dashboard stays within the 4.3 budget of 20 queries.
        $byKind = ['v' => [], 's' => [], 'c' => [], 'l' => []];
        $totals = ['t' => 0, 'w' => 0];
        $dislikes = 0;

        foreach (self::figureRows(self::figureQueries($id, $start, $prevStart, $end, $endExclusive, $daily)) as $row) {
            $kind = (string) $row->k;

            if (isset($totals[$kind])) {
                $totals[$kind] = (int) $row->a;

                continue;
            }

            if (! isset($byKind[$kind])) {
                continue;
            }

            $byKind[$kind][substr((string) $row->d, 0, 10)] = (int) $row->a;

            if ($kind === 'l') {
                $dislikes += (int) $row->b;
            }
        }

        // Views: the daily totals of the member's videos, the period and the one before it (or every view ever, without
        // daily figures)
        $views = [];
        $viewsPrev = 0;
        $lifetime = $daily ? null : $totals['w'];

        if ($daily) {
            [$views, $viewsPrev] = self::split($byKind['v'], $start, $end);
        }

        // New subscribers in the period and before it, and the total
        [$subs, $subsPrev] = self::split($byKind['s'], $start, $end);
        $subsTotal = $totals['t'];

        // Comments on the member's videos (by anyone, replies included; R1-165: the public ones, as viewers see them)
        [$comments, $commentsPrev] = self::split($byKind['c'], $start, $end);

        // Likes and dislikes of the member's videos in the period
        [$likes] = self::split($byKind['l'], $start, $end);

        $viewsTotal = $daily ? array_sum($views) : (int) $lifetime;
        $likesTotal = array_sum($likes);

        return [
            'daily' => $daily,
            'views' => $viewsTotal,
            'viewsDelta' => $daily ? StudioFormat::delta($viewsTotal, $viewsPrev) : null,
            'viewsSpark' => $daily ? self::spark($views) : [],
            'subs' => array_sum($subs),
            'subsTotal' => $subsTotal,
            'subsSpark' => self::spark($subs),
            'comments' => array_sum($comments),
            'commentsPrev' => $commentsPrev,
            'commentsSpark' => self::spark($comments),
            'likes' => $likesTotal,
            'likesPct' => StudioFormat::percent($likesTotal, $likesTotal + $dislikes),
            'likesSpark' => self::spark($likes),
        ];
    }

    /**
     * The figures' queries, each row [k, d, a, b]: k the figure (v views per day, w every view ever, s new subscribers per
     * day, t subscribers in total, c comments per day, l likes per day with b the dislikes), d the day (Y-m-d, NULL for a
     * total), a the number. The same conditions as before 1.2.3 round 1, one query each; comments are the public ones
     * (not hidden by a moderator, R1-165), as the watch page counts them.
     *
     * @return list<\Illuminate\Database\Query\Builder>
     */
    private static function figureQueries(int $id, CarbonImmutable $start, CarbonImmutable $prevStart, CarbonImmutable $end, CarbonImmutable $endExclusive, bool $daily): array
    {
        $parts = [];

        $parts[] = $daily
            ? DB::table('video_view_daily_stats')
                ->join('videos', 'videos.id', '=', 'video_view_daily_stats.video_id')
                ->where('videos.user_id', $id)
                ->whereNull('videos.deleted_at')
                ->whereBetween('video_view_daily_stats.view_date', [$prevStart->toDateString(), $end->toDateString()])
                ->groupBy('video_view_daily_stats.view_date')
                ->selectRaw("'v' AS k, video_view_daily_stats.view_date AS d, SUM(video_view_daily_stats.views_count) AS a, 0 AS b")
            : DB::table('videos')
                ->where('user_id', $id)
                ->whereNull('deleted_at')
                ->selectRaw("'w' AS k, NULL AS d, COALESCE(SUM(views), 0) AS a, 0 AS b");

        $parts[] = DB::table('subscriptions')
            ->where('user_id', $id)
            ->where('subscribe_at', '>=', $prevStart)
            ->where('subscribe_at', '<', $endExclusive)
            ->groupByRaw('DATE(subscribe_at)')
            ->selectRaw("'s' AS k, DATE(subscribe_at) AS d, COUNT(*) AS a, 0 AS b");

        $parts[] = DB::table('subscriptions')
            ->where('user_id', $id)
            ->selectRaw("'t' AS k, NULL AS d, COUNT(*) AS a, 0 AS b");

        $parts[] = DB::table('comments')
            ->join('videos', 'videos.id', '=', 'comments.video_id')
            ->where('videos.user_id', $id)
            ->whereNull('comments.deleted_at')
            ->whereNull('comments.banned_at')
            ->where('comments.created_at', '>=', $prevStart)
            ->where('comments.created_at', '<', $endExclusive)
            ->groupByRaw('DATE(comments.created_at)')
            ->selectRaw("'c' AS k, DATE(comments.created_at) AS d, COUNT(*) AS a, 0 AS b");

        $parts[] = DB::table('interactions')
            ->join('videos', 'videos.id', '=', 'interactions.likeable_id')
            ->where('interactions.likeable_type', (new Video())->getMorphClass())
            ->where('videos.user_id', $id)
            ->where('interactions.perform_at', '>=', $start)
            ->where('interactions.perform_at', '<', $endExclusive)
            ->groupByRaw('DATE(interactions.perform_at)')
            ->selectRaw("'l' AS k, DATE(interactions.perform_at) AS d, SUM(CASE WHEN interactions.status = 1 THEN 1 ELSE 0 END) AS a, SUM(CASE WHEN interactions.status = 0 THEN 1 ELSE 0 END) AS b");

        return $parts;
    }

    /**
     * The rows of the figures' queries in one round trip (UNION ALL). When that fails (a table of an old database), each
     * query runs on its own and a failing one counts nothing, as each one did before.
     *
     * @param  list<\Illuminate\Database\Query\Builder>  $parts
     * @return list<object>
     */
    private static function figureRows(array $parts): array
    {
        try {
            $union = clone $parts[0];

            foreach (array_slice($parts, 1) as $part) {
                $union->unionAll($part);
            }

            return $union->get()->all();
        } catch (Throwable) {
            $rows = [];

            foreach ($parts as $part) {
                $rows = array_merge($rows, self::safe(fn () => $part->get()->all(), []));
            }

            return $rows;
        }
    }

    /**
     * The days of the period in order (0 for a day without rows) and the sum of the days before it.
     *
     * @param  array<string, mixed>  $rows  day (Y-m-d) => count
     * @return array{0: list<int>, 1: int}
     */
    private static function split(array $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byDay = [];

        foreach ($rows as $day => $count) {
            $byDay[substr((string) $day, 0, 10)] = (int) $count;
        }

        $series = [];
        $before = 0;
        $startKey = $start->toDateString();

        foreach ($byDay as $day => $count) {
            if ($day < $startKey) {
                $before += $count;
            }
        }

        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $series[] = $byDay[$d->toDateString()] ?? 0;
        }

        return [$series, $before];
    }

    /**
     * The sparkline's points: the days summed in groups, at most 14 points, adding up to the tile.
     *
     * @param  list<int>  $days
     * @return list<int>
     */
    public static function spark(array $days): array
    {
        $n = count($days);

        if ($n === 0) {
            return [];
        }

        $size = max(1, (int) ceil($n / 14));
        $points = [];

        for ($i = 0; $i < $n; $i += $size) {
            $points[] = array_sum(array_slice($days, $i, $size));
        }

        return $points;
    }

    /** The SVG points of a sparkline (200 x 34, as the kit draws it). @param list<int> $points */
    public static function sparkPaths(array $points, int $w = 200, int $h = 34): ?array
    {
        if (count($points) < 2) {
            return null;
        }

        $max = max(1, max($points));
        $step = $w / (count($points) - 1);
        $line = [];

        foreach ($points as $i => $value) {
            $line[] = round($i * $step, 1).','.round($h - ($value / $max) * ($h - 4) - 2, 1);
        }

        return ['line' => implode(' ', $line), 'area' => '0,'.$h.' '.implode(' ', $line).' '.$w.','.$h];
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value, ZoneLabel::zone())?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private static function safe(callable $query, mixed $fallback): mixed
    {
        try {
            return $query();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** The statuses the dashboard's "Latest videos" never lists (they are in "Needs your attention"). */
    public static function hiddenFromLatest(): array
    {
        return [VideoStatus::DRAFT->value, VideoStatus::FAILED->value];
    }
}
