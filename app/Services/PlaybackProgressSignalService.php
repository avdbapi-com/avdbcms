<?php

namespace App\Services;

use App\Models\PlaybackProgressSignal;
use App\Models\User;
use App\Models\Video;
use App\Support\BoundedConfig;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class PlaybackProgressSignalService
{
    public const bool DEFAULT_ENABLED = false;
    public const int DEFAULT_MIN_DURATION_SECONDS = 30;
    public const int DEFAULT_RETENTION_DAYS = 90;
    public const int MIN_MIN_DURATION_SECONDS = 5;
    public const int MAX_MIN_DURATION_SECONDS = 3600;
    public const int MIN_RETENTION_DAYS = 1;
    public const int MAX_RETENTION_DAYS = 365;
    private const int PRUNE_CHUNK_SIZE = 1000;
    private const int MAX_PRUNE_CHUNK_SIZE = 5000;

    private static ?bool $tableReady = null;

    public static function settingsDefaults(): array
    {
        return [
            'enabled' => self::DEFAULT_ENABLED ? '1' : '0',
            'min_duration_seconds' => self::DEFAULT_MIN_DURATION_SECONDS,
            'min_duration_seconds_min' => self::MIN_MIN_DURATION_SECONDS,
            'min_duration_seconds_max' => self::MAX_MIN_DURATION_SECONDS,
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'retention_days_min' => self::MIN_RETENTION_DAYS,
            'retention_days_max' => self::MAX_RETENTION_DAYS,
        ];
    }

    public function ingestForCurrentUser(Video $video, int $positionSeconds, ?int $durationSeconds = null, ?CarbonInterface $playedAt = null): ?PlaybackProgressSignal
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User) {
            return null;
        }

        return $this->ingest($viewer, $video, $positionSeconds, $durationSeconds, $playedAt);
    }

    public function ingest(User $viewer, Video $video, int $positionSeconds, ?int $durationSeconds = null, ?CarbonInterface $playedAt = null): ?PlaybackProgressSignal
    {
        if (! $this->enabled() || ! $this->tableReady() || ! $viewer->exists || ! $video->exists) {
            return null;
        }

        $durationSeconds = $this->durationSeconds($video, $durationSeconds);

        if ($durationSeconds < $this->minimumDurationSeconds()) {
            return null;
        }

        $positionSeconds = max(0, min($positionSeconds, $durationSeconds));

        if ($positionSeconds === 0) {
            return null;
        }

        $playedAt ??= now();
        $retentionDays = $this->retentionDays();

        $timestamp = now();

        PlaybackProgressSignal::query()->upsert(
            [[
                'user_id' => $viewer->getKey(),
                'video_id' => $video->getKey(),
                'position_seconds' => $positionSeconds,
                'duration_seconds' => $durationSeconds,
                'progress_basis_points' => intdiv($positionSeconds * 10000, max(1, $durationSeconds)),
                'last_played_at' => $playedAt,
                'expires_at' => $playedAt->copy()->addDays($retentionDays),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]],
            ['user_id', 'video_id'],
            [
                'position_seconds',
                'duration_seconds',
                'progress_basis_points',
                'last_played_at',
                'expires_at',
                'updated_at',
            ]
        );

        return $this->latestFor($viewer, $video);
    }

    public function latestFor(User $viewer, Video $video): ?PlaybackProgressSignal
    {
        if (! $this->enabled() || ! $this->tableReady() || ! $viewer->exists || ! $video->exists) {
            return null;
        }

        return PlaybackProgressSignal::query()
            ->where('user_id', $viewer->getKey())
            ->where('video_id', $video->getKey())
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            })
            ->first();
    }

    public function enabled(): bool
    {
        return (bool) config('site.playback_progress_signal_enabled', self::DEFAULT_ENABLED);
    }

    public function minimumDurationSeconds(): int
    {
        return BoundedConfig::integer(
            'site.playback_progress_signal_min_duration_seconds',
            self::DEFAULT_MIN_DURATION_SECONDS,
            self::MIN_MIN_DURATION_SECONDS,
            self::MAX_MIN_DURATION_SECONDS
        );
    }

    public function retentionDays(): int
    {
        return BoundedConfig::integer(
            'site.playback_progress_signal_retention_days',
            self::DEFAULT_RETENTION_DAYS,
            self::MIN_RETENTION_DAYS,
            self::MAX_RETENTION_DAYS
        );
    }

    public function pruneExpired(?CarbonInterface $now = null, int $chunkSize = self::PRUNE_CHUNK_SIZE): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        $now ??= now();
        $chunkSize = max(1, min(self::MAX_PRUNE_CHUNK_SIZE, $chunkSize));
        $deleted = 0;

        do {
            $ids = PlaybackProgressSignal::query()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->orderBy('expires_at')
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return $deleted;
            }

            $deletedThisBatch = PlaybackProgressSignal::query()
                ->whereKey($ids)
                ->delete();

            $deleted += $deletedThisBatch;
        } while ($deletedThisBatch >= $chunkSize);

        return $deleted;
    }

    private function durationSeconds(Video $video, ?int $durationSeconds): int
    {
        if ($durationSeconds !== null && $durationSeconds > 0) {
            return $durationSeconds;
        }

        return max(0, (int) $video->getRawOriginal('duration'));
    }

    private function tableReady(): bool
    {
        return self::$tableReady ??= Schema::hasTable('playback_progress_signals');
    }
}
