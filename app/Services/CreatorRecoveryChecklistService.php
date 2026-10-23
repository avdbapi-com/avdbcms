<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
use Illuminate\Support\Collection;

class CreatorRecoveryChecklistService
{
    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_LIMIT = 5;
    public const int DEFAULT_INACTIVITY_DAYS = 21;
    public const int MIN_LIMIT = 1;
    public const int MAX_LIMIT = 12;
    public const int MIN_INACTIVITY_DAYS = 1;
    public const int MAX_INACTIVITY_DAYS = 180;

    public static function settingsDefaults(): array
    {
        return [
            'enabled' => self::DEFAULT_ENABLED ? '1' : '0',
            'limit' => self::DEFAULT_LIMIT,
            'limit_min' => self::MIN_LIMIT,
            'limit_max' => self::MAX_LIMIT,
            'inactivity_days' => self::DEFAULT_INACTIVITY_DAYS,
            'inactivity_days_min' => self::MIN_INACTIVITY_DAYS,
            'inactivity_days_max' => self::MAX_INACTIVITY_DAYS,
        ];
    }

    public function itemsFor(User $creator): Collection
    {
        if (! config('site.creator_recovery_checklist_enabled', self::DEFAULT_ENABLED)) {
            return collect();
        }

        $limit = BoundedConfig::integer(
            'site.creator_recovery_checklist_limit',
            self::DEFAULT_LIMIT,
            self::MIN_LIMIT,
            self::MAX_LIMIT
        );
        $inactivityDays = BoundedConfig::integer(
            'site.creator_recovery_checklist_inactivity_days',
            self::DEFAULT_INACTIVITY_DAYS,
            self::MIN_INACTIVITY_DAYS,
            self::MAX_INACTIVITY_DAYS
        );
        $staleBefore = now()->subDays($inactivityDays);

        return collect()
            ->merge($this->failedUploads($creator, $staleBefore, $limit))
            ->merge($this->drafts($creator, $staleBefore, $limit))
            ->merge($this->staleUnpublished($creator, $staleBefore, $limit))
            ->take($limit)
            ->values();
    }

    private function failedUploads(User $creator, mixed $staleBefore, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->where('status', VideoStatus::FAILED)
            ->where('updated_at', '<=', $staleBefore)
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'failed_upload',
                'severity' => 'danger',
                'icon' => 'triangle-exclamation',
                'title' => __('Recover failed upload'),
                'message' => __('Retry the upload or remove the failed draft.'),
                'action_label' => __('Review uploads'),
                'action_url' => route('user.videos.index'),
                'video' => $video,
            ]);
    }

    private function drafts(User $creator, mixed $staleBefore, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->where('status', VideoStatus::DRAFT)
            ->where('updated_at', '<=', $staleBefore)
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'draft',
                'severity' => 'info',
                'icon' => 'pen-to-square',
                'title' => __('Finish draft'),
                'message' => __('Complete metadata and publish when ready.'),
                'action_label' => __('Continue draft'),
                'action_url' => route('user.videos.create', $video),
                'video' => $video,
            ]);
    }

    private function staleUnpublished(User $creator, mixed $staleBefore, int $limit): Collection
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->whereIn('status', [VideoStatus::PRIVATE, VideoStatus::PLANNED])
            ->whereNotNull('uploaded_at')
            ->where('updated_at', '<=', $staleBefore)
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Video $video): array => [
                'type' => 'stale_unpublished',
                'severity' => 'warning',
                'icon' => 'clock-rotate-left',
                'title' => __('Review unpublished video'),
                'message' => __('Check visibility, schedule, or publish readiness.'),
                'action_label' => __('Edit video'),
                'action_url' => route('user.videos.edit', $video),
                'video' => $video,
            ]);
    }
}
