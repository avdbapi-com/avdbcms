<?php

namespace App\Services;

use App\Services\Concerns\BuildsRecentPublicVideoQuery;
use App\Support\BoundedConfig;
use App\Support\DiscoverySnapshots;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdaptiveTrendingCollectionsService
{
    use BuildsRecentPublicVideoQuery;

    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_LIMIT = 16;
    public const int DEFAULT_LOOKBACK_DAYS = 30;
    public const int MIN_LIMIT = 1;
    public const int MAX_LIMIT = 40;
    public const int MIN_LOOKBACK_DAYS = 1;
    public const int MAX_LOOKBACK_DAYS = 90;

    /** How long the rail's actors are current (seconds): 10 minutes, as since 1.2.0. */
    public const int CACHE_SECONDS = 600;

    public static function settingsDefaults(): array
    {
        return [
            'enabled' => self::DEFAULT_ENABLED ? '1' : '0',
            'limit' => self::DEFAULT_LIMIT,
            'limit_min' => self::MIN_LIMIT,
            'limit_max' => self::MAX_LIMIT,
            'lookback_days' => self::DEFAULT_LOOKBACK_DAYS,
            'lookback_days_min' => self::MIN_LOOKBACK_DAYS,
            'lookback_days_max' => self::MAX_LOOKBACK_DAYS,
        ];
    }

    /**
     * The actors of the videos viewed most in the window, exactly as counted since 1.2.0, current for 10 minutes: counted
     * by the page where the window holds few view rows, else by the scheduler's task in the background
     * (App\Support\DiscoverySnapshots; a busy site's 30 days of views take seconds to count).
     */
    public function collections(): Collection
    {
        if (! config('site.trending_collections_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $params = [
            'v' => 3,
            'locale' => app()->getLocale(),
            'limit' => BoundedConfig::integer('site.trending_collections_limit', self::DEFAULT_LIMIT, self::MIN_LIMIT, self::MAX_LIMIT),
            'lookback_days' => BoundedConfig::integer('site.trending_collections_lookback_days', self::DEFAULT_LOOKBACK_DAYS, self::MIN_LOOKBACK_DAYS, self::MAX_LOOKBACK_DAYS),
        ];

        $actors = DiscoverySnapshots::answer('actors', $params, self::CACHE_SECONDS, function () use ($params): array {
            $since = now()->subDays($params['lookback_days']);

            return $this->viewWindow($since)['few'] ? [$this->exactCollections($params, $since), true] : [collect(), false];
        });

        // 1.2.3 refresh 2: an actor named with a word that is always blocked is never offered (App\Support\ContentSafety;
        // filtered after the answer was read, as the categories are, so a kept answer needs no new count)
        return ($actors instanceof Collection ? $actors : collect($actors))
            ->reject(fn ($row): bool => \App\Support\ContentSafety::hides(is_object($row)
                ? (string) ($row->actor_name ?? '')
                : (is_array($row) ? (string) ($row['actor_name'] ?? '') : '')))
            ->values();
    }

    /**
     * The rail's actors for $params (locale, limit, lookback_days), 1.2.2's way: the actors of the active videos (in the
     * language's rule, not in the bin) viewed since the window's start, by their views, then their videos, then name.
     *
     * @param  array<string, scalar>  $params
     * @param  CarbonInterface|null  $since  the window's start (now minus its days by default)
     */
    public function exactCollections(array $params, ?CarbonInterface $since = null): Collection
    {
        return $this
            ->recentActorViewCounts($since ?? now()->subDays(max(1, (int) ($params['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS))))
            ->orderByDesc('trending_collections_views_count')
            ->orderByDesc('trending_collections_videos_count')
            ->orderBy('actor_name')
            ->limit(max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)))
            ->get();
    }

    private function recentActorViewCounts(CarbonInterface $since): Builder
    {
        if ($this->shouldUseDailyStats($since)) {
            $query = DB::table('video_view_daily_stats')
                ->join('videos', 'videos.id', '=', 'video_view_daily_stats.video_id')
                ->whereNotNull('videos.avdb_actor')
                ->whereRaw("TRIM(videos.avdb_actor) <> ''")
                ->where('video_view_daily_stats.view_date', '>=', $since->toDateString());

            $this->applyJoinedActiveLocalizedVideoFilters($query);

            return $query
                ->selectRaw('TRIM(videos.avdb_actor) as actor_name')
                ->selectRaw('SUM(video_view_daily_stats.views_count) as trending_collections_views_count')
                ->selectRaw('COUNT(DISTINCT videos.id) as trending_collections_videos_count')
                ->groupByRaw('TRIM(videos.avdb_actor)');
        }

        $query = DB::table('views')
            ->join('videos', 'videos.id', '=', 'views.video_id')
            ->whereNotNull('videos.avdb_actor')
            ->whereRaw("TRIM(videos.avdb_actor) <> ''")
            ->whereNull('views.deleted_at')
            ->where('views.view_at', '>=', $since);

        $this->applyJoinedActiveLocalizedVideoFilters($query);

        return $query
            ->selectRaw('TRIM(videos.avdb_actor) as actor_name')
            ->selectRaw('COUNT(views.id) as trending_collections_views_count')
            ->selectRaw('COUNT(DISTINCT videos.id) as trending_collections_videos_count')
            ->groupByRaw('TRIM(videos.avdb_actor)');
    }

    /** @var array<string, array{daily: bool, few: bool}> viewWindow() of this instance, per window start */
    private array $viewWindows = [];

    /**
     * The window's view rows: whether they are the daily view counts (any since the window's start; else each view, as
     * since 1.2.0) and whether there are few enough of them for the page to count them itself (at most
     * DiscoverySnapshots::INLINE_ROWS): capped counts of the (date, video) / (time, id) index alone.
     *
     * @return array{daily: bool, few: bool}
     */
    private function viewWindow(CarbonInterface $since): array
    {
        $key = $since->format('Y-m-d H:i:s');

        if (isset($this->viewWindows[$key])) {
            return $this->viewWindows[$key];
        }

        $cap = DiscoverySnapshots::inlineRows() + 1;
        $daily = Schema::hasTable('video_view_daily_stats')
            ? (int) DB::query()->fromSub(DB::table('video_view_daily_stats')->select('video_view_daily_stats.id')
                ->where('video_view_daily_stats.view_date', '>=', $since->toDateString())->limit($cap), 'avd_window')->count()
            : 0;

        if ($daily > 0) {
            return $this->viewWindows[$key] = ['daily' => true, 'few' => $daily <= DiscoverySnapshots::inlineRows()];
        }

        $views = (int) DB::query()->fromSub(DB::table('views')->select('views.id')->where('views.view_at', '>=', $since)->limit($cap), 'avd_window')->count();

        return $this->viewWindows[$key] = ['daily' => false, 'few' => $views <= DiscoverySnapshots::inlineRows()];
    }

    private function shouldUseDailyStats(CarbonInterface $since): bool
    {
        return $this->viewWindow($since)['daily'];
    }
}
