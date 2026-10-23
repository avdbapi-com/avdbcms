<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ViewHistoryRetentionService
{
    public const string LOG_MODE_DETAILED = 'detailed';
    public const string LOG_MODE_AUTHENTICATED = 'authenticated';
    public const string LOG_MODE_COUNTER_ONLY = 'counter_only';

    public const string DEFAULT_LOG_MODE = self::LOG_MODE_AUTHENTICATED;
    public const bool DEFAULT_DAILY_STATS_ENABLED = true;
    public const int DEFAULT_RETENTION_DAYS = 90;
    public const int MIN_RETENTION_DAYS = 1;
    public const int MAX_RETENTION_DAYS = 3650;
    public const int DEFAULT_PRUNE_CHUNK_SIZE = 5000;
    public const int MAX_PRUNE_CHUNK_SIZE = 50000;

    public static function logModes(): array
    {
        return [
            self::LOG_MODE_DETAILED => 'Detailed raw history',
            self::LOG_MODE_AUTHENTICATED => 'Member history only',
            self::LOG_MODE_COUNTER_ONLY => 'Counter only',
        ];
    }

    public static function settingsDefaults(): array
    {
        return [
            'log_mode' => self::DEFAULT_LOG_MODE,
            'daily_stats_enabled' => self::DEFAULT_DAILY_STATS_ENABLED ? '1' : '0',
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'retention_days_min' => self::MIN_RETENTION_DAYS,
            'retention_days_max' => self::MAX_RETENTION_DAYS,
        ];
    }

    public function logMode(): string
    {
        $mode = (string) $this->settingValue('view_log_mode', self::DEFAULT_LOG_MODE);

        return array_key_exists($mode, self::logModes()) ? $mode : self::DEFAULT_LOG_MODE;
    }

    public function dailyStatsEnabled(): bool
    {
        return (string) $this->settingValue('view_daily_stats_enabled', self::DEFAULT_DAILY_STATS_ENABLED ? '1' : '0') === '1';
    }

    public function retentionDays(): int
    {
        $days = (int) $this->settingValue('view_history_retention_days', self::DEFAULT_RETENTION_DAYS);

        return max(self::MIN_RETENTION_DAYS, min(self::MAX_RETENTION_DAYS, $days));
    }

    public function shouldStoreRawView(?User $viewer = null): bool
    {
        return match ($this->logMode()) {
            self::LOG_MODE_DETAILED => true,
            self::LOG_MODE_AUTHENTICATED => ($viewer ?? Auth::user()) instanceof User,
            default => false,
        };
    }

    public function prune(?CarbonInterface $now = null, int $chunkSize = self::DEFAULT_PRUNE_CHUNK_SIZE): int
    {
        if (! Schema::hasTable('views')) {
            return 0;
        }

        $now ??= now();
        $chunkSize = max(1, min(self::MAX_PRUNE_CHUNK_SIZE, $chunkSize));
        $cutoff = $now->copy()->subDays($this->retentionDays());
        $deleted = 0;

        do {
            $ids = DB::table('views')
                ->where('view_at', '<', $cutoff)
                ->orderBy('view_at')
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return $deleted;
            }

            $deletedThisBatch = DB::table('views')
                ->whereIn('id', $ids)
                ->delete();

            $deleted += $deletedThisBatch;
        } while ($deletedThisBatch >= $chunkSize);

        return $deleted;
    }

    private function settingValue(string $key, mixed $default): mixed
    {
        $value = config('site.' . $key);

        if ($value !== null) {
            return $value;
        }

        if (app()->runningInConsole() && Schema::hasTable('settings')) {
            return Setting::get($key, $default);
        }

        return $default;
    }
}
