<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AvdbImportController;
use App\Http\Controllers\Admin\XvidImportController;
use App\Models\AvdbImportRun;
use App\Models\AvdbImportSchedule;
use App\Models\XvidImportRun;
use App\Models\XvidImportSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FreshInstallImportIdleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_fresh_schema_creates_an_inactive_xvid_template_with_valid_timing(): void
    {
        $schedule = XvidImportController::ensureDefaultScheduleExists();

        $this->assertNotNull($schedule);
        $this->assertFalse($schedule->is_active);
        $this->assertSame('hourly', $schedule->fresh()->frequency);
        $this->assertSame('Hourly at :30', $schedule->scheduleLabel());
        $this->assertTrue($schedule->skip_if_active);
        $this->assertSame('2026-09-23 11:30:00', $schedule->calculateNextRunAt(\Carbon\Carbon::parse('2026-09-23 11:10:00'))->format('Y-m-d H:i:s'));
        $this->assertIdle();
    }

    public function test_repeated_fresh_schedulers_do_not_start_either_provider(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('xvid:schedules:run')->assertExitCode(0);
            $this->artisan('avdb:schedules:run')->assertExitCode(0);
        }

        $this->assertSame(1, XvidImportSchedule::count());
        $this->assertSame(1, AvdbImportSchedule::count());
        $this->assertSame(0, XvidImportSchedule::where('is_active', true)->count());
        $this->assertSame(0, AvdbImportSchedule::where('enabled', true)->count());
        $this->assertIdle();
    }

    public function test_opening_the_xvid_dashboard_does_not_start_an_import(): void
    {
        Cache::forget('xvidapi_admin_api_summary_v4');
        Http::fake(['https://xvidapi.com/*' => Http::response([
            'code' => 1,
            'total' => 250,
            'pagecount' => 3,
            'limit' => 100,
        ])]);

        $controller = new XvidImportController();
        $controller->index();
        $controller->index();

        $this->assertSame(1, XvidImportSchedule::count());
        $this->assertFalse(XvidImportSchedule::firstOrFail()->is_active);
        $this->assertIdle(true);
    }

    public function test_deleting_the_last_template_does_not_reenable_automatic_imports(): void
    {
        XvidImportController::ensureDefaultScheduleExists()->delete();
        $this->artisan('xvid:schedules:run')->assertExitCode(0);

        $this->assertFalse(XvidImportSchedule::firstOrFail()->is_active);
        $this->assertIdle();
    }

    public function test_explicitly_created_schedule_keeps_the_selected_timing(): void
    {
        $response = (new XvidImportController())->storeSchedule(Request::create('/', 'POST', [
            'name' => 'User requested import',
            'frequency' => 'interval',
            'interval_minutes' => 15,
            'categories' => [65],
            'pages' => 1,
            'start_page' => 1,
            'pagesize' => 10,
            'delay' => 0,
            'skip_if_active' => true,
        ]));

        $this->assertTrue($response->getData(true)['success']);
        $schedule = XvidImportSchedule::firstOrFail();
        $this->assertTrue($schedule->is_active);
        $this->assertSame('interval', $schedule->frequency);
        $this->assertSame(15, $schedule->interval_minutes);
        $this->assertSame('Every 15 min', $schedule->scheduleLabel());
        $this->assertNull(XvidImportController::ensureDefaultScheduleExists());
        $this->assertTrue($schedule->fresh()->is_active);
        $this->assertIdle();
    }

    public function test_legacy_schedule_columns_remain_a_fallback(): void
    {
        // Hydrate legacy columns as they would be read from an older database;
        // fresh-schema mass assignment correctly ignores those absent columns.
        $schedule = (new XvidImportSchedule())->setRawAttributes([
            'frequency' => 'weekly',
            'run_time' => '04:15',
            'weekday' => 'monday',
            'skip_if_active' => false,
            'settings' => '{}',
        ]);

        $this->assertSame('weekly', $schedule->frequency);
        $this->assertSame(1, $schedule->weekday);
        $this->assertFalse($schedule->skip_if_active);
        $this->assertSame('2026-09-28 04:15:00', $schedule->calculateNextRunAt(\Carbon\Carbon::parse('2026-09-23 11:10:00'))->format('Y-m-d H:i:s'));
    }

    public function test_run_now_runs_a_paused_schedule_once_without_enabling_automatic_imports(): void
    {
        $previousMode = $_ENV['XVID_IMPORT_MODE'] ?? null;
        $_ENV['XVID_IMPORT_MODE'] = 'queue';

        try {
            $schedule = XvidImportController::ensureDefaultScheduleExists();
            $this->artisan('xvid:schedules:run', ['--id' => $schedule->id])->assertExitCode(0);

            $this->assertFalse($schedule->fresh()->is_active);
            $this->assertSame(1, XvidImportRun::count());
            $this->assertSame('queued', XvidImportRun::firstOrFail()->status);
            Queue::assertPushed(\App\Jobs\RunXvidImport::class, 1);

            // Make it due with no active run: only the paused flag should stop cron.
            XvidImportRun::query()->update(['status' => 'completed']);
            $schedule->update(['next_run_at' => now()->subMinute()]);
            $this->artisan('xvid:schedules:run')->assertExitCode(0);

            $this->assertSame(1, XvidImportRun::count());
            Queue::assertPushed(\App\Jobs\RunXvidImport::class, 1);
            Http::assertNothingSent();
        } finally {
            if ($previousMode === null) {
                unset($_ENV['XVID_IMPORT_MODE']);
            } else {
                $_ENV['XVID_IMPORT_MODE'] = $previousMode;
            }
        }
    }

    private function assertIdle(bool $metadataReadExpected = false): void
    {
        $this->assertSame(0, XvidImportRun::count());
        $this->assertSame(0, AvdbImportRun::count());
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
        if ($metadataReadExpected) {
            Http::assertSentCount(1);
            Http::assertSent(fn ($request) => $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_HOST) === 'xvidapi.com'
                && rtrim(parse_url($request->url(), PHP_URL_PATH) ?? '', '/') === '/api.php/provide/vod'
                && $request['ac'] === 'list'
                && $request['at'] === 'json'
                && (string) $request['pagesize'] === '100');
        } else {
            Http::assertNothingSent();
        }
    }
}
