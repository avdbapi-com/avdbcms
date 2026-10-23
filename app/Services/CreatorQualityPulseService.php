<?php

namespace App\Services;

use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatorQualityPulseService
{
    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_LIMIT = 5;
    public const int DEFAULT_LOOKBACK_DAYS = 30;
    public const int MIN_LIMIT = 1;
    public const int MAX_LIMIT = 12;
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

    public function videosFor(User $creator): Collection
    {
        if (! config('site.creator_quality_pulse_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = BoundedConfig::integer(
            'site.creator_quality_pulse_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );
        $lookbackDays = BoundedConfig::integer(
            'site.creator_quality_pulse_lookback_days',
            self::DEFAULT_LOOKBACK_DAYS,
            self::MIN_LOOKBACK_DAYS,
            self::MAX_LOOKBACK_DAYS
        );
        $since = now()->subDays($lookbackDays);
        $useDailyStats = $this->shouldUseDailyStats($since);

        $query = Video::query()
            ->where('user_id', $creator->id)
            ->valid()
            ->whereNotNull('uploaded_at')
            ->where(function ($query) use ($since): void {
                $query->where('published_at', '>=', $since)
                    ->orWhere('uploaded_at', '>=', $since);
            })
            ->with([
                'thumbnail',
                'user:id,username',
                'category:id,title',
            ])
            ->withCount([
                'comments as creator_quality_comments_count' => fn ($query) => $query->where('created_at', '>=', $since),
                'thumbnail as creator_quality_thumbnail_count',
            ]);

        if ($useDailyStats) {
            $query->selectSub(
                    DB::table('video_view_daily_stats')
                        ->selectRaw('COALESCE(SUM(views_count), 0)')
                        ->whereColumn('video_view_daily_stats.video_id', 'videos.id')
                        ->where('view_date', '>=', $since->toDateString()),
                    'creator_quality_views_count'
                );
        } else {
            $query->withCount([
                'viewsHistory as creator_quality_views_count' => fn ($query) => $query->where('view_at', '>=', $since),
            ]);
        }

        return $query
            ->orderByDesc('creator_quality_views_count')
            ->orderByDesc('creator_quality_comments_count')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Video $video): Video {
                $views = min(50, max(0, (int) $video->creator_quality_views_count));
                $comments = min(10, max(0, (int) $video->creator_quality_comments_count));
                $thumbnailBonus = (int) $video->creator_quality_thumbnail_count > 0 ? 20 : 0;

                $video->setAttribute('creator_quality_score', min(100, ($views * 2) + ($comments * 10) + $thumbnailBonus));
                $video->setAttribute('creator_quality_thumbnail_ready', $thumbnailBonus > 0);

                return $video;
            });
    }

    private function shouldUseDailyStats(mixed $since): bool
    {
        return Schema::hasTable('video_view_daily_stats')
            && DB::table('video_view_daily_stats')
                ->where('view_date', '>=', $since->toDateString())
                ->exists();
    }
}
