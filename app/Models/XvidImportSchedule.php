<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XvidImportSchedule extends Model
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
        'is_active' => 'boolean',
        'skip_if_active' => 'boolean',
        'categories' => 'array',
        'settings' => 'array',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(XvidImportRun::class, 'last_run_id');
    }

    // The clean-install schema stores timing options in settings. Older
    // deployments may also have dedicated columns, which remain a fallback.
    public function getFrequencyAttribute($value): string
    {
        return (string) ($this->settings['frequency'] ?? $value ?? self::FREQUENCY_DAILY);
    }

    public function getIntervalMinutesAttribute($value): int
    {
        return (int) ($this->settings['interval_minutes'] ?? $value ?? 60);
    }

    public function getRunTimeAttribute($value): string
    {
        return (string) ($this->settings['run_time'] ?? $value ?? '03:00');
    }

    public function getWeekdayAttribute($value): int
    {
        $weekday = $this->settings['weekday'] ?? $value ?? 0;
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        return is_numeric($weekday) ? (int) $weekday : (int) array_search(strtolower((string) $weekday), $days, true);
    }

    public function getMonthDayAttribute($value): int
    {
        return (int) ($this->settings['month_day'] ?? $value ?? 1);
    }

    public function getSkipIfActiveAttribute($value): bool
    {
        return (bool) ($this->settings['skip_if_active'] ?? $value ?? true);
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
            'Cats: ' . ($categories === [] ? 'All' : implode(',', $categories)) . ' | Pages: ' . (int) ($settings['start_page'] ?? 1) . '+' . (int) ($settings['pages'] ?? 1) . ' | Size: ' . (int) ($settings['pagesize'] ?? 50),
            'Sort: ' . strtoupper((string) ($settings['sort_direction'] ?? 'desc')) . ' | Thumbnail: ' . (string) ($settings['thumbnail_mode'] ?? 'remote'),
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
        $candidate = $from->copy()->hour($hour)->minute($minute)->second(0);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function nextWeeklyRun(Carbon $from, int $hour, int $minute): Carbon
    {
        $targetWeekday = max(0, min(6, (int) ($this->weekday ?? 0)));
        $candidate = $from->copy()->hour($hour)->minute($minute)->second(0);

        while ($candidate->dayOfWeek !== $targetWeekday || $candidate->lessThanOrEqualTo($from)) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private function nextMonthlyRun(Carbon $from, int $hour, int $minute): Carbon
    {
        $targetDay = $this->boundedMonthDay();
        $candidate = $from->copy()->day(1)->hour($hour)->minute($minute)->second(0);
        $candidate->day(min($targetDay, $candidate->daysInMonth));

        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate->addMonthNoOverflow();
            $candidate->day(min($targetDay, $candidate->daysInMonth));
        }

        return $candidate;
    }

    private function boundedIntervalMinutes(): int
    {
        return max(5, min(1440, (int) ($this->interval_minutes ?: 60)));
    }

    private function boundedMonthDay(): int
    {
        return max(1, min(31, (int) ($this->month_day ?: 1)));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function timeParts(): array
    {
        $value = trim((string) $this->run_time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            $hour = max(0, min(23, (int) $matches[1]));
            $minute = max(0, min(59, (int) $matches[2]));

            return [$hour, $minute];
        }

        return [2, 0];
    }

    private function weekdayName(): string
    {
        return match ((int) ($this->weekday ?? 0)) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            default => 'Sunday',
        };
    }
}
