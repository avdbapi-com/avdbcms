<?php

namespace App\Services;

use App\Models\User;
use App\Services\Concerns\BuildsRecentPublicVideoQuery;
use App\Support\BoundedConfig;
use App\Support\SafeCache;
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

    public function creators(): Collection
    {
        if (! config('site.creator_spotlight_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = BoundedConfig::integer(
            'site.creator_spotlight_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );
        $lookbackDays = BoundedConfig::integer(
            'site.creator_spotlight_lookback_days',
            self::DEFAULT_LOOKBACK_DAYS,
            self::MIN_LOOKBACK_DAYS,
            self::MAX_LOOKBACK_DAYS
        );
        $since = now()->subDays($lookbackDays);
        $locale = app()->getLocale();
        $cacheKey = "home:creator-spotlight:v5:{$locale}:{$limit}:{$lookbackDays}";

        $resolver = function () use ($limit, $since): Collection {
            $recentRows = $this->creatorVideoCounts($since, $limit * 3);
            $creators = $this->hydrateCreatorsFromCounts($recentRows, $limit);

            if ($creators->count() >= $limit) {
                return $creators;
            }

            $fallbackRows = $this->creatorVideoCounts(null, $limit * 3);
            $fallbackCreators = $this->hydrateCreatorsFromCounts($fallbackRows, $limit);

            if ($fallbackCreators->count() >= $limit) {
                return $fallbackCreators;
            }

            // Fallback: If still under limit, include top active community members with any video or profile
            $existingIds = $fallbackCreators->pluck('id')->all();
            $moreUsers = User::query()
                ->active()
                ->whereNotIn('id', $existingIds)
                ->withCount('subscribers')
                ->take($limit - $fallbackCreators->count())
                ->get();

            return $fallbackCreators->merge($moreUsers)->values();
        };

        return SafeCache::remember($cacheKey, now()->addMinutes(10), $resolver);
    }

    private function creatorVideoCounts($since, int $limit): Collection
    {
        $query = DB::table('videos')
            ->select('videos.user_id')
            ->selectRaw('COUNT(*) as creator_spotlight_videos_count')
            ->whereNotNull('videos.user_id')
            ->whereNull('videos.deleted_at')
            ->groupBy('videos.user_id')
            ->orderByDesc('creator_spotlight_videos_count')
            ->limit($limit);

        $this->applyActiveLocalizedVideoFilters($query);

        if ($since) {
            $query->where(function ($query) use ($since): void {
                $query->where('videos.published_at', '>=', $since)
                    ->orWhere('videos.scheduled_at', '>=', $since);
            });
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
