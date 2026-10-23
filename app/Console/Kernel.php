<?php

namespace App\Console;

use App\Console\Commands\DeleteExpiredChunks;
use App\Console\Commands\DeleteUnconfirmedUsers;
use App\Console\Commands\Premium\SendCardExpiration;
use App\Console\Commands\Premium\SendTrialsEnd;
use App\Console\Commands\PruneViewHistory;
use App\Console\Commands\PurgeOrphanedVideos;
use App\Console\Commands\PurgeTrashedVideos;
use App\Console\Commands\QueueAvdbDurationSync;
use App\Console\Commands\RunScheduledAvdbImports;
use App\Console\Commands\RunScheduledXvidImports;
use App\Console\Commands\SendVideoPublishedEvent;
use App\Support\CronControl;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Laravel\Scout\Console\ImportCommand;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $LOG_PATH = storage_path('logs/cron.log');

        $schedule->command(SendVideoPublishedEvent::class)
            ->everyMinute()
            ->withoutOverlapping(10)
            ->skip(fn () => CronControl::isPaused('video:published'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(SendTrialsEnd::class)
            ->dailyAt('12:00')
            ->skip(fn () => CronControl::isPaused('premium:trial_end'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(DeleteUnconfirmedUsers::class)
            ->hourly()
            ->skip(fn () => CronControl::isPaused('users:delete-unconfirmed'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(DeleteExpiredChunks::class)
            ->dailyAt('2:00')
            ->skip(fn () => CronControl::isPaused('videos:delete_expired_chunks'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(PruneViewHistory::class)
            ->dailyAt('2:20')
            ->skip(fn () => CronControl::isPaused('views:prune-history'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command('queue:cleanup-stale --realtime-hours=24 --queued-days=30 --failed-hours=168 --batch-hours=168 --import-run-days=30 --keep-import-runs=50 --session-days=7')
            ->dailyAt('2:40')
            ->withoutOverlapping(15)
            ->skip(fn () => CronControl::isPaused('queue:cleanup-stale --realtime-hours=24 --queued-days=30 --failed-hours=168 --batch-hours=168 --import-run-days=30 --keep-import-runs=50 --session-days=7'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(RunScheduledAvdbImports::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->skip(fn () => CronControl::isPaused('avdb:schedules:run'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(RunScheduledXvidImports::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->skip(fn () => CronControl::isPaused('xvid:schedules:run'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(QueueAvdbDurationSync::class, [
            '--limit' => 10,
            '--chunk' => 5,
            '--max-runtime' => 12,
            '--remote-concurrency' => 1,
            '--remote-timeout' => 2,
        ])
            ->everyFiveMinutes()
            ->withoutOverlapping(15)
            ->skip(fn () => CronControl::isPaused('avdb:queue-duration-sync --limit=10 --chunk=5 --max-runtime=12 --remote-concurrency=1 --remote-timeout=2'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(SendCardExpiration::class)
            ->twiceMonthly(1, 16, '09:30')
            ->lastDayOfMonth('09:30')
            ->skip(fn () => CronControl::isPaused('premium:card_expiration'))
            ->appendOutputTo($LOG_PATH);

        // Sync views_count for videos index Meilisearch
        $schedule->command(ImportCommand::class, ['App\Models\Video'])
            ->skip(fn () => CronControl::isPaused('scout:import App\Models\Video'))
            ->dailyAt('3:00');

        // Clear expired tokens
        $schedule->command('sanctum:prune-expired')
            ->daily()
            ->skip(fn () => CronControl::isPaused('sanctum:prune-expired'))
            ->appendOutputTo($LOG_PATH);

        // Deleting Expired Password Reset Tokens
        $schedule->command('auth:clear-resets')
            ->everyFifteenMinutes()
            ->skip(fn () => CronControl::isPaused('auth:clear-resets'))
            ->appendOutputTo($LOG_PATH);

        // Safety net: purge any lingering soft-deleted records daily
        $schedule->command(PurgeTrashedVideos::class)
            ->dailyAt('3:30')
            ->skip(fn () => CronControl::isPaused('cleanup:purge-trashed'))
            ->appendOutputTo($LOG_PATH);

        // Purge orphaned videos (deleted user) weekly
        $schedule->command(PurgeOrphanedVideos::class)
            ->weeklyOn(0, '4:00')
            ->skip(fn () => CronControl::isPaused('videos:purge-orphaned'))
            ->appendOutputTo($LOG_PATH);

        // Cron-driven queue worker.
        // The installer writes QUEUE_CONNECTION=database and typical panel / shared hosts
        // have no supervisor or daemon, so without this nothing ever drains the jobs
        // table (imports, uploads, notifications silently stay queued forever).
        // At most one worker runs per minute; it exits when the queue is empty or after
        // 50 seconds so it never overlaps the next scheduler tick. Admins running a real
        // daemon (Horizon / supervisor) can pause it from Admin > Cron.
        if (! in_array(config('queue.default'), ['sync', 'null'], true)) {
            $schedule->command('queue:work --stop-when-empty --max-time=50 --max-jobs=250 --tries=3')
                ->everyMinute()
                ->withoutOverlapping(10)
                ->runInBackground()
                ->skip(fn () => CronControl::isPaused('queue:work --stop-when-empty --max-time=50 --max-jobs=250 --tries=3'))
                ->appendOutputTo($LOG_PATH);
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
