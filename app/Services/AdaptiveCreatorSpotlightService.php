<?php

namespace App\Services;

use App\Models\User;
use App\Services\Concerns\BuildsRecentPublicVideoQuery;
use App\Support\BoundedConfig;
use App\Support\CachedUsers;
use App\Support\DiscoverySnapshots;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdaptiveCreatorSpotlightService
{
    use BuildsRecentPublicVideoQuery;

    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_LIMIT = 48;
    public const int DEFAULT_LOOKBACK_DAYS = 60;
    public const int MIN_LIMIT = 1;
    public const int MAX_LIMIT = 90;
    public const int MIN_LOOKBACK_DAYS = 1;
    public const int MAX_LOOKBACK_DAYS = 180;

    /** How long the rail's creators are current (seconds): 10 minutes, as since 1.2.0. */
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
     * The creators with the most videos of the window (and, when the window has too few, of every date), exactly as
     * counted since 1.2.0, current for 10 minutes: counted by the page where the window (and, when it is needed, the
     * whole catalogue) holds few videos, else by the scheduler's task in the background (App\Support\DiscoverySnapshots;
     * at 300,000 videos the count takes seconds). Before the task's first count a big site's Home shows its categories
     * in the rail's place.
     */
    public function creators(): Collection
    {
        if (! config('site.creator_spotlight_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $params = [
            'v' => 5,
            'locale' => app()->getLocale(),
            'limit' => BoundedConfig::integer('site.creator_spotlight_limit', self::DEFAULT_LIMIT, self::MIN_LIMIT, self::MAX_LIMIT),
            'lookback_days' => BoundedConfig::integer('site.creator_spotlight_lookback_days', self::DEFAULT_LOOKBACK_DAYS, self::MIN_LOOKBACK_DAYS, self::MAX_LOOKBACK_DAYS),
        ];

        return DiscoverySnapshots::answer('creators', $params, self::CACHE_SECONDS, function () use ($params): array {
            $small = $this->recentWindowIsSmall(now()->subDays($params['lookback_days']), true);

            if (! $small['window']) {
                return [collect(), false];
            }

            $creators = $this->exactCreators($params, ! $small['catalogue']);

            return $creators === null ? [collect(), false] : [$creators, true];
        });
    }

    /**
     * The rail's creators for $params (locale, limit, lookback_days), 1.2.2's way: the creators with the most active videos
     * of the window (in the language's rule, not in the bin), the most first; when fewer than the limit, of every date;
     * when still fewer, other active members. The cached creators keep only their public columns
     * (App\Support\CachedUsers). $notOfEveryDate: null instead of the count of every date (a big catalogue: the page
     * leaves it to the background).
     *
     * @param  array<string, scalar>  $params
     */
    public function exactCreators(array $params, bool $notOfEveryDate = false): ?Collection
    {
        $limit = max(1, (int) ($params['limit'] ?? self::DEFAULT_LIMIT));
        $since = now()->subDays(max(1, (int) ($params['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS)));

        $recentRows = $this->creatorVideoCounts($since, $limit * 3);
        $creators = $this->hydrateCreatorsFromCounts($recentRows, $limit);

        if ($creators->count() >= $limit) {
            return CachedUsers::publicOnly($creators);
        }

        if ($notOfEveryDate) {
            return null;
        }

        $fallbackRows = $this->creatorVideoCounts(null, $limit * 3);
        $fallbackCreators = $this->hydrateCreatorsFromCounts($fallbackRows, $limit);

        if ($fallbackCreators->count() >= $limit) {
            return CachedUsers::publicOnly($fallbackCreators);
        }

        // Fallback: If still under limit, include top active community members with any video or profile
        $existingIds = $fallbackCreators->pluck('id')->all();
        $moreUsers = User::query()
            ->active()
            ->whereNotIn('id', $existingIds)
            ->withCount('subscribers')
            ->take($limit - $fallbackCreators->count())
            ->get();

        // The cached creators keep only their public columns (App\Support\CachedUsers): the fallback members were whole
        // users rows (password hash, e-mail address, phone, sign-in address) in the cache file.
        return CachedUsers::publicOnly($fallbackCreators->merge($moreUsers)->values());
    }

    /**
     * The creators of the active videos published or scheduled since $since (of every date when null) with how many of
     * them each has, the most first (then by creator, so the same counts always give the same creators); a window's
     * count starts from its videos (recentWindowIds()).
     */
    private function creatorVideoCounts($since, int $limit): Collection
    {
        $query = $since === null
            ? DB::table('videos')
            : DB::query()->fromSub($this->recentWindowIds($since), 'avd_window')->join('videos', 'videos.id', '=', 'avd_window.avd_window_id');

        $query
            ->select($since === null ? 'videos.user_id' : $this->windowFirst('videos.user_id'))
            ->selectRaw('COUNT(*) as creator_spotlight_videos_count')
            ->whereNotNull('videos.user_id')
            ->whereNull('videos.deleted_at')
            ->groupBy('videos.user_id')
            ->orderByDesc('creator_spotlight_videos_count')
            ->orderBy('videos.user_id')
            ->limit($limit);

        $this->applyActiveLocalizedVideoFilters($query);

        if ($since) {
            $this->recentWindowCondition($query, $since);
        }

        return $query->get();
    }

    private function hydrateCreatorsFromCounts(Collection $rows, int $limit): Collection
    {
        $countsByUser = $rows
            ->pluck('creator_spotlight_videos_count', 'user_id')
            ->map(fn ($count) => (int) $count);

        if ($countsByUser->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->active()
            ->whereIn('id', $countsByUser->keys())
            ->withCount('subscribers')
            ->get([
                'id',
                'username',
                'slug',
                'avatar',
                'show_subscribers',
                'email_verified_at',
                'banned_at',
                'created_at',
            ])
            ->keyBy('id');

        return $countsByUser
            ->map(fn (int $count, int $userId) => $users->get($userId)?->setAttribute('creator_spotlight_videos_count', $count))
            ->filter()
            ->values()
            ->take($limit);
    }

}
