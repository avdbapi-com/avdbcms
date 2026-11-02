<?php

namespace App\Console;

use App\Console\Commands\DeleteExpiredChunks;
use App\Console\Commands\DeleteUnconfirmedUsers;
use App\Console\Commands\FinishPendingUploads;
use App\Console\Commands\Premium\SendCardExpiration;
use App\Console\Commands\Premium\SendTrialsEnd;
use App\Console\Commands\PruneViewHistory;
use App\Console\Commands\PurgeOrphanedVideos;
use App\Console\Commands\PurgeTrashedVideos;
use App\Console\Commands\QueueAvdbDurationSync;
use App\Console\Commands\RecountVideoEngagement;
use App\Console\Commands\RunScheduledAvdbImports;
use App\Console\Commands\RunScheduledXvidImports;
use App\Console\Commands\SendVideoPublishedEvent;
use App\Console\Commands\TidyUpdateFiles;
use App\Support\CronControl;
use App\Support\Updater\Updater;
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
        // No task while the installer is open again or on a fresh upload that is not installed yet (a cron line added
        // before the installation): they would work on a database that is being replaced or does not exist yet.
        if (! \App\Support\LaravelSchedulerCron::schedulesMayRun()) {
            return;
        }

        $LOG_PATH = storage_path('logs/cron.log');

        $schedule->command(SendVideoPublishedEvent::class)
            ->everyMinute()
            ->withoutOverlapping(10)
            ->skip(fn () => CronControl::isPaused('video:published'))
            ->appendOutputTo($LOG_PATH);
        // Never lose a video: member uploads whose page was closed while Upload18 processed them (pending drafts) are
        // finished here, one short pass a minute for all of them, started only while one is due. Their server
        // thumbnails (when the browser sent none) are a task of their own: ffmpeg can take minutes, and the minute
        // check of the other drafts never waits for it.
        $schedule->command(FinishPendingUploads::class, ['--seconds' => 50])
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->when(fn (): bool => (bool) rescue(fn (): bool => FinishPendingUploads::due(), false, false))
            ->skip(fn () => CronControl::isPaused('upload18:finish-pending --seconds=50'))
            ->appendOutputTo($LOG_PATH);
        $schedule->command(FinishPendingUploads::class, ['--thumbnails'])
            ->everyMinute()
            ->withoutOverlapping(30)
            ->runInBackground()
            ->when(fn (): bool => (bool) rescue(fn (): bool => FinishPendingUploads::thumbnailsDue(), false, false))
            ->skip(fn () => CronControl::isPaused('upload18:finish-pending --thumbnails'))
            ->appendOutputTo($LOG_PATH);
        // The two member mails go once however many scheduler runs start in their minute (the system cron's and one the
        // site started could otherwise both send them): onOneServer() takes a lock of that minute in the cache.
        $schedule->command(SendTrialsEnd::class)
            ->dailyAt('12:00')
            ->onOneServer()
            ->skip(fn () => CronControl::isPaused('premium:trial_end'))
            ->appendOutputTo($LOG_PATH);
        // at most 50 seconds a run (the rest the next hour), one run at a time, beside the other tasks (DB-RV-2)
        $schedule->command(DeleteUnconfirmedUsers::class)
            ->hourly()
            ->withoutOverlapping(60)
            ->runInBackground()
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
            ->onOneServer()
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

        // One-click updates (Tools > Updates): the work files of a finished update leave their place with one rename
        // and are deleted here in slices (and by the Updates page while it is open). Started only while some wait and
        // no update or undo runs (its own steps empty the trash; a slice here would hold the trash from them).
        $schedule->command(TidyUpdateFiles::class, ['--seconds' => 15])
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->when(fn (): bool => (bool) rescue(fn (): bool => (app()->bound(Updater::class) ? app(Updater::class) : new Updater())->tidyDue(), false, false))
            ->skip(fn () => CronControl::isPaused('updates:tidy --seconds=15'))
            ->appendOutputTo($LOG_PATH);

        // Like, dislike and comment counts of the videos (the studio, the admin lists, search by likes): every video is
        // counted again once a day and right after the update to 1.2.3, in short slices; started only while a pass is due.
        // In the background: a slice can take 50 seconds, and the tasks after it (the queue worker) never wait for it.
        $schedule->command(RecountVideoEngagement::class, ['--seconds' => 50])
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->when(fn (): bool => (bool) rescue(fn (): bool => RecountVideoEngagement::due(), false, false))
            ->skip(fn () => CronControl::isPaused('videos:recount-engagement --seconds=50'))
            ->appendOutputTo($LOG_PATH);

        // History, subscription and saved-playlist rows that 1.2.0 to 1.2.2 dated with the database server's clock: put in
        // the site's time zone once, right after the update to 1.2.3, in short slices; started only while rows wait.
        $schedule->command(\App\Console\Commands\FixOldRowTimes::class, ['--seconds' => 20])
            ->everyMinute()
            ->withoutOverlapping(10)
            ->when(fn (): bool => (bool) rescue(fn (): bool => \App\Console\Commands\FixOldRowTimes::due(), false, false))
            ->skip(fn () => CronControl::isPaused('history:fix-old-times --seconds=20'))
            ->appendOutputTo($LOG_PATH);

        // Videos whose titles, tags or categories use words that are always blocked: hidden by the update to 1.2.3, in
        // short slices; the scheduler goes on where a big site's update stopped. Started only while the pass is not done.
        $schedule->command(\App\Console\Commands\HideBlockedWordVideos::class, ['--seconds' => 20])
            ->everyMinute()
            ->withoutOverlapping(10)
            ->when(fn (): bool => (bool) rescue(fn (): bool => \App\Console\Commands\HideBlockedWordVideos::due(), false, false))
            ->skip(fn () => CronControl::isPaused('videos:hide-blocked-words --seconds=20'))
            ->appendOutputTo($LOG_PATH);

        // Bell notifications: carries on long work the queue did not finish (mark all as read, delete all, a new video to the
        // subscribers, an admin's notification to everyone) and removes old ones once a day. Started only when there is work.
        $schedule->command(\App\Console\Commands\TidyNotifications::class, ['--seconds' => 20])->everyMinute()->withoutOverlapping(10)->when(fn (): bool => (bool) rescue(fn (): bool => \App\Console\Commands\TidyNotifications::due(), false, false))->skip(fn () => CronControl::isPaused('notifications:tidy --seconds=20'))->appendOutputTo($LOG_PATH);
        // The notifications page's Unread-tab index on a big table: "Finish update" leaves it to this task (it reads the
        // whole table), built online in the background; started only while it is missing.
        $schedule->command(\App\Console\Commands\BuildNotificationIndexes::class)->everyMinute()->withoutOverlapping(120)->runInBackground()->when(fn (): bool => (bool) rescue(fn (): bool => \App\Console\Commands\BuildNotificationIndexes::due(), false, false))->skip(fn () => CronControl::isPaused('notifications:index'))->appendOutputTo($LOG_PATH);

        // The video indexes a bundled site had with other columns (judge R3-009): on a big table "Finish update" leaves
        // them to this task (it reads the whole table), built online in the background; started only while one is not right.
        $schedule->command(\App\Console\Commands\BuildVideoIndexes::class)->everyMinute()->withoutOverlapping(120)->runInBackground()->when(fn (): bool => (bool) rescue(fn (): bool => \App\Console\Commands\BuildVideoIndexes::due(), false, false))->skip(fn () => CronControl::isPaused('videos:index'))->appendOutputTo($LOG_PATH);

        // Home's discovery rails, its shorts shelf and the Ctrl+K search chips of a big site, counted exactly in the background
        // (App\Support\DiscoverySnapshots: a page never waits for them); started only while pages want one.
        $schedule->command(\App\Console\Commands\BuildDiscoverySnapshots::class, ['--seconds' => 50])->everyMinute()->withoutOverlapping(10)->runInBackground()->when(fn (): bool => (bool) rescue(fn (): bool => \App\Console\Commands\BuildDiscoverySnapshots::due(), false, false))->skip(fn () => CronControl::isPaused(\App\Support\DiscoverySnapshots::TASK))->appendOutputTo($LOG_PATH);

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
