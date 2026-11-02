<?php

namespace App\Services;

use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** The admin's "Creator tips box" size (creator_quality_pulse_limit), clamped to its range. */
    public function limit(): int
    {
        return BoundedConfig::integer(
            'site.creator_quality_pulse_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );
    }

    /**
     * The creator's recent videos, most watched first: the admin's limit of them, or $candidates (at most three times
     * the largest limit) when the caller picks among more (the studio dashboard lists the videos that have something to
     * fix, up to the admin's limit: member design 6.2). $withRelations loads each video's active thumbnail, creator
     * and category; the studio dashboard reads none of them (its tips print studio links and the member's own titles)
     * and passes false: one query for the videos (1.2.3 round 1, R1-074).
     */
    public function videosFor(User $creator, ?int $candidates = null, bool $withRelations = true): Collection
    {
        if (! config('site.creator_quality_pulse_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = $candidates === null ? $this->limit() : max(self::MIN_LIMIT, min(self::MAX_LIMIT * 3, $candidates));
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
            ->when(
                $withRelations,
                fn ($query) => $query->with([
                    'thumbnail',
                    'user:id,username',
                    'category:id,title',
                ]),
                fn ($query) => $query->without('thumbnail'),
            )
            ->withCount([
                'comments as creator_quality_comments_count' => fn ($query) => $query->where('created_at', '>=', $since),
                'thumbnail as creator_quality_thumbnail_count',
                // the dashboard's tips (member design 6.2) suggest a playlist for a video in none of them
                'playlists as creator_quality_playlists_count',
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
                // studio-30: a poster picture counts as a thumbnail (imported videos show their poster on every card)
                $thumbnailBonus = ((int) $video->creator_quality_thumbnail_count > 0 || filled($video->poster_url)) ? 20 : 0;

                $video->setAttribute('creator_quality_score', min(100, ($views * 2) + ($comments * 10) + $thumbnailBonus));
                $video->setAttribute('creator_quality_thumbnail_ready', $thumbnailBonus > 0);

                return $video;
            });
    }

    private function shouldUseDailyStats(mixed $since): bool
    {
        // Every install has the table (2026_05_05 migration): ask for rows directly instead of looking the table up
        // in the schema on every dashboard view; a database without it still falls back to the raw view rows.
        try {
            return DB::table('video_view_daily_stats')
                ->where('view_date', '>=', $since->toDateString())
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }
}
