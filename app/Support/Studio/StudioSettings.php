<?php

namespace App\Support\Studio;

use App\Services\CreatorQualityPulseService;
use App\Services\CreatorRecoveryChecklistService;
use App\Services\StudioHealthAlertsService;
use App\Support\BoundedConfig;

/**
 * The admin settings the studio pages keep honouring (member design 6.0), read one way everywhere: a switch
 * may be stored as a bool or as '1' / '0', a limit is clamped to its range.
 */
final class StudioSettings
{
    public static function on(string $key, bool $default): bool
    {
        $value = config('site.'.$key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function int(string $key, int $default, int $min, int $max): int
    {
        if (class_exists(BoundedConfig::class)) {
            return BoundedConfig::integer('site.'.$key, $default, $min, $max);
        }

        return max($min, min($max, (int) config('site.'.$key, $default)));
    }

    /** "Searchable drop-down lists" (enhanced_selects_enabled): the Playlists TomSelect, else a check-box list. */
    public static function enhancedSelects(): bool
    {
        return StudioChrome::enhancedSelects();
    }

    public static function uploadsAllowed(): bool
    {
        return self::on('allow_uploads', true);
    }

    /** Rows in Latest videos, Latest comments, New subscribers (dashboard_widget_limit, default 5). */
    public static function widgetLimit(): int
    {
        return self::int('dashboard_widget_limit', 5, 1, 20);
    }

    public static function dailyStatsEnabled(): bool
    {
        return self::on('view_daily_stats_enabled', true);
    }

    public static function healthAlertsEnabled(): bool
    {
        return self::on('studio_health_alerts_enabled', StudioHealthAlertsService::DEFAULT_ENABLED);
    }

    public static function recoveryChecklistEnabled(): bool
    {
        return self::on('creator_recovery_checklist_enabled', CreatorRecoveryChecklistService::DEFAULT_ENABLED);
    }

    public static function qualityPulseEnabled(): bool
    {
        return self::on('creator_quality_pulse_enabled', CreatorQualityPulseService::DEFAULT_ENABLED);
    }
}
