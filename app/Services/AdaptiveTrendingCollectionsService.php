<?php

namespace App\Services;

use App\Services\Concerns\BuildsRecentPublicVideoQuery;
use App\Support\BoundedConfig;
use App\Support\SafeCache;
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

    public function collections(): Collection
    {
        if (! config('site.trending_collections_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = BoundedConfig::integer(
            'site.trending_collections_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );
        $lookbackDays = BoundedConfig::integer(
            'site.trending_collections_lookback_days',
            self::DEFAULT_LOOKBACK_DAYS,
            self::MIN_LOOKBACK_DAYS,
            self::MAX_LOOKBACK_DAYS
        );
        $locale = app()->getLocale();
        $cacheKey = "home:trending-collections:v3:{$locale}:{$limit}:{$lookbackDays}";

        $resolver = fn () => $this
            ->recentActorViewCounts(now()->subDays($lookbackDays))
            ->orderByDesc('trending_collections_views_count')
            ->orderByDesc('trending_collections_videos_count')
            ->orderBy('actor_name')
            ->limit($limit)
            ->get();

        return SafeCache::remember($cacheKey, now()->addMinutes(10), $resolver);
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

    private function shouldUseDailyStats(CarbonInterface $since): bool
    {
        return Schema::hasTable('video_view_daily_stats')
            && DB::table('video_view_daily_stats')
                ->where('view_date', '>=', $since->toDateString())
                ->exists();
    }
}
