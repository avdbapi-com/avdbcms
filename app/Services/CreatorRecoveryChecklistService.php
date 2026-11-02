<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
use Illuminate\Database\Eloquent\Builder;
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

        // 1.2.3 round 1 (R1-074): the three lists come in ONE round trip (a UNION ALL of the same three queries, each
        // with its own conditions, order and limit), and their videos never eager-load a thumbnail (no item prints it):
        // the studio dashboard stays within its query budget on a cold cache.
        $parts = [
            'failed_upload' => $this->failedUploadsQuery($creator, $staleBefore, $limit),
            'draft' => $this->draftsQuery($creator, $staleBefore, $limit),
            'stale_unpublished' => $this->staleUnpublishedQuery($creator, $staleBefore, $limit),
        ];
        $videos = $this->fetch($parts);

        return collect()
            ->merge($videos['failed_upload']->map(fn (Video $video): array => $this->failedUploadItem($video)))
            ->merge($videos['draft']->map(fn (Video $video): array => $this->draftItem($video)))
            ->merge($videos['stale_unpublished']->map(fn (Video $video): array => $this->staleUnpublishedItem($video)))
            ->take($limit)
            ->values();
    }

    /**
     * The videos of each list, in the list's own order (the oldest change first). One query; when the database refuses
     * the union, each list runs on its own as before.
     *
     * @param  array<string, Builder>  $parts
     * @return array<string, Collection<int, Video>>
     */
    private function fetch(array $parts): array
    {
        $tagged = [];

        foreach ($parts as $kind => $query) {
            $tagged[$kind] = (clone $query)->select('videos.*')->selectRaw("'".$kind."' AS recovery_kind");
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

        $out = [];

        foreach (array_keys($parts) as $kind) {
            $out[$kind] = $rows
                ->filter(fn (Video $video): bool => $video->getAttribute('recovery_kind') === $kind)
                ->sortBy(fn (Video $video): string => (string) $video->getRawOriginal('updated_at').'|'.str_pad((string) $video->getKey(), 20, '0', STR_PAD_LEFT))
                ->values();
        }

        return $out;
    }

    private function failedUploadsQuery(User $creator, mixed $staleBefore, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->where('user_id', $creator->id)
            ->where('status', VideoStatus::FAILED)
            ->where('updated_at', '<=', $staleBefore)
            ->oldest('updated_at')
            ->limit($limit);
    }

    private function failedUploadItem(Video $video): array
    {
        return [
            'type' => 'failed_upload',
            'severity' => 'danger',
            'icon' => 'triangle-exclamation',
            'title' => __('Recover failed upload'),
            'message' => __('Retry the upload or remove the failed draft.'),
            'action_label' => __('Review uploads'),
            'action_url' => route('user.videos.index'),
            'video' => $video,
        ];
    }

    private function draftsQuery(User $creator, mixed $staleBefore, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->where('user_id', $creator->id)
            ->where('status', VideoStatus::DRAFT)
            ->where('updated_at', '<=', $staleBefore)
            ->oldest('updated_at')
            ->limit($limit);
    }

    private function draftItem(Video $video): array
    {
        return [
            'type' => 'draft',
            'severity' => 'info',
            'icon' => 'pen-to-square',
            'title' => __('Finish draft'),
            'message' => __('Complete metadata and publish when ready.'),
            'action_label' => __('Continue draft'),
            'action_url' => route('user.videos.create', $video),
            'video' => $video,
        ];
    }

    private function staleUnpublishedQuery(User $creator, mixed $staleBefore, int $limit): Builder
    {
        return Video::query()
            ->without('thumbnail')
            ->where('user_id', $creator->id)
            ->whereIn('status', [VideoStatus::PRIVATE, VideoStatus::PLANNED])
            ->whereNotNull('uploaded_at')
            ->where('updated_at', '<=', $staleBefore)
            ->oldest('updated_at')
            ->limit($limit);
    }

    private function staleUnpublishedItem(Video $video): array
    {
        return [
            'type' => 'stale_unpublished',
            'severity' => 'warning',
            'icon' => 'clock-rotate-left',
            'title' => __('Review unpublished video'),
            'message' => __('Check visibility, schedule, or publish readiness.'),
            'action_label' => __('Edit video'),
            'action_url' => route('user.videos.edit', $video),
            'video' => $video,
        ];
    }
}
