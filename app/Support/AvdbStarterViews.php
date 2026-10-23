<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AvdbStarterViews
{
    public const MODE_DISABLED = 'disabled';
    public const MODE_NATURAL = 'natural';
    public const MODE_AGE_WEIGHTED = 'age_weighted';

    private const MAX_VIEWS = 1000000000;
    private const MAX_SPREAD_DAYS = 3650;

    public static function modes(): array
    {
        return [
            self::MODE_DISABLED => 'Disabled',
            self::MODE_NATURAL => 'Natural random',
            self::MODE_AGE_WEIGHTED => 'Age-weighted natural',
        ];
    }

    public static function defaults(): array
    {
        return [
            'mode' => self::MODE_DISABLED,
            'min' => 25,
            'max' => 5000,
            'spread_days' => 90,
            'daily_stats' => true,
            'only_zero' => true,
            'apply_existing_zero' => false,
            'seed' => '',
        ];
    }

    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $mode = (string) ($settings['mode'] ?? $defaults['mode']);
        if (! array_key_exists($mode, self::modes())) {
            $mode = self::MODE_DISABLED;
        }

        $min = self::clampInt($settings['min'] ?? $defaults['min'], 0, self::MAX_VIEWS);
        $max = self::clampInt($settings['max'] ?? $defaults['max'], 0, self::MAX_VIEWS);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'mode' => $mode,
            'min' => $min,
            'max' => $max,
            'spread_days' => self::clampInt($settings['spread_days'] ?? $defaults['spread_days'], 1, self::MAX_SPREAD_DAYS),
            'daily_stats' => self::boolValue($settings['daily_stats'] ?? $defaults['daily_stats']),
            'only_zero' => self::boolValue($settings['only_zero'] ?? $defaults['only_zero']),
            'apply_existing_zero' => self::boolValue($settings['apply_existing_zero'] ?? $defaults['apply_existing_zero']),
            'seed' => trim((string) ($settings['seed'] ?? $defaults['seed'])),
        ];
    }

    public static function enabled(array $settings): bool
    {
        $settings = self::normalize($settings);

        return $settings['mode'] !== self::MODE_DISABLED && $settings['max'] > 0;
    }

    public static function countFor(int $avdbId, ?CarbonInterface $releaseDate, array $settings): int
    {
        $settings = self::normalize($settings);
        if (! self::enabled($settings)) {
            return 0;
        }

        if ($settings['min'] === $settings['max']) {
            return $settings['min'];
        }

        $min = $settings['min'];
        $range = $settings['max'] - $min;
        $tier = self::unit($settings['seed'], $avdbId, 'tier');
        $withinTier = self::unit($settings['seed'], $avdbId, 'within-tier');

        [$low, $high] = match (true) {
            $tier < 0.70 => [0.00, 0.20],
            $tier < 0.90 => [0.20, 0.55],
            $tier < 0.98 => [0.55, 0.85],
            default => [0.85, 1.00],
        };

        $count = $min + (int) round($range * ($low + (($high - $low) * $withinTier)));

        if ($settings['mode'] === self::MODE_AGE_WEIGHTED) {
            $count = self::applyAgeWeight($count, $min, $releaseDate);
        }

        return self::clampInt($count, $min, $settings['max']);
    }

    public static function seedDailyStats(int $videoId, int $views, CarbonInterface $endAt, int $days, string $seed): void
    {
        if ($videoId <= 0 || $views <= 0 || $days <= 0 || ! Schema::hasTable('video_view_daily_stats')) {
            return;
        }

        $end = CarbonImmutable::instance($endAt)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();
        if ($end->greaterThan($today)) {
            $end = $today;
        }

        $span = min(self::MAX_SPREAD_DAYS, max(1, $days), max(1, $views));
        $allocations = self::dailyAllocations($views, $span, $seed . '|daily|' . $videoId);
        $now = now();

        foreach ($allocations as $offset => $count) {
            if ($count <= 0) {
                continue;
            }

            $date = $end->subDays($offset)->toDateString();
            $updated = DB::table('video_view_daily_stats')
                ->where('video_id', $videoId)
                ->where('view_date', $date)
                ->update([
                    'views_count' => DB::raw('views_count + ' . (int) $count),
                    'updated_at' => $now,
                ]);

            if ($updated > 0) {
                continue;
            }

            try {
                DB::table('video_view_daily_stats')->insert([
                    'video_id' => $videoId,
                    'view_date' => $date,
                    'views_count' => $count,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException) {
                DB::table('video_view_daily_stats')
                    ->where('video_id', $videoId)
                    ->where('view_date', $date)
                    ->increment('views_count', $count, ['updated_at' => now()]);
            }
        }
    }

    private static function applyAgeWeight(int $count, int $min, ?CarbonInterface $releaseDate): int
    {
        if (! $releaseDate) {
            return $count;
        }

        $now = CarbonImmutable::now();
        $daysOld = $releaseDate->lessThan($now) ? (int) floor($releaseDate->diffInDays($now)) : 0;
        $multiplier = match (true) {
            $daysOld < 30 => 0.25,
            $daysOld < 180 => 0.45,
            $daysOld < 365 => 0.65,
            $daysOld < 1095 => 0.85,
            default => 1.00,
        };

        return $min + (int) round(max(0, $count - $min) * $multiplier);
    }

    private static function dailyAllocations(int $views, int $days, string $seed): array
    {
        $weights = [];
        $weightTotal = 0.0;

        for ($offset = 0; $offset < $days; $offset++) {
            $recency = 1.0 + (($days - $offset) / $days) * 2.0;
            $jitter = 0.70 + (self::unit($seed, $offset, 'jitter') * 0.60);
            $weight = $recency * $jitter;
            $weights[$offset] = $weight;
            $weightTotal += $weight;
        }

        $allocations = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $offset => $weight) {
            $exact = ($weight / $weightTotal) * $views;
            $whole = (int) floor($exact);
            $allocations[$offset] = $whole;
            $remainders[$offset] = $exact - $whole;
            $allocated += $whole;
        }

        arsort($remainders, SORT_NUMERIC);
        $remaining = $views - $allocated;
        foreach (array_keys($remainders) as $offset) {
            if ($remaining <= 0) {
                break;
            }
            $allocations[$offset]++;
            $remaining--;
        }

        return $allocations;
    }

    private static function unit(string $seed, int $id, string $purpose): float
    {
        $material = implode('|', [config('app.url'), config('app.key'), $seed, $purpose, $id]);
        $int = (int) hexdec(substr(hash('sha256', $material), 0, 8));

        return $int / 0xffffffff;
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private static function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
