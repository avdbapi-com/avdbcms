<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AvdbImportController;
use App\Models\AvdbImportSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The default AVDBAPI import schedule (Content > Import videos, avdb:run-scheduled; R1B-021): its weekday is stored as
 * the number the schedule form uses (0 = Sunday ... 6 = Saturday), Monday. It was inserted as the text 'monday' into
 * a small number column: MySQL and MariaDB in their non-strict mode stored 0 (switched to Weekly, the schedule ran
 * on Sundays: "Every Sunday at 00:15"), MySQL 8's strict mode refused the insert and no default schedule was made.
 * Schedules that exist are never changed (a stored 0 may be an owner's Sunday).
 */
class AvdbDefaultScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_default_schedule_stores_monday_as_the_number_the_form_uses(): void
    {
        $this->assertSame(0, AvdbImportSchedule::query()->count());

        $schedule = AvdbImportController::ensureDefaultScheduleExists();

        $this->assertInstanceOf(AvdbImportSchedule::class, $schedule);
        $raw = DB::table('avdb_import_schedules')->where('id', $schedule->id)->value('weekday');
        $this->assertTrue(is_int($raw) || ctype_digit((string) $raw), 'a number, as the form stores it: '.var_export($raw, true));
        $this->assertSame(1, (int) $raw, 'Monday');

        // switched to Weekly by the owner, it runs on Mondays at its time
        $schedule = $schedule->fresh();
        $schedule->frequency = AvdbImportSchedule::FREQUENCY_WEEKLY;
        $this->assertSame('Every Monday at 00:15', $schedule->scheduleLabel());
        Carbon::setTestNow(Carbon::create(2026, 10, 28, 12, 0, 0)); // a Wednesday
        $next = $schedule->calculateNextRunAt();
        $this->assertSame([Carbon::MONDAY, '00:15'], [$next->dayOfWeek, $next->format('H:i')]);
        $this->assertSame('2026-11-02', $next->toDateString());
    }

    public function test_a_schedule_that_exists_is_left_as_it_is(): void
    {
        // an owner's weekly schedule on Sundays (0): the default insert never runs while any schedule exists
        $owners = AvdbImportSchedule::query()->create([
            'name' => 'Sunday sync', 'enabled' => true, 'frequency' => AvdbImportSchedule::FREQUENCY_WEEKLY,
            'interval_minutes' => 60, 'run_time' => '03:00', 'weekday' => 0, 'month_day' => 1, 'skip_if_active' => true,
            'settings' => ['pages' => 1], 'next_run_at' => now()->addDay(),
        ]);

        $this->assertNull(AvdbImportController::ensureDefaultScheduleExists());

        $this->assertSame(1, AvdbImportSchedule::query()->count());
        $this->assertSame(0, (int) DB::table('avdb_import_schedules')->where('id', $owners->id)->value('weekday'));
        $this->assertSame('Every Sunday at 03:00', $owners->fresh()->scheduleLabel());
    }
}
