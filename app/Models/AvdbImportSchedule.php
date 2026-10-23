<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvdbImportSchedule extends Model
{
    public const ACTIVE_RUN_STATUSES = ['queued', 'starting', 'running'];

    public const FREQUENCY_INTERVAL = 'interval';
    public const FREQUENCY_HOURLY = 'hourly';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'skip_if_active' => 'boolean',
        'settings' => 'array',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(AvdbImportRun::class, 'last_run_id');
    }

    public function calculateNextRunAt(?Carbon $from = null): Carbon
    {
        $from = ($from ?: now())->copy()->startOfMinute();
        $time = $this->timeParts();

        return match ($this->frequency) {
            self::FREQUENCY_INTERVAL => $from->copy()->addMinutes($this->boundedIntervalMinutes()),
            self::FREQUENCY_HOURLY => $this->nextHourlyRun($from, $time[1]),
            self::FREQUENCY_WEEKLY => $this->nextWeeklyRun($from, $time[0], $time[1]),
            self::FREQUENCY_MONTHLY => $this->nextMonthlyRun($from, $time[0], $time[1]),
            default => $this->nextDailyRun($from, $time[0], $time[1]),
        };
    }

    public function scheduleLabel(): string
    {
        $time = substr((string) $this->run_time, 0, 5) ?: '02:00';

        return match ($this->frequency) {
            self::FREQUENCY_INTERVAL => 'Every ' . $this->boundedIntervalMinutes() . ' min',
            self::FREQUENCY_HOURLY => 'Hourly at :' . str_pad((string) $this->timeParts()[1], 2, '0', STR_PAD_LEFT),
            self::FREQUENCY_WEEKLY => 'Weekly ' . $this->weekdayName() . ' at ' . $time,
            self::FREQUENCY_MONTHLY => 'Monthly day ' . $this->boundedMonthDay() . ' at ' . $time,
            default => 'Daily at ' . $time,
        };
    }

    /**
     * @return array<int, string>
     */
    public function settingsSummary(): array
    {
        $settings = (array) $this->settings;
        $categories = array_values(array_filter(array_map('intval', (array) ($settings['categories'] ?? []))));
        $filters = array_filter([
            'wd' => (string) ($settings['search_wd'] ?? ''),
            'code' => (string) ($settings['search_code'] ?? ''),
            'actor' => (string) ($settings['search_actor'] ?? ''),
            'director' => (string) ($settings['search_director'] ?? ''),
            'writer' => (string) ($settings['search_writer'] ?? ''),
            'tag' => (string) ($settings['search_category'] ?? ''),
            'h' => (int) ($settings['updated_hours'] ?? 0) > 0 ? (string) $settings['updated_hours'] : '',
            'year' => (string) ($settings['year'] ?? ''),
        ]);

        return array_values(array_filter([
            'Cats: ' . ($categories === [] ? 'Direct IDs' : implode(',', $categories)) . ' | Pages: ' . (int) ($settings['start_page'] ?? 1) . '+' . (int) ($settings['pages'] ?? 1) . ' | Size: ' . (int) ($settings['pagesize'] ?? 100),
            'Sort: ' . strtoupper((string) ($settings['sort_direction'] ?? 'desc')) . ' | Thumbnail: ' . (string) ($settings['thumbnail_mode'] ?? 'remote') . ' | Starter: ' . (string) ($settings['starter_views_mode'] ?? 'disabled'),
            'Lang: ' . (string) ($settings['api_lang'] ?? 'en') . ' | Visibility: ' . (string) ($settings['language_mode'] ?? 'auto') . ' | Full-lang: ' . (! empty($settings['full_langs']) ? 'yes' : 'no'),
            $filters === [] ? null : 'Filters: ' . implode(', ', array_map(
                static fn (string $key, string $value): string => "{$key}={$value}",
                array_keys($filters),
                array_values($filters)
            )),
        ]));
    }

    private function nextHourlyRun(Carbon $from, int $minute): Carbon
    {
        $candidate = $from->copy()->minute($minute)->second(0);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate->addHour();
        }

        return $candidate;
    }

    private function nextDailyRun(Carbon $from, int $hour, int $minute): Carbon
    {
        $candidate = $from->copy()->setTime($hour, $minute);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function nextWeeklyRun(Carbon $from, int $hour, int $minute): Carbon
    {
        $weekday = max(0, min(6, (int) $this->weekday));
        $daysAhead = ($weekday - (int) $from->dayOfWeek + 7) % 7;
        $candidate = $from->copy()->addDays($daysAhead)->setTime($hour, $minute);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate->addWeek();
        }

        return $candidate;
    }

    private function nextMonthlyRun(Carbon $from, int $hour, int $minute): Carbon
    {
        $day = $this->boundedMonthDay();
        $candidate = $from->copy()->day(min($day, $from->copy()->endOfMonth()->day))->setTime($hour, $minute);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate->addMonthNoOverflow();
            $candidate->day(min($day, $candidate->copy()->endOfMonth()->day))->setTime($hour, $minute);
        }

        return $candidate;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function timeParts(): array
    {
        $time = (string) ($this->run_time ?: '02:00');
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $time, $match)) {
            return [2, 0];
        }

        return [
            max(0, min(23, (int) $match[1])),
            max(0, min(59, (int) $match[2])),
        ];
    }

    private function boundedIntervalMinutes(): int
    {
        return max(15, min(10080, (int) ($this->interval_minutes ?: 1440)));
    }

    private function boundedMonthDay(): int
    {
        return max(1, min(28, (int) ($this->month_day ?: 1)));
    }

    private function weekdayName(): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][max(0, min(6, (int) $this->weekday))];
    }
}
