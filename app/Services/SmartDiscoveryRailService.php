<?php

namespace App\Services;

use App\Models\Tag;
use App\Services\Concerns\BuildsRecentPublicVideoQuery;
use App\Support\BoundedConfig;
use App\Support\DiscoverySnapshots;
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

    /** How long the rail's tags are current (seconds): 10 minutes, as since 1.2.0. */
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
     * The tags with the most videos of the window (Discovery Window Days), exactly as counted since 1.2.0, current for 10
     * minutes: counted by the page where the window holds few videos, else by the scheduler's task in the background
     * (App\Support\DiscoverySnapshots; at 300,000 videos the count takes seconds). Before the task's first count a big
     * site's Home shows its categories in the rail's place.
     */
    public function tags(): Collection
    {
        if (!config('site.smart_discovery_rail_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $params = [
            // v7 (1.2.3): without the tags named with a word that is always blocked (App\Support\ContentSafety)
            'v' => 7,
            'locale' => app()->getLocale(),
            'limit' => BoundedConfig::integer('site.smart_discovery_rail_limit', self::DEFAULT_LIMIT, self::MIN_LIMIT, self::MAX_LIMIT),
            'lookback_days' => BoundedConfig::integer('site.smart_discovery_rail_lookback_days', self::DEFAULT_LOOKBACK_DAYS, self::MIN_LOOKBACK_DAYS, self::MAX_LOOKBACK_DAYS),
        ];

        return DiscoverySnapshots::answer('tags', $params, self::CACHE_SECONDS, fn (): array => $this->recentWindowIsSmall(now()->subDays($params['lookback_days']))['window']
            ? [$this->exactTags($params), true]
            : [collect(), false]);
    }

    /**
     * The rail's tags for $params (locale, limit, lookback_days): every active video of the window in the language's
     * rule, not in the bin, published or scheduled since the window's start, counted per tag; the most first, then by
     * name; a tag named with a word that is always blocked left out. 1.2.2's count, started from the window's videos
     * (recentWindowIds(), windowFirst()): 1.2.2's statement read every tag of every video on MySQL 8 (7-17 s at 300,000
     * videos whatever the window held); this one 0.2 s for a window of 6,000 videos, 3.6 s for 185,000 (MariaDB 0.06 s,
     * 1.2-1.9 s).
     *
     * @param  array<string, scalar>  $params
     */
    public function exactTags(array $params): Collection
    {
        $locale = (string) ($params['locale'] ?? app()->getLocale());
        $since = now()->subDays(max(1, (int) ($params['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS)));

        // the count per tag, from the window's videos (their rows, then their tags' ids)
        $counts = DB::query()
            ->fromSub($this->recentWindowIds($since), 'avd_window')
            ->join('videos', 'videos.id', '=', 'avd_window.avd_window_id')
            ->join('video_tags', 'video_tags.video_id', '=', 'videos.id');

        $this->applyJoinedActiveLocalizedVideoFilters($counts);
        $this->recentWindowCondition($counts, $since);

        $counts->select($this->windowFirst('video_tags.tag_id'))
            ->selectRaw('COUNT(DISTINCT videos.id) as smart_discovery_videos_count')
            ->groupBy('video_tags.tag_id');

        // then the tags: the most first, by name among equal counts
        $query = DB::table('tags')
            ->joinSub($counts, 'avd_counts', 'avd_counts.tag_id', '=', 'tags.id')
            ->select('tags.*', 'avd_counts.smart_discovery_videos_count')
            ->orderByDesc('avd_counts.smart_discovery_videos_count')
            ->orderBy('tags.name')
            ->limit(max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));

        $tags = Tag::query()->fromSub($query, 'tags');
        // Tag::localized_name reads the translation of the current locale (English and the fallback locale need
        // none): loaded with the tags, not one query per tag card.
        if ($locale !== 'en' && $locale !== config('app.fallback_locale', 'en')) {
            $tags->with(['translations' => fn ($query) => $query->where('locale', $locale)]);
        }
        $tags = \App\Support\ContentSafety::visibleTags($tags->get());

        return $tags->each(fn (Tag $tag) => $tag->setAttribute('smart_discovery_videos_count', (int) $tag->smart_discovery_videos_count));
    }

    public function categories(): Collection
    {
        return $this->tags();
    }
}
