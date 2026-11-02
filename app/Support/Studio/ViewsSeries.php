<?php

namespace App\Support\Studio;

use App\Models\Video;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The "Views per day" series of a video's Analytics page (member design 6.5; studio-1, studio-13, perf-2).
 *
 * One source per number (studio-12): the chart reads the daily totals (video_view_daily_stats, written in the site's
 * time zone by ViewService); the page's "Views" total stays videos.views, the same number as My videos, Video details
 * and the delete dialog.
 * - Periods: 7, 28 (default) or 90 days, or "Since upload". The chart starts at the LATER of the period's first day and
 *   the video's publish day (a video published 5 days ago shows 5 columns, one uploaded today shows one), and it always
 *   ends today; today's column is marked partial ("Today so far").
 * - At most 90 columns: days, else weeks (up to 90 weeks), else months.
 * - The note under the chart: when the daily totals add up to less than the video's total (daily figures started after
 *   the video, or were switched off for a while), it says from when the daily figures exist.
 * Two queries: the buckets of the range, and the first day + the sum of every daily row of the video.
 */
final class ViewsSeries
{
    public const PERIODS = ['7', '28', '90', 'all'];

    public const DEFAULT_PERIOD = '28';

    public const MAX_BUCKETS = 90;

    /**
     * @return array{
     *     period: string, unit: string, start: CarbonImmutable, end: CarbonImmutable, published: CarbonImmutable,
     *     startsAtPublish: bool, wentPublic: bool, buckets: list<array{key: string, start: CarbonImmutable, end: CarbonImmutable, views: int, partial: bool, label: string, title: string}>,
     *     total: int, max: int, top: int, ticks: list<int>, labels: list<array{index: int, text: string, position: float, class: string}>,
     *     firstStatDay: ?CarbonImmutable, dailySum: int, note: ?string
     * }
     */
    public static function build(Video $video, ?string $period = null, ?CarbonInterface $now = null): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
        $zone = ZoneLabel::zone();
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($zone)->startOfDay();
        $published = self::publishDay($video, $today);

        $start = $period === 'all' ? $published : $today->subDays(((int) $period) - 1);
        $startsAtPublish = $published->greaterThan($start) || $period === 'all';

        if ($published->greaterThan($start)) {
            $start = $published;
        }

        if ($start->greaterThan($today)) {
            $start = $today;
        }

        $days = (int) $start->diffInDays($today) + 1;
        $unit = $days <= self::MAX_BUCKETS ? 'day' : ($days <= self::MAX_BUCKETS * 7 ? 'week' : 'month');

        $rows = self::dailyRows($video, $start, $today);
        $buckets = self::buckets($start, $today, $unit, $rows);

        $total = array_sum(array_map(fn (array $b): int => $b['views'], $buckets));
        $max = $buckets === [] ? 0 : max(array_map(fn (array $b): int => $b['views'], $buckets));
        [$top, $ticks] = self::scale($max);

        [$firstStatDay, $dailySum] = self::dailyTotals($video);
        $note = null;
        $lifetime = (int) ($video->views ?? 0);

        if ($lifetime > $dailySum) {
            $note = $firstStatDay !== null
                ? __('Daily figures start on :date. The total above also counts views from before that day.', ['date' => StudioFormat::date($firstStatDay)])
                : __('There are no daily figures for this video yet. The total above counts every view.');
        }

        return [
            'period' => $period,
            'unit' => $unit,
            'start' => $start,
            'end' => $today,
            'published' => $published,
            'startsAtPublish' => $startsAtPublish,
            'wentPublic' => self::wentPublic($video, $now),
            'buckets' => $buckets,
            'total' => $total,
            'max' => $max,
            'top' => $top,
            'ticks' => $ticks,
            'labels' => self::labels($buckets, $unit, $today),
            'firstStatDay' => $firstStatDay,
            'dailySum' => $dailySum,
            'note' => $note,
        ];
    }

    /**
     * The day the video went public (else uploaded, else created), in the site's zone, never after today. A scheduled
     * video keeps its scheduled time in published_at until it goes public (UpdateVideoAction): until then it has no
     * publish day (1.2.3 round 2, R2A-009), and its chart starts the day it was uploaded.
     */
    public static function publishDay(Video $video, ?CarbonInterface $today = null): CarbonImmutable
    {
        $zone = ZoneLabel::zone();
        $today = CarbonImmutable::instance($today ?? now())->setTimezone($zone)->startOfDay();
        $raw = (self::wentPublic($video) ? $video->published_at : null) ?? $video->uploaded_at ?? $video->created_at ?? $today;
        $day = CarbonImmutable::instance($raw)->setTimezone($zone)->startOfDay();

        return $day->greaterThan($today) ? $today : $day;
    }

    /** Whether the video has gone public: it has a publish time that has come (a scheduled one's is still ahead). */
    public static function wentPublic(Video $video, ?CarbonInterface $now = null): bool
    {
        return $video->published_at !== null && $video->published_at->lessThanOrEqualTo($now ?? now());
    }

    /**
     * Views of one video in a range of days and in the same number of days before it (the "Last 7 days" tile).
     *
     * @return array{0: int, 1: int}
     */
    public static function lastDays(Video $video, int $days, ?CarbonInterface $now = null): array
    {
        $zone = ZoneLabel::zone();
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($zone)->startOfDay();
        $from = $today->subDays($days - 1);
        $before = $from->subDays($days);

        try {
            $row = DB::table('video_view_daily_stats')
                ->where('video_id', $video->getKey())
                ->whereBetween('view_date', [$before->toDateString(), $today->toDateString()])
                ->selectRaw('SUM(CASE WHEN view_date >= ? THEN views_count ELSE 0 END) AS recent', [$from->toDateString()])
                ->selectRaw('SUM(CASE WHEN view_date < ? THEN views_count ELSE 0 END) AS earlier', [$from->toDateString()])
                ->first();
        } catch (Throwable) {
            return [0, 0];
        }

        return [(int) ($row->recent ?? 0), (int) ($row->earlier ?? 0)];
    }

    /** @return array<string, int> view_date => views */
    private static function dailyRows(Video $video, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            return DB::table('video_view_daily_stats')
                ->where('video_id', $video->getKey())
                ->whereBetween('view_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('view_date')
                ->selectRaw('view_date, SUM(views_count) AS views')
                ->pluck('views', 'view_date')
                ->mapWithKeys(fn ($views, $date): array => [substr((string) $date, 0, 10) => (int) $views])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{0: ?CarbonImmutable, 1: int} */
    private static function dailyTotals(Video $video): array
    {
        try {
            $row = DB::table('video_view_daily_stats')
                ->where('video_id', $video->getKey())
                ->selectRaw('MIN(view_date) AS first_day, SUM(views_count) AS views')
                ->first();
        } catch (Throwable) {
            return [null, 0];
        }

        $first = $row?->first_day ? CarbonImmutable::parse(substr((string) $row->first_day, 0, 10), ZoneLabel::zone()) : null;

        return [$first, (int) ($row->views ?? 0)];
    }

    /**
     * @param  array<string, int>  $rows
     * @return list<array{key: string, start: CarbonImmutable, end: CarbonImmutable, views: int, partial: bool, label: string, title: string}>
     */
    private static function buckets(CarbonImmutable $start, CarbonImmutable $today, string $unit, array $rows): array
    {
        $out = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($today)) {
            $end = match ($unit) {
                'week' => $cursor->addDays(6),
                'month' => $cursor->endOfMonth()->startOfDay(),
                default => $cursor,
            };

            if ($end->greaterThan($today)) {
                $end = $today;
            }

            $views = 0;

            for ($d = $cursor; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
                $views += $rows[$d->toDateString()] ?? 0;
            }

            $partial = $end->isSameDay($today);
            $label = match ($unit) {
                'month' => $cursor->locale(app()->getLocale())->translatedFormat('M Y'),
                default => ($unit === 'day' && $partial) ? __('Today') : StudioFormat::shortDate($cursor),
            };
            $when = match (true) {
                $unit === 'day' && $partial => __('Today so far'),
                $unit === 'day' => StudioFormat::shortDate($cursor),
                $unit === 'week' => __(':from – :to', ['from' => StudioFormat::shortDate($cursor), 'to' => $partial ? __('today') : StudioFormat::shortDate($end)]),
                default => $label,
            };

            $out[] = [
                'key' => $cursor->toDateString(),
                'start' => $cursor,
                'end' => $end,
                'views' => $views,
                'partial' => $partial,
                'label' => $label,
                'title' => trans_choice(':when: :count view|:when: :count views', $views, ['when' => $when, 'count' => StudioFormat::number($views)]),
            ];

            $cursor = $end->addDay();
        }

        return $out;
    }

    /**
     * A round top for the y axis and its 5 gridline values (0, 1/4 ... top).
     *
     * @return array{0: int, 1: list<int>}
     */
    public static function scale(int $max): array
    {
        if ($max <= 0) {
            return [4, [0, 1, 2, 3, 4]];
        }

        $raw = $max / 4;
        $magnitude = 10 ** (int) floor(log10($raw));
        $step = $magnitude;

        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($factor * $magnitude >= $raw) {
                $step = $factor * $magnitude;
                break;
            }
        }

        $step = max(1, (int) ceil($step));
        $top = $step * 4;

        return [$top, [0, $step, $step * 2, $step * 3, $top]];
    }

    /**
     * 1-6 date labels under the plot: the first, the last and evenly spaced ones between them. On phones only the
     * first, the middle one and the last show (the others carry is-extra).
     *
     * @param  list<array<string, mixed>>  $buckets
     * @return list<array{index: int, text: string, position: float, class: string}>
     */
    private static function labels(array $buckets, string $unit, CarbonImmutable $today): array
    {
        $n = count($buckets);

        if ($n === 0) {
            return [];
        }

        if ($n === 1) {
            return [['index' => 0, 'text' => $buckets[0]['label'], 'position' => 50.0, 'class' => '']];
        }

        $wanted = $n <= 6 ? range(0, $n - 1) : array_values(array_unique(array_map(fn (int $i): int => (int) round($i * ($n - 1) / 5), range(0, 5))));
        $middle = $wanted[intdiv(count($wanted) - 1, 2)];
        $out = [];

        foreach ($wanted as $index) {
            $class = match (true) {
                $index === 0 => 'is-start',
                $index === $n - 1 => 'is-end',
                default => '',
            };

            if ($index !== 0 && $index !== $n - 1 && $index !== $middle) {
                $class = trim($class.' is-extra');
            }

            $out[] = [
                'index' => $index,
                'text' => $buckets[$index]['label'],
                'position' => round(($index + .5) * 100 / $n, 3),
                'class' => $class,
            ];
        }

        return $out;
    }
}
