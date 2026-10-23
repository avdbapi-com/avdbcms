<?php

namespace App\Services;

use App\Models\Search;
use App\Support\BoundedConfig;
use Illuminate\Support\Facades\Cache;

class SearchHistoryRetentionService
{
    public const int DEFAULT_RETENTION_DAYS = 180;
    public const int DEFAULT_MAX_ROWS = 50000;
    public const int DEFAULT_CLEANUP_INTERVAL_MINUTES = 60;
    public const int MIN_RETENTION_DAYS = 1;
    public const int MAX_RETENTION_DAYS = 3650;
    public const int MIN_MAX_ROWS = 100;
    public const int MAX_MAX_ROWS = 5000000;
    public const int MIN_CLEANUP_INTERVAL_MINUTES = 1;
    public const int MAX_CLEANUP_INTERVAL_MINUTES = 1440;
    private const int DELETE_CHUNK_SIZE = 1000;

    public static function settingsDefaults(): array
    {
        return [
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'retention_days_min' => self::MIN_RETENTION_DAYS,
            'retention_days_max' => self::MAX_RETENTION_DAYS,
            'max_rows' => self::DEFAULT_MAX_ROWS,
            'max_rows_min' => self::MIN_MAX_ROWS,
            'max_rows_max' => self::MAX_MAX_ROWS,
            'cleanup_interval_minutes' => self::DEFAULT_CLEANUP_INTERVAL_MINUTES,
            'cleanup_interval_minutes_min' => self::MIN_CLEANUP_INTERVAL_MINUTES,
            'cleanup_interval_minutes_max' => self::MAX_CLEANUP_INTERVAL_MINUTES,
        ];
    }

    public function maybeCleanup(): void
    {
        $interval = $this->cleanupIntervalMinutes();

        if (!Cache::add('search-history-retention-cleanup', true, now()->addMinutes($interval))) {
            return;
        }

        $this->cleanup();
    }

    public function cleanup(): void
    {
        Search::query()
            ->where('perform_at', '<', now()->subDays($this->retentionDays()))
            ->delete();

        $this->trimToMaxRows();
    }

    private function trimToMaxRows(): void
    {
        $overflow = Search::query()->count() - $this->maxRows();

        while ($overflow > 0) {
            $ids = Search::query()
                ->orderBy('perform_at')
                ->orderBy('id')
                ->limit(min(self::DELETE_CHUNK_SIZE, $overflow))
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            Search::query()->whereKey($ids)->delete();
            $overflow -= $ids->count();
        }
    }

    private function retentionDays(): int
    {
        return BoundedConfig::integer(
            'site.search_history_retention_days',
            self::DEFAULT_RETENTION_DAYS,
            self::MIN_RETENTION_DAYS,
            self::MAX_RETENTION_DAYS
        );
    }

    private function maxRows(): int
    {
        return BoundedConfig::integer(
            'site.search_history_max_rows',
            self::DEFAULT_MAX_ROWS,
            self::MIN_MAX_ROWS,
            self::MAX_MAX_ROWS
        );
    }

    private function cleanupIntervalMinutes(): int
    {
        return BoundedConfig::integer(
            'site.search_history_cleanup_interval_minutes',
            self::DEFAULT_CLEANUP_INTERVAL_MINUTES,
            self::MIN_CLEANUP_INTERVAL_MINUTES,
            self::MAX_CLEANUP_INTERVAL_MINUTES
        );
    }

}
