<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
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

    public function alertsFor(User $creator): Collection
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

        $alerts = collect()
            ->merge($this->failedUploadAlerts($creator, $since, $limit))
            ->merge($this->recentReportAlerts($creator, $since, $limit))
            ->merge($this->missingThumbnailAlerts($creator, $since, $limit))
            ->merge($this->lowEngagementAlerts($creator, $since, $limit))
            ->take($limit)
            ->values();

        $videos = $alerts
            ->pluck('video')
            ->filter(fn (mixed $video): bool => $video instanceof Video);

        if ($videos->isNotEmpty()) {
            (new EloquentCollection($videos->all()))->loadMissing(['user:id,username', 'category:id,title']);
        }

        return $alerts;
    }

    private function failedUploadAlerts(User $creator, mixed $since, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->where('status', VideoStatus::FAILED)
            ->where('updated_at', '>=', $since)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'upload_failed',
                'severity' => 'danger',
                'icon' => 'triangle-exclamation',
                'title' => __('Upload failed'),
                'message' => __('Review this failed upload and retry or remove it.'),
                'video' => $video,
                'count' => null,
            ]);
    }

    private function recentReportAlerts(User $creator, mixed $since, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->whereHas('reports', fn ($query) => $query
                ->where('status', ReportStatus::PENDING)
                ->where('created_at', '>=', $since))
            ->withCount(['reports as studio_health_reports_count' => fn ($query) => $query
                ->where('status', ReportStatus::PENDING)
                ->where('created_at', '>=', $since)])
            ->orderByDesc('studio_health_reports_count')
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'recent_reports',
                'severity' => 'warning',
                'icon' => 'flag',
                'title' => __('Recent reports'),
                'message' => trans_choice('reports', (int) $video->studio_health_reports_count),
                'video' => $video,
                'count' => (int) $video->studio_health_reports_count,
            ]);
    }

    private function missingThumbnailAlerts(User $creator, mixed $since, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->valid()
            ->whereNotNull('uploaded_at')
            ->where(function ($query) use ($since): void {
                $query->where('published_at', '>=', $since)
                    ->orWhere('uploaded_at', '>=', $since);
            })
            ->whereDoesntHave('thumbnail')
            ->latest('uploaded_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'missing_thumbnail',
                'severity' => 'info',
                'icon' => 'image',
                'title' => __('Missing thumbnail'),
                'message' => __('Add a thumbnail to improve the video card.'),
                'video' => $video,
                'count' => null,
            ]);
    }

    private function lowEngagementAlerts(User $creator, mixed $since, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->whereIn('status', [VideoStatus::PUBLIC, VideoStatus::UNLISTED])
            ->whereNotNull('uploaded_at')
            ->where(function ($query) use ($since): void {
                $query->where('published_at', '>=', $since)
                    ->orWhere('uploaded_at', '>=', $since);
            })
            ->whereDoesntHave('viewsHistory', fn ($query) => $query->where('view_at', '>=', $since))
            ->latest('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'low_engagement',
                'severity' => 'secondary',
                'icon' => 'chart-line',
                'title' => __('No recent views'),
                'message' => __('Refresh the title, thumbnail, or distribution.'),
                'video' => $video,
                'count' => 0,
            ]);
    }
}
