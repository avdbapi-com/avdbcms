<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class StudioHealthAlertsService
{
    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_LIMIT = 5;
    public const int DEFAULT_LOOKBACK_DAYS = 14;
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

    /**
     * The creator's alerts. $withLinkRelations loads what a video's public link reads (its creator and category); the
     * studio dashboard prints studio links only and passes false (1.2.3 round 1, R1-074: fewer queries on a cold
     * dashboard). The videos never eager-load their thumbnail here: no alert prints it.
     */
    public function alertsFor(User $creator, bool $withLinkRelations = true): Collection
    {
        if (! config('site.studio_health_alerts_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = BoundedConfig::integer(
            'site.studio_health_alerts_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );
        $lookbackDays = BoundedConfig::integer(
            'site.studio_health_alerts_lookback_days',
            self::DEFAULT_LOOKBACK_DAYS,
            self::MIN_LOOKBACK_DAYS,
            self::MAX_LOOKBACK_DAYS
        );
        $since = now()->subDays($lookbackDays);

        // 1.2.3 round 1 (R1-074): the four lists come in ONE round trip (a UNION ALL of the same four queries, each with
        // its own conditions, order and limit), so a cold studio dashboard stays within its query budget.
        $videos = $this->fetch([
            'upload_failed' => $this->failedUploadsQuery($creator, $since, $limit),
            'recent_reports' => $this->recentReportsQuery($creator, $since, $limit),
            'missing_thumbnail' => $this->missingThumbnailsQuery($creator, $since, $limit),
            'low_engagement' => $this->lowEngagementQuery($creator, $since, $limit),
        ]);

        $alerts = collect()
            ->merge($videos['upload_failed']->map(fn (Video $video): array => $this->failedUploadAlert($video)))
            ->merge($videos['recent_reports']->map(fn (Video $video): array => $this->recentReportAlert($video)))
            ->merge($videos['missing_thumbnail']->map(fn (Video $video): array => $this->missingThumbnailAlert($video)))
            ->merge($videos['low_engagement']->map(fn (Video $video): array => $this->lowEngagementAlert($video)))
            ->take($limit)
            ->values();

        $alertVideos = $alerts
            ->pluck('video')
            ->filter(fn (mixed $video): bool => $video instanceof Video);

        if ($withLinkRelations && $alertVideos->isNotEmpty()) {
            (new EloquentCollection($alertVideos->all()))->loadMissing(['user:id,username', 'category:id,title']);
        }

        return $alerts;
    }

    /**
     * The videos of each list, each list in its own order. One query (UNION ALL; every part selects the same columns in
     * the same order: the video, its list, its pending reports); when the database refuses the union, each list runs on
     * its own.
     *
     * @param  array<string, Builder>  $parts
     * @return array<string, Collection<int, Video>>
     */
    private function fetch(array $parts): array
    {
        $tagged = [];

        foreach ($parts as $kind => $query) {
            $tagged[$kind] = clone $query;
        }

        try {
            $union = null;

            foreach ($tagged as $query) {
                $union = $union === null ? clone $query : $union->unionAll($query);
            }

            $rows = $union->get();
        } catch (\Throwable) {
            $rows = collect();

            foreach ($tagged as $query) {
                $rows = $rows->concat($query->get());
            }
        }

        $time = fn (Video $video, string $column): string => (string) $video->getRawOriginal($column);
        $id = fn (Video $video): string => str_pad((string) $video->getKey(), 20, '0', STR_PAD_LEFT);
        $order = [
            // latest change first
            'upload_failed' => fn (Video $video): string => $time($video, 'updated_at').'|'.$id($video),
            // most reports first, then the latest change
            'recent_reports' => fn (Video $video): string => str_pad((string) (int) $video->studio_health_reports_count, 10, '0', STR_PAD_LEFT).'|'.$time($video, 'updated_at').'|'.$id($video),
            // latest upload / publication first
            'missing_thumbnail' => fn (Video $video): string => $time($video, 'uploaded_at').'|'.$id($video),
            'low_engagement' => fn (Video $video): string => $time($video, 'published_at').'|'.$id($video),
        ];
        $out = [];

        foreach (array_keys($parts) as $kind) {
            $out[$kind] = $rows
                ->filter(fn (Video $video): bool => $video->getAttribute('studio_health_kind') === $kind)
                ->sortByDesc($order[$kind])
                ->values();
        }

        return $out;
    }

    private function failedUploadsQuery(User $creator, mixed $since, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->select('videos.*')
            ->selectRaw("'upload_failed' AS studio_health_kind, 0 AS studio_health_reports_count")
            ->where('user_id', $creator->id)
            ->where('status', VideoStatus::FAILED)
            ->where('updated_at', '>=', $since)
            ->latest('updated_at')
            ->limit($limit);
    }

    private function failedUploadAlert(Video $video): array
    {
        return [
            'type' => 'upload_failed',
            'severity' => 'danger',
            'icon' => 'triangle-exclamation',
            'title' => __('Upload failed'),
            'message' => __('Review this failed upload and retry or remove it.'),
            'video' => $video,
            'count' => null,
        ];
    }

    private function recentReportsQuery(User $creator, mixed $since, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->select('videos.*')
            ->selectRaw("'recent_reports' AS studio_health_kind")
            ->where('user_id', $creator->id)
            ->whereHas('reports', fn ($query) => $query
                ->where('status', ReportStatus::PENDING)
                ->where('created_at', '>=', $since))
            ->withCount(['reports as studio_health_reports_count' => fn ($query) => $query
                ->where('status', ReportStatus::PENDING)
                ->where('created_at', '>=', $since)])
            ->orderByDesc('studio_health_reports_count')
            ->latest('updated_at')
            ->limit($limit);
    }

    private function recentReportAlert(Video $video): array
    {
        return [
            'type' => 'recent_reports',
            'severity' => 'warning',
            'icon' => 'flag',
            'title' => __('Recent reports'),
            'message' => trans_choice('reports', (int) $video->studio_health_reports_count),
            'video' => $video,
            'count' => (int) $video->studio_health_reports_count,
        ];
    }

    private function missingThumbnailsQuery(User $creator, mixed $since, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->select('videos.*')
            ->selectRaw("'missing_thumbnail' AS studio_health_kind, 0 AS studio_health_reports_count")
            ->where('user_id', $creator->id)
            ->valid()
            ->whereNotNull('uploaded_at')
            ->where(function ($query) use ($since): void {
                $query->where('published_at', '>=', $since)
                    ->orWhere('uploaded_at', '>=', $since);
            })
            ->whereDoesntHave('thumbnail')
            // studio-30: a poster picture counts as a thumbnail (the card shows it), so it is not "missing"
            ->where(fn ($query) => $query->whereNull('poster_url')->orWhere('poster_url', ''))
            ->latest('uploaded_at')
            ->limit($limit);
    }

    private function missingThumbnailAlert(Video $video): array
    {
        return [
            'type' => 'missing_thumbnail',
            'severity' => 'info',
            'icon' => 'image',
            'title' => __('Missing thumbnail'),
            'message' => __('Add a thumbnail to improve the video card.'),
            'video' => $video,
            'count' => null,
        ];
    }

    private function lowEngagementQuery(User $creator, mixed $since, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->select('videos.*')
            ->selectRaw("'low_engagement' AS studio_health_kind, 0 AS studio_health_reports_count")
            ->where('user_id', $creator->id)
            ->whereIn('status', [VideoStatus::PUBLIC, VideoStatus::UNLISTED])
            ->whereNotNull('uploaded_at')
            ->where(function ($query) use ($since): void {
                $query->where('published_at', '>=', $since)
                    ->orWhere('uploaded_at', '>=', $since);
            })
            // 1.2.3 round 1 (R1-019): nobody watched it, by the count every view goes to (guests included, as the Views
            // tile and Analytics count them). It was published or uploaded inside the window, so all of its views are
            // recent ones; the raw view rows (signed-in viewers only, pruned after 90 days) listed a video watched 42
            // times today as "No recent views", and every recent video of a site whose viewers are guests.
            ->where('views', '<=', 0)
            ->latest('published_at')
            ->limit($limit);
    }

    private function lowEngagementAlert(Video $video): array
    {
        return [
            'type' => 'low_engagement',
            'severity' => 'secondary',
            'icon' => 'chart-line',
            'title' => __('No recent views'),
            'message' => __('Refresh the title, thumbnail, or distribution.'),
            'video' => $video,
            'count' => 0,
        ];
    }
}
