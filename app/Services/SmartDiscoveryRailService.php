<?php

namespace App\Services;

use App\Models\Tag;
use App\Services\Concerns\BuildsRecentPublicVideoQuery;
use App\Support\BoundedConfig;
use App\Support\SafeCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartDiscoveryRailService
{
    use BuildsRecentPublicVideoQuery;

    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_LIMIT = 32;
    public const int DEFAULT_LOOKBACK_DAYS = 30;
    public const int MIN_LIMIT = 1;
    public const int MAX_LIMIT = 60;
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

    public function tags(): Collection
    {
        if (!config('site.smart_discovery_rail_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = BoundedConfig::integer(
            'site.smart_discovery_rail_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );

        $locale = app()->getLocale();
        $cacheKey = "home:smart-discovery-tags:v5:{$locale}:{$limit}";

        $resolver = function () use ($limit): Collection {
            $since = now()->subDays(BoundedConfig::integer(
                'site.smart_discovery_rail_lookback_days',
                self::DEFAULT_LOOKBACK_DAYS,
                self::MIN_LOOKBACK_DAYS,
                self::MAX_LOOKBACK_DAYS,
            ));

            $query = DB::table('tags')
                ->join('video_tags', 'video_tags.tag_id', '=', 'tags.id')
                ->join('videos', 'videos.id', '=', 'video_tags.video_id');

            $this->applyJoinedActiveLocalizedVideoFilters($query);

            $query->where(function (Builder $query) use ($since): void {
                $query->where('videos.published_at', '>=', $since)
                    ->orWhere('videos.scheduled_at', '>=', $since);
            });

            $query = $query
                ->select('tags.*')
                ->selectRaw('COUNT(DISTINCT videos.id) as smart_discovery_videos_count')
                ->groupBy('tags.id')
                ->orderByDesc('smart_discovery_videos_count')
                ->orderBy('tags.name')
                ->limit($limit);

            $tags = Tag::query()->fromSub($query, 'tags')->get();

            return $tags->each(fn (Tag $tag) => $tag->setAttribute('smart_discovery_videos_count', (int) $tag->smart_discovery_videos_count));
        };

        return SafeCache::remember($cacheKey, now()->addMinutes(10), $resolver);
    }

    public function categories(): Collection
    {
        return $this->tags();
    }
}
