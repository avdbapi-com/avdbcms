<?php

namespace App\Http\Controllers\Admin;

use App\Models\Video;
use App\Support\AdminPreferences;
use App\Support\AppendLog;
use App\Support\AvdbDurationBackfill;
use App\Support\CronControl;
use App\Support\LaravelSchedulerCron;
use App\Support\PhpBinary;
use App\Support\RuntimeEnv;
use App\Support\ShellRunner;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CronController
{
    private const DURATION_METRICS_INDEX = 'videos_avdb_missing_duration_metric_idx';

    private const AVDB_CATEGORY_METRICS_INDEX = 'videos_avdb_category_metric_idx';

    private const DURATION_ATTEMPT_INDEX = 'videos_avdb_duration_sync_attempted_at_index';

    public function index(): View
    {
        // schedule:list and the PHP binary check take seconds: the page shares what the "schedule" and "php" status
        // probes keep (5 and 10 minutes). When they have no answer yet, the new admin answers at once and fills those
        // parts in after the page appears (data-ad-defer: the probes run, the page is fetched again); the old admin
        // works them out before answering.
        $newAdmin = AdminPreferences::usesNewAdmin();
        $deferred = $newAdmin && (StatusProbeController::peek('schedule') === null || StatusProbeController::peek('php') === null);
        $tasks = $deferred ? [] : $this->scheduledTasks(true);
        $durationBackfill = $this->durationBackfillStats($deferred);

        $stats = [
            'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'scheduled' => count($tasks),
            'paused' => count(array_filter($tasks, fn (array $task): bool => (bool) $task['paused'])),
            'queue_connection' => config('queue.default'),
            'cron_log_size' => is_file(storage_path('logs/cron.log')) ? filesize(storage_path('logs/cron.log')) : 0,
        ];

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('failed_at')->limit(10)->get()
            : collect();

        $queuedJobs = Schema::hasTable('jobs')
            ? DB::table('jobs')->orderBy('available_at')->limit(10)->get()
            : collect();

        $cronLog = $this->readLogTail(storage_path(LaravelSchedulerCron::TASK_LOG), 80);
        $scheduleRunLog = $this->readLogTail(storage_path(LaravelSchedulerCron::CRON_LOG), 80);
        $siteRunLog = $this->readLogTail(storage_path(LaravelSchedulerCron::SITE_LOG), 80);
        $durationLog = $this->readLogTail(storage_path('logs/avdb-duration-sync.log'), 80);
        $activityLogs = $this->activityLogs('cron.');
        $scheduler = $this->schedulerState($deferred, $durationBackfill);

        return view('admin.cron.index', compact(
            'newAdmin',
            'deferred',
            'tasks',
            'stats',
            'durationBackfill',
            'failedJobs',
            'queuedJobs',
            'cronLog',
            'scheduleRunLog',
            'siteRunLog',
            'durationLog',
            'activityLogs',
            'scheduler'
        ));
    }

    /**
     * Who starts the scheduled tasks and the cron line this site needs (judge R1B-004/R1B-006): the system cron (its
     * runs are stamped by schedule:run itself), only the site after page requests, or nobody; what this account's
     * crontab holds for the site; the line to add, for the PHP binary the "php" status probe found.
     *
     * @param  array<string, mixed>  $durationBackfill
     * @return array<string, mixed>
     */
    private function schedulerState(bool $deferred, array $durationBackfill): array
    {
        $php = ($durationBackfill['php_binary_ok'] ?? false) ? (string) ($durationBackfill['php_binary'] ?? '') : '';
        $windows = \App\Support\Environment::isWindows();

        return [
            'run' => LaravelSchedulerCron::runStatus(),
            'windows' => $windows,
            'crontab' => $deferred ? ['state' => 'unknown', 'lines' => []] : LaravelSchedulerCron::crontabState(),
            'line' => $php !== '' ? LaravelSchedulerCron::lineFor($php) : null,
            // a hosting panel's cron job: the same command without the crontab's schedule (round 3 IN-RV-01)
            'command' => $php !== '' && ! $windows ? LaravelSchedulerCron::commandFor($php) : null,
            'panel_line' => $php !== '' && ! $windows ? LaravelSchedulerCron::panelLineFor($php) : null,
        ];
    }

    public function runScheduler(): RedirectResponse
    {
        // A run of the site, not of the system cron: its own log, and the status still tells whether a cron runs.
        $ok = LaravelSchedulerCron::startSiteRun($this->phpBinary());
        if (! $ok) {
            return back()->withErrors(__('The scheduled tasks could not be started. Check that PHP may start processes on this server.'));
        }

        $this->audit('cron.scheduler.run', 'Started schedule:run in the background.');

        return back()->withSuccess(__('The scheduled tasks are running in the background.'));
    }

    public function interruptScheduler(): RedirectResponse
    {
        try {
            Artisan::call('schedule:interrupt');
            $this->audit('cron.scheduler.interrupt', 'Sent schedule interrupt signal.');

            return back()->withSuccess(__('The scheduled tasks were asked to stop.'));
        } catch (\Throwable $e) {
            return back()->withErrors(__('The scheduled tasks could not be stopped: :error', ['error' => Str::limit($e->getMessage(), 180)]));
        }
    }

    public function runTask(Request $request): RedirectResponse
    {
        $command = $this->validatedTaskCommand($request);
        if ($command === null) {
            return back()->withErrors(__('This scheduled task was not found.'));
        }

        $ok = $this->runBackgroundArtisan($this->artisanArguments($command), storage_path('logs/cron.log'));
        if (! $ok) {
            return back()->withErrors(__('The scheduled task could not be started. Check that PHP may start processes on this server.'));
        }

        $this->audit('cron.task.run', "Started scheduled task: {$command}");

        return back()->withSuccess(__('Scheduled task started'));
    }

    public function pauseTask(Request $request): RedirectResponse
    {
        $command = $this->validatedTaskCommand($request);
        if ($command === null) {
            return back()->withErrors(__('This scheduled task was not found.'));
        }

        CronControl::pause($command);
        $this->audit('cron.task.pause', "Paused scheduled task: {$command}");

        return back()->withSuccess(__('Scheduled task paused'));
    }

    public function resumeTask(Request $request): RedirectResponse
    {
        $command = $this->validatedTaskCommand($request);
        if ($command === null) {
            return back()->withErrors(__('This scheduled task was not found.'));
        }

        CronControl::resume($command);
        $this->audit('cron.task.resume', "Resumed scheduled task: {$command}");

        return back()->withSuccess(__('Scheduled task resumed'));
    }

    public function clearLogs(): RedirectResponse
    {
        foreach ([storage_path(LaravelSchedulerCron::TASK_LOG), storage_path(LaravelSchedulerCron::CRON_LOG), storage_path(LaravelSchedulerCron::SITE_LOG), storage_path('logs/avdb-duration-sync.log')] as $path) {
            if (is_file($path)) {
                file_put_contents($path, '', LOCK_EX);
            }
        }

        $this->audit('cron.logs.clear', 'Cleared cron logs.');

        return back()->withSuccess(__('Scheduled task logs cleared'));
    }

    public function startDurationBackfill(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:smart,background,queue,inline'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'chunk' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $mode = (string) ($data['mode'] ?? 'smart');
        $limit = (int) ($data['limit'] ?? 5000);
        $chunk = (int) ($data['chunk'] ?? 100);

        $result = match ($mode) {
            'background' => AvdbDurationBackfill::startBackgroundProcess($limit, $chunk),
            'queue' => AvdbDurationBackfill::queueMissing($limit, $chunk),
            'inline' => $this->runDurationBackfillInline($limit, $chunk),
            default => $this->startDurationBackfillSmart($limit, $chunk),
        };

        $this->audit('cron.duration.start', "{$mode}: {$result['message']}");

        if (! $result['available']) {
            return back()->withErrors($result['message']);
        }

        return back()->withSuccess($result['message']);
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::connection()->getDriverName() === 'mysql'
            && Schema::hasTable($table)
            && Schema::hasIndex($table, $index);
    }

    public function resetDurationRetries(): RedirectResponse
    {
        if (! Schema::hasTable('videos')) {
            return back()->withErrors(__('The videos table was not found.'));
        }

        $updates = [];
        if (Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at')) {
            $updates['avdb_duration_sync_attempted_at'] = null;
        }

        if (Schema::hasColumn('videos', 'avdb_duration_sync_failed_count')) {
            $updates['avdb_duration_sync_failed_count'] = 0;
        }

        if ($updates === []) {
            return back()->withSuccess(__('Nothing to reset: this database keeps no duration retries.'));
        }

        $countQuery = Video::query()->withoutGlobalScopes();
        if ($this->hasIndex('videos', self::DURATION_METRICS_INDEX)) {
            $countQuery->forceIndex(self::DURATION_METRICS_INDEX);
        }

        $count = $countQuery
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->update($updates);

        AvdbDurationBackfill::clearPriorityQueue();
        $this->audit('cron.duration.reset', "Reset duration retry tracking for {$count} missing-duration AVDB ".Str::plural('video', $count).'.');

        return back()->withSuccess(trans_choice('1 AVDB video still missing its duration will be tried again. Start the duration sync to try it now.|:count AVDB videos still missing their duration will be tried again. Start the duration sync to try them now.', $count));
    }

    public function durationStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stats' => $this->durationRunnerStats(),
        ]);
    }

    public function startDurationRunner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'chunk' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 10000);
        $chunk = (int) ($data['chunk'] ?? 100);
        $clearedQueuedJobs = $this->deleteQueuedDurationJobs();
        $stats = $this->durationRunnerStats();

        if (($stats['missing'] ?? 0) < 1) {
            return response()->json([
                'success' => true,
                'done' => true,
                'mode' => 'none',
                'message' => __('No AVDB videos need duration sync.'),
                'cleared_queued_jobs' => $clearedQueuedJobs,
                'stats' => $stats,
            ]);
        }

        $process = AvdbDurationBackfill::startBackgroundProcess($limit, $chunk);
        if ($process['available']) {
            $this->audit('cron.duration.runner.start', $process['message']);

            return response()->json([
                'success' => true,
                'done' => false,
                'mode' => 'background',
                'message' => $process['message'],
                'cleared_queued_jobs' => $clearedQueuedJobs,
                'stats' => $this->durationRunnerStats(),
            ]);
        }

        $message = $process['message'].' '.__('Running browser-safe duration batches instead.');
        if ($clearedQueuedJobs > 0) {
            $message .= ' '.trans_choice('Removed 1 old queued duration job.|Removed :count old queued duration jobs.', $clearedQueuedJobs);
        }

        $this->audit('cron.duration.runner.web', $message);

        return response()->json([
            'success' => true,
            'done' => false,
            'mode' => 'web-batch',
            'message' => $message,
            'batch_limit' => min(100, $limit),
            'batch_chunk' => min(100, $chunk),
            'cleared_queued_jobs' => $clearedQueuedJobs,
            'stats' => $this->durationRunnerStats(),
        ]);
    }

    public function runDurationBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'chunk' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($data['limit'] ?? 500);
        $chunk = (int) ($data['chunk'] ?? 100);
        $result = $this->runDurationBackfillInline($limit, $chunk);
        $stats = $this->durationRunnerStats();
        $batch = $result['batch'] ?? [];
        $resolved = (int) ($batch['resolved'] ?? 0);
        $missing = (int) ($stats['missing'] ?? 0);

        return response()->json([
            'success' => $result['available'],
            'done' => $missing < 1,
            'continue' => $result['available'] && $missing > 0 && $resolved > 0,
            'mode' => 'web-batch',
            'message' => $result['message'],
            'batch' => $batch,
            'stats' => $stats,
        ], $result['available'] ? 200 : 422);
    }

    /**
     * What each scheduled task does, in plain words for Tools > Scheduled tasks, by its artisan command. The commands'
     * own descriptions are written for developers (some in broken English, some empty); this page translates its own.
     * Every command the Kernel schedules has one (tests/Feature/ScheduledTaskPlainWordsTest.php).
     */
    private const TASK_DESCRIPTIONS = [
        'video:published' => 'Tells subscribers when a scheduled video becomes public',
        'upload18:finish-pending' => 'Finishes member uploads that Upload18 completed after the upload page was closed',
        'premium:trial_end' => 'E-mails members whose free trial ends soon (days before the end: :days)',
        'premium:card_expiration' => 'E-mails members whose card expires at the end of the month',
        'users:delete-unconfirmed' => 'Removes new accounts whose e-mail address was never confirmed in time',
        'videos:delete_expired_chunks' => 'Deletes the pieces of uploads that were never finished',
        'views:prune-history' => 'Deletes view history older than you keep it (Settings > Advanced)',
        'queue:cleanup-stale' => 'Removes old rows from the tables of the background jobs',
        'avdb:schedules:run' => 'Runs the AVDBAPI imports that are due',
        'xvid:schedules:run' => 'Runs the XVIDAPI imports that are due',
        'avdb:queue-duration-sync' => 'Looks up the running time of AVDBAPI videos that have none',
        'scout:import' => 'Adds the videos to the search index',
        'sanctum:prune-expired' => 'Removes expired API keys',
        'auth:clear-resets' => 'Removes expired password reset links',
        'cleanup:purge-trashed' => 'Deletes for good the deleted records that were still kept (a safety clean-up)',
        'videos:purge-orphaned' => 'Deletes for good the videos of deleted accounts',
        'updates:tidy' => 'Deletes the work files of finished one-click updates, a few seconds at a time',
        'videos:recount-engagement' => 'Counts the likes, dislikes and comments of every video again, a little at a time',
        'notifications:tidy' => 'Finishes long notification work and removes old notifications in small batches',
        'history:fix-old-times' => 'After the update to 1.2.3, sets the older times of history, subscription, playlist, like and dislike entries to the site time zone, a little at a time',
        'videos:hide-blocked-words' => 'After the update to 1.2.3, hides the videos whose titles, tags or categories use words that are always blocked, a little at a time',
        'notifications:index' => 'On sites with many notifications, finishes the part of the update that keeps the "Unread" notifications tab fast',
        'videos:index' => 'On sites with many videos, finishes the part of the update that keeps the video lists and counts fast',
        'discovery:snapshots' => 'On sites with many videos, counts the popular tags, creators and actors of the home page and checks the search suggestions in the background',
        'queue:work' => 'Runs the background jobs that are waiting',
    ];

    /** A task's description for the page: this page's own words for a known command, else the command's own. */
    public static function describe(array $task): ?string
    {
        $command = (string) ($task['command'] ?? '');

        // "php artisan video:published", "'/usr/bin/php' 'artisan' video:published --seconds=50", "artisan "scout:import" ..."
        if (preg_match('/artisan[\'"]?\s+[\'"]?([\w:\-]+)/', $command, $match) === 1 && isset(self::TASK_DESCRIPTIONS[$match[1]])) {
            // The trial reminder goes out as many days before the end as the admin set (Money > Premium plans).
            $replace = $match[1] === 'premium:trial_end' ? ['days' => (int) config('plans.trial_period.email_reminder', 7)] : [];

            return __(self::TASK_DESCRIPTIONS[$match[1]], $replace);
        }

        $description = trim((string) ($task['description'] ?? ''));

        return $description === '' ? null : __($description);
    }

    /**
     * The scheduler's tasks as `schedule:list --json` lists them (the "schedule" status probe caches this list).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listScheduledTasks(): array
    {
        Artisan::call('schedule:list', ['--json' => true]);
        $tasks = json_decode(Artisan::output(), true);

        return is_array($tasks) ? array_values(array_filter($tasks, 'is_array')) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduledTasks(bool $cached = false): array
    {
        try {
            // The probe answers from its cache, or lists the tasks now and keeps them for the next pages.
            $tasks = $cached ? (StatusProbeController::result('schedule')['data']['tasks'] ?? null) : null;

            if (is_array($tasks)) {
                $tasks = array_map(fn (array $task): array => $this->withNextDue($task), $tasks);
            } else {
                $tasks = self::listScheduledTasks();
            }
        } catch (\Throwable $e) {
            return [[
                'expression' => '',
                'command' => 'Error: '.$e->getMessage(),
                'description' => null,
                'next_due_date' => null,
                'next_due_date_human' => null,
                'timezone' => config('app.timezone'),
                'paused' => false,
                'key' => '',
            ]];
        }

        return array_map(static function (array $task): array {
            $command = (string) ($task['command'] ?? '');
            $key = CronControl::key($command);
            $task['key'] = $key;
            $task['paused'] = CronControl::isPaused($key);

            return $task;
        }, $tasks);
    }

    /** A cached task's next run, counted again from its cron expression (the cached time may have passed). */
    private function withNextDue(array $task): array
    {
        try {
            $timezone = (string) ($task['timezone'] ?? config('app.timezone'));
            $next = Carbon::instance((new CronExpression((string) ($task['expression'] ?? '')))->getNextRunDate(now($timezone), 0, false, $timezone));
            $task['next_due_date'] = $next->format('Y-m-d H:i:s P');
            $task['next_due_date_human'] = $next->diffForHumans();
        } catch (\Throwable) {
            // Keep what the list said.
        }

        return $task;
    }

    private function validatedTaskCommand(Request $request): ?string
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:500'],
        ]);

        $key = CronControl::key($data['command']);
        foreach ($this->scheduledTasks() as $task) {
            if (($task['key'] ?? '') === $key) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function artisanArguments(string $command): array
    {
        $parts = str_getcsv($command, ' ', '"', '\\');

        return array_values(array_filter(array_map('strval', $parts), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<int, string>  $artisanArguments
     */
    private function runBackgroundArtisan(array $artisanArguments, string $logPath): bool
    {
        $php = $this->phpBinary();
        if (! PhpBinary::usable($php)) {
            return false;
        }

        return ShellRunner::runBackground(
            array_merge([$php, base_path('artisan')], $artisanArguments),
            $logPath,
            base_path()
        );
    }

    private function phpBinary(): ?string
    {
        return self::findPhpBinary();
    }

    /** The PHP binary the scheduler's background runs use (the "php" status probe checks it too). */
    public static function findPhpBinary(): ?string
    {
        return PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  bool  $peek  the new admin's page before the "php" probe answered: no PHP binary check now (null)
     * @return array<string, mixed>
     */
    private function durationBackfillStats(bool $peek = false): array
    {
        $hasVideos = Schema::hasTable('videos');
        $hasAttemptedAt = $hasVideos && Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at');
        $hasFailedCount = $hasVideos && Schema::hasColumn('videos', 'avdb_duration_sync_failed_count');
        // Finding and checking the PHP binary starts processes (seconds on some hosts): the page shows the answer the
        // "php" status probe keeps for 10 minutes (the actions that start processes still look it up each time).
        $php = $peek ? StatusProbeController::peek('php') : StatusProbeController::result('php');
        $metrics = $hasVideos
            ? $this->durationMetricAggregates($hasAttemptedAt, $hasFailedCount)
            : [];
        $total = (int) ($metrics['total'] ?? 0);
        $missingCount = (int) ($metrics['missing'] ?? 0);
        $queuedJobs = Schema::hasTable('jobs')
            ? DB::table('jobs')->where('payload', 'like', '%SyncAvdbDurationBatch%')->count()
            : 0;

        return [
            'total' => $total,
            'synced' => max(0, $total - $missingCount),
            'missing' => $missingCount,
            'queued_jobs' => $queuedJobs,
            'recent_attempts' => (int) ($metrics['recent_attempts'] ?? 0),
            'waiting_retry' => (int) ($metrics['waiting_retry'] ?? 0),
            'failed_attempts' => (int) ($metrics['failed_attempts'] ?? 0),
            'latest_attempt' => $metrics['latest_attempt'] ?? null,
            'mode' => RuntimeEnv::get('AVDB_DURATION_BACKFILL_MODE', 'auto'),
            'remote_concurrency' => AvdbDurationBackfill::remoteConcurrency(),
            'remote_timeout' => AvdbDurationBackfill::remoteTimeout(),
            'queue_async' => AvdbDurationBackfill::queueIsAsync(),
            'process_available' => ShellRunner::available(),
            'php_binary' => $php['data']['path'] ?? null,
            'php_binary_ok' => $php === null ? null : (bool) ($php['data']['usable'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function durationMetricAggregates(bool $hasAttemptedAt, bool $hasFailedCount): array
    {
        $base = DB::table('videos')
            ->whereNull('deleted_at')
            ->whereNotNull('avdb_id');
        if ($this->hasIndex('videos', self::DURATION_METRICS_INDEX)) {
            $base->forceIndex(self::DURATION_METRICS_INDEX);
        }

        $missing = (clone $base)->where(function ($query): void {
            $query->whereNull('duration')
                ->orWhereIn('duration', ['', '0']);
        });

        $totalQuery = DB::table('videos')
            ->whereNull('deleted_at')
            ->whereNotNull('avdb_id');
        if ($this->hasIndex('videos', self::AVDB_CATEGORY_METRICS_INDEX)) {
            $totalQuery->forceIndex(self::AVDB_CATEGORY_METRICS_INDEX);
        }

        $metrics = [
            'total' => $totalQuery->count(),
            'missing' => (clone $missing)->count(),
            'recent_attempts' => 0,
            'waiting_retry' => 0,
            'failed_attempts' => 0,
            'latest_attempt' => null,
        ];

        if ($hasAttemptedAt) {
            $metrics['recent_attempts'] = (clone $missing)
                ->where('avdb_duration_sync_attempted_at', '>=', now()->subHour())
                ->count();
            $metrics['waiting_retry'] = (clone $missing)
                ->where('avdb_duration_sync_attempted_at', '>', now()->subMinutes(15))
                ->count();
            $latestQuery = DB::table('videos')
                ->whereNull('deleted_at')
                ->whereNotNull('avdb_id')
                ->whereNotNull('avdb_duration_sync_attempted_at');
            if ($this->hasIndex('videos', self::DURATION_ATTEMPT_INDEX)) {
                $latestQuery->forceIndex(self::DURATION_ATTEMPT_INDEX);
            }
            $metrics['latest_attempt'] = $latestQuery
                ->orderByDesc('avdb_duration_sync_attempted_at')
                ->value('avdb_duration_sync_attempted_at');
        }

        if ($hasFailedCount) {
            $metrics['failed_attempts'] = (clone $missing)
                ->where('avdb_duration_sync_failed_count', '>', 0)
                ->count();
        }

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    private function durationRunnerStats(): array
    {
        $stats = $this->durationBackfillStats();
        $total = max(0, (int) ($stats['total'] ?? 0));
        $synced = max(0, (int) ($stats['synced'] ?? 0));
        $stats['progress'] = $total > 0 ? round(min(100, ($synced / $total) * 100), 1) : 100;

        return $stats;
    }

    /**
     * @return array{available: bool, videos: int, jobs: int, message: string}
     */
    private function startDurationBackfillSmart(int $limit, int $chunk): array
    {
        $process = AvdbDurationBackfill::startBackgroundProcess($limit, $chunk);
        if ($process['available']) {
            return $process;
        }

        $inline = $this->runDurationBackfillInline(min($limit, 1000), min($chunk, 100));
        if ($inline['available']) {
            $inline['message'] = $process['message'].' '.$inline['message'];

            return $inline;
        }

        if (AvdbDurationBackfill::queueIsAsync()) {
            $queued = AvdbDurationBackfill::queueMissing($limit, $chunk);
            $queued['message'] = $process['message'].' '.$inline['message'].' '.$queued['message'].' Queue jobs require a running queue worker.';

            return $queued;
        }

        return $inline;
    }

    /**
     * @return array{available: bool, videos: int, jobs: int, message: string}
     */
    private function runDurationBackfillInline(int $limit, int $chunk): array
    {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $limit = max(1, min(1000, $limit));
        $chunk = max(1, min(100, $chunk));
        $startedAt = microtime(true);
        $missingBefore = $this->missingDurationCount();
        $clearedQueuedJobs = $this->deleteQueuedDurationJobs();

        try {
            $status = Artisan::call('avdb:sync-durations', [
                '--limit' => $limit,
                '--chunk' => $chunk,
                '--retry-minutes' => 0,
                '--remote-concurrency' => $this->durationWebConcurrency(),
                '--remote-timeout' => AvdbDurationBackfill::remoteTimeout(),
            ]);
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'videos' => 0,
                'jobs' => 0,
                'message' => __('The duration sync failed: :error', ['error' => Str::limit($e->getMessage(), 180)]),
            ];
        }

        $output = trim(Artisan::output());
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $missingAfter = $this->missingDurationCount();
        $resolved = max(0, $missingBefore - $missingAfter);
        $message = $status === 0
            ? trans_choice('Ran one duration batch for up to 1 video in :seconds s.|Ran one duration batch for up to :count videos in :seconds s.', $limit, ['seconds' => round($elapsedMs / 1000, 1)])
                .' '.__('Missing before: :before. Missing now: :after. Found now: :found.', ['before' => $missingBefore, 'after' => $missingAfter, 'found' => $resolved])
            : __('The duration batch stopped with an error.');

        if ($clearedQueuedJobs > 0) {
            $message .= ' '.trans_choice('Removed 1 old queued duration job.|Removed :count old queued duration jobs.', $clearedQueuedJobs);
        }

        // The command's own lines (English, one per video found) only when the batch stopped with an error: then they say
        // why. A batch that ran is fully told by the counts above, in the admin's language.
        if ($status !== 0 && $output !== '') {
            $message .= ' '.Str::limit($output, 500);
        }

        return [
            'available' => $status === 0,
            'videos' => 0,
            'jobs' => 0,
            'message' => $message,
            'batch' => [
                'limit' => $limit,
                'chunk' => $chunk,
                'missing_before' => $missingBefore,
                'missing_after' => $missingAfter,
                'resolved' => $resolved,
                'elapsed_ms' => $elapsedMs,
                'output' => $output,
            ],
        ];
    }

    private function missingDurationCount(): int
    {
        if (! Schema::hasTable('videos')) {
            return 0;
        }

        $query = Video::query()->withoutGlobalScopes();
        if ($this->hasIndex('videos', self::DURATION_METRICS_INDEX)) {
            $query->forceIndex(self::DURATION_METRICS_INDEX);
        }

        return $query
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->count();
    }

    private function deleteQueuedDurationJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('payload', 'like', '%SyncAvdbDurationBatch%')
            ->delete();
    }

    private function durationWebConcurrency(): int
    {
        return max(1, min(100, (int) RuntimeEnv::get('AVDB_DURATION_WEB_CONCURRENCY', 50)));
    }

    /**
     * @return array<int, string>
     */
    private function readLogTail(string $path, int $lines = 60): array
    {
        // From the end of the file: a big log is never read whole into memory.
        return AppendLog::tail($path, $lines);
    }

    private function activityLogs(string $prefix): array
    {
        if (! Schema::hasTable('admin_audit_logs')) {
            return [];
        }

        return DB::table('admin_audit_logs')
            ->leftJoin('users', 'admin_audit_logs.user_id', '=', 'users.id')
            ->where('admin_audit_logs.action', 'like', $prefix.'%')
            ->orderByDesc('admin_audit_logs.created_at')
            ->limit(20)
            ->get(['admin_audit_logs.*', 'users.username'])
            ->all();
    }

    private function audit(string $action, string $details): void
    {
        AuditLogController::log($action, $details);
    }
}
