<?php

namespace Tests\Unit;

use App\Models\AvdbImportSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class AvdbImportScheduleTest extends TestCase
{
    public function test_daily_schedule_moves_to_next_day_when_time_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-09 03:00:00'));

        $schedule = new AvdbImportSchedule([
            'frequency' => AvdbImportSchedule::FREQUENCY_DAILY,
            'run_time' => '02:00',
        ]);

        $this->assertSame('2026-05-10 02:00:00', $schedule->calculateNextRunAt()->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_interval_schedule_uses_bounded_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-09 03:00:00'));

        $schedule = new AvdbImportSchedule([
            'frequency' => AvdbImportSchedule::FREQUENCY_INTERVAL,
            'interval_minutes' => 30,
        ]);

        $this->assertSame('2026-05-09 03:30:00', $schedule->calculateNextRunAt()->toDateTimeString());

        Carbon::setTestNow();
    }
}
