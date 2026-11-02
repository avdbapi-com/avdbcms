<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use App\Support\PhpBinary;
use App\Support\QueueJobInspector;
use App\Support\ShellRunner;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class QueueMonitorController
{
    public function index(): View
    {
        $now = now()->timestamp;

        $queuedJobs = Schema::hasTable('jobs')
            ? DB::table('jobs')->orderByRaw('reserved_at IS NOT NULL DESC')->orderBy('available_at')->paginate(\App\Support\AdminPagination::perPage(null, 25), ['*'], 'jobs_page')->withQueryString()
            : collect();

        if (method_exists($queuedJobs, 'getCollection')) {
            $queuedJobs->getCollection()->transform(fn ($job) => $this->decorateQueuedJob($job, $now));
        }

        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->orderByDesc('failed_at')->paginate(\App\Support\AdminPagination::perPage(null, 25), ['*'], 'failed_page')->withQueryString()
            : collect();

        if (method_exists($failedJobs, 'getCollection')) {
            $failedJobs->getCollection()->transform(fn ($job) => $this->decorateFailedJob($job));
        }

        $batches = Schema::hasTable('job_batches')
            ? DB::table('job_batches')->orderByDesc('created_at')->paginate(\App\Support\AdminPagination::perPage(null, 25), ['*'], 'batches_page')->withQueryString()
            : collect();

        if (method_exists($batches, 'getCollection')) {
            $batches->getCollection()->transform(function ($batch) {
                $batch->progress = $batch->total_jobs > 0
                    ? round(($batch->total_jobs - $batch->pending_jobs) / $batch->total_jobs * 100)
                    : 0;

                return $batch;
            });
        }

        $stats = [
            'queued_total' => $this->jobsCount(),
            'queued_available' => $this->jobsCount(fn ($query) => $query->whereNull('reserved_at')->where('available_at', '<=', $now)),
            'queued_delayed' => $this->jobsCount(fn ($query) => $query->whereNull('reserved_at')->where('available_at', '>', $now)),
            'queued_reserved' => $this->jobsCount(fn ($query) => $query->whereNotNull('reserved_at')),
            'failed_total' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'failed_today' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->whereDate('failed_at', today())->count() : 0,
            'batches_total' => Schema::hasTable('job_batches') ? DB::table('job_batches')->count() : 0,
            'batches_pending' => Schema::hasTable('job_batches') ? DB::table('job_batches')->where('pending_jobs', '>', 0)->count() : 0,
            'queue_connection' => config('queue.default'),
            // A Redis that does not answer makes this check wait seconds: the new admin shows the answer of the
            // "redis" status probe (cached; null = asked after the page appears).
            'horizon_active' => AdminPreferences::usesNewAdmin() ? (StatusProbeController::peek('redis')['data']['horizon'] ?? null) : $this->horizonAvailable(),
        ];

        $queues = $this->queueNames();
        $activityLogs = $this->activityLogs('queue.');
        $workerLog = $this->readLogTail(storage_path('logs/queue-worker.log'), 80);
        $queueLog = $this->filteredLogTail((string) config('logging.channels.single.path', storage_path('logs/laravel.log')), ['queue', 'job', 'RunAvdbImport', 'UploadToUpload18'], 80);

        return view('admin.queue.index', compact(
            'queuedJobs',
            'failedJobs',
            'batches',
            'stats',
            'queues',
            'activityLogs',
            'workerLog',
            'queueLog'
        ));
    }

    public function startWorker(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'queue' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9_,:.-]+$/'],
            'max_jobs' => ['required', 'integer', 'min:1', 'max:500'],
            'max_time' => ['required', 'integer', 'min:5', 'max:3600'],
            'timeout' => ['required', 'integer', 'min:10', 'max:7200'],
            'tries' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $php = $this->phpBinary();
        if (! PhpBinary::usable($php)) {
            return back()->withErrors(__('No PHP command-line program was found for the queue worker.'));
        }

        $command = [
            $php,
            base_path('artisan'),
            'queue:work',
            (string) config('queue.default', 'database'),
            '--stop-when-empty',
            '--force',
            '--sleep=1',
            '--max-jobs='.(int) $data['max_jobs'],
            '--max-time='.(int) $data['max_time'],
            '--timeout='.(int) $data['timeout'],
            '--tries='.(int) $data['tries'],
        ];

        $queue = trim((string) ($data['queue'] ?? ''));
        if ($queue !== '') {
            $command[] = '--queue='.$queue;
        }

        $ok = ShellRunner::runBackground($command, storage_path('logs/queue-worker.log'), base_path());
        if (! $ok) {
            return back()->withErrors(__('The queue worker could not be started in the background. Check that PHP may start processes on this server.'));
        }

        $this->audit('queue.worker.start', 'Started worker: '.implode(' ', array_slice($command, 2)));

        return back()->withSuccess(__('Queue worker started'));
    }

    public function restartWorkers(): RedirectResponse
    {
        Artisan::call('queue:restart');
        $this->audit('queue.worker.restart', 'Requested queue worker restart.');

        return back()->withSuccess(__('Queue workers asked to restart'));
    }

    public function cleanupStale(Request $request): RedirectResponse
    {
        $realtimeHours = max(1, min(168, (int) $request->input('realtime_hours', 24)));
        $queuedDays = max(1, min(365, (int) $request->input('queued_days', 30)));
        $failedHours = max(1, min(8760, (int) $request->input('failed_hours', 168)));
        $batchHours = max(1, min(8760, (int) $request->input('batch_hours', 168)));
        $importRunDays = max(1, min(365, (int) $request->input('import_run_days', 30)));
        $keepImportRuns = max(0, min(500, (int) $request->input('keep_import_runs', 50)));

        Artisan::call('queue:cleanup-stale', [
            '--realtime-hours' => $realtimeHours,
            '--queued-days' => $queuedDays,
            '--failed-hours' => $failedHours,
            '--batch-hours' => $batchHours,
            '--import-run-days' => $importRunDays,
            '--keep-import-runs' => $keepImportRuns,
        ]);

        $output = trim(Artisan::output());
        $this->audit('queue.cleanup.stale', $output !== '' ? $output : 'Cleaned stale queue rows.');

        // The command's own lines (English, with the counts) stay in the admin actions log; the message is in the
        // admin's language.
        return back()->withSuccess(__('Stale queue rows cleaned. The counts are in Tools > Logs > Admin actions.'));
    }

    public function pauseJob(Request $request, int $id): RedirectResponse
    {
        $seconds = max(60, min(604800, (int) $request->input('seconds', 3600)));
        $updated = DB::table('jobs')->where('id', $id)->update([
            'reserved_at' => null,
            'available_at' => now()->addSeconds($seconds)->timestamp,
        ]);

        $this->audit('queue.job.pause', "Paused job #{$id} for {$seconds} seconds.");

        return back()->with($updated ? 'success' : 'error', $updated ? __('Job #:id paused.', ['id' => $id]) : __('Job #:id was not found.', ['id' => $id]));
    }

    public function resumeJob(int $id): RedirectResponse
    {
        $updated = DB::table('jobs')->where('id', $id)->update([
            'reserved_at' => null,
            'available_at' => now()->timestamp,
        ]);

        $this->audit('queue.job.resume', "Released job #{$id} to run now.");

        return back()->with($updated ? 'success' : 'error', $updated ? __('Job #:id is available now.', ['id' => $id]) : __('Job #:id was not found.', ['id' => $id]));
    }

    public function retryQueuedJob(int $id): RedirectResponse
    {
        $updated = DB::table('jobs')->where('id', $id)->update([
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'attempts' => 0,
        ]);

        $this->audit('queue.job.retry', "Reset queued job #{$id}.");

        return back()->with($updated ? 'success' : 'error', $updated ? __('Job #:id reset for retry.', ['id' => $id]) : __('Job #:id was not found.', ['id' => $id]));
    }

    public function deleteQueuedJob(int $id): RedirectResponse
    {
        $job = DB::table('jobs')->where('id', $id)->first();
        if (! $job) {
            return back()->with('error', __('Job #:id was not found.', ['id' => $id]));
        }

        $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
        if ($inspection['delete_protected']) {
            // in English like every stored detail (R3-070): Admin actions shows it in the reader's language
            $this->audit('queue.job.delete.blocked', "Blocked delete for protected job #{$id}: {$inspection['risk_key']}.");

            return back()->withErrors(__('Job #:id is protected: :reason', ['id' => $id, 'reason' => $inspection['risk_description']]));
        }

        $deleted = DB::table('jobs')->where('id', $id)->delete();
        $this->audit('queue.job.delete', "Deleted queued job #{$id}.");

        return back()->with($deleted ? 'success' : 'error', $deleted ? __('Job #:id deleted.', ['id' => $id]) : __('Job #:id was not found.', ['id' => $id]));
    }

    public function pauseAll(Request $request): RedirectResponse
    {
        $seconds = max(60, min(604800, (int) $request->input('seconds', 3600)));
        $queue = trim((string) $request->input('queue', ''));
        $query = DB::table('jobs');
        if ($queue !== '') {
            $query->where('queue', $queue);
        }

        $count = $query->update([
            'reserved_at' => null,
            'available_at' => now()->addSeconds($seconds)->timestamp,
        ]);

        $this->audit('queue.jobs.pause_all', "Paused {$count} queued ".Str::plural('job', $count).($queue ? " on {$queue}" : '').'.');

        return back()->withSuccess(trans_choice('Paused 1 queued job.|Paused :count queued jobs.', $count));
    }

    public function resumeAll(Request $request): RedirectResponse
    {
        $queue = trim((string) $request->input('queue', ''));
        $query = DB::table('jobs');
        if ($queue !== '') {
            $query->where('queue', $queue);
        }

        $count = $query->update([
            'reserved_at' => null,
            'available_at' => now()->timestamp,
        ]);

        $this->audit('queue.jobs.resume_all', "Released {$count} queued ".Str::plural('job', $count).($queue ? " on {$queue}" : '').'.');

        return back()->withSuccess(trans_choice('1 queued job can run now.|:count queued jobs can run now.', $count));
    }

    public function deleteAllQueued(Request $request): RedirectResponse
    {
        $queue = trim((string) $request->input('queue', ''));
        $query = DB::table('jobs')->select(['id', 'payload'])->orderBy('id');
        if ($queue !== '') {
            $query->where('queue', $queue);
        }

        $ids = [];
        $skipped = 0;

        $query->chunkById(100, function ($jobs) use (&$ids, &$skipped): void {
            foreach ($jobs as $job) {
                $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
                if ($inspection['delete_protected']) {
                    $skipped++;

                    continue;
                }

                $ids[] = (int) $job->id;
            }
        }, 'id');

        $count = $ids === [] ? 0 : DB::table('jobs')->whereIn('id', $ids)->delete();
        $this->audit('queue.jobs.delete_all', "Deleted {$count} deletable queued ".Str::plural('job', $count).($queue ? " on {$queue}" : '')."; kept {$skipped} protected ".Str::plural('job', $skipped).'.');

        return back()->withSuccess(trans_choice('Deleted 1 queued job.|Deleted :count queued jobs.', $count).self::kept($skipped));
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit('queue.failed.retry', "Retried failed job {$uuid}.");

        return back()->withSuccess(__('Sent back to the queue'));
    }

    public function retryAllFailed(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->audit('queue.failed.retry_all', 'Retried all failed jobs.');

        return back()->withSuccess(__('All failed jobs sent back to the queue'));
    }

    public function deleteFailed(string $uuid): RedirectResponse
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        if (! $job) {
            return back()->with('error', __('Failed job not found.'));
        }

        $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
        if ($inspection['delete_protected']) {
            // in English like every stored detail (R3-070): Admin actions shows it in the reader's language
            $this->audit('queue.failed.delete.blocked', "Blocked delete for protected failed job {$uuid}: {$inspection['risk_key']}.");

            return back()->withErrors(__('This failed job is protected: :reason', ['reason' => $inspection['risk_description']]));
        }

        try {
            Artisan::call('queue:forget', ['id' => $uuid]);
        } catch (\Throwable $e) {
            DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        }

        $this->audit('queue.failed.delete', "Deleted failed job {$uuid}.");

        return back()->withSuccess(__('Failed job deleted'));
    }

    public function flushFailed(): RedirectResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return back()->withSuccess(__('This database has no table of failed jobs.'));
        }

        $ids = [];
        $skipped = 0;

        DB::table('failed_jobs')
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$ids, &$skipped): void {
                foreach ($jobs as $job) {
                    $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
                    if ($inspection['delete_protected']) {
                        $skipped++;

                        continue;
                    }

                    $ids[] = (int) $job->id;
                }
            }, 'id');

        $count = $ids === [] ? 0 : DB::table('failed_jobs')->whereIn('id', $ids)->delete();
        $this->audit('queue.failed.flush', "Cleared {$count} deletable failed ".Str::plural('job', $count)."; kept {$skipped} protected ".Str::plural('job', $skipped).'.');

        return back()->withSuccess(trans_choice('Cleared 1 failed job.|Cleared :count failed jobs.', $count).self::kept($skipped));
    }

    /** " Kept 2 protected jobs." after a clean-up that left the jobs that must not be deleted (nothing when none). */
    private static function kept(int $skipped): string
    {
        return $skipped > 0 ? ' '.trans_choice('Kept 1 protected job.|Kept :count protected jobs.', $skipped) : '';
    }

    public function deleteBatch(string $id): RedirectResponse
    {
        $batch = DB::table('job_batches')->where('id', $id)->first();
        if (! $batch) {
            return back()->with('error', __('Batch not found.'));
        }

        if ((int) $batch->pending_jobs > 0 && $batch->finished_at === null && $batch->cancelled_at === null) {
            $this->audit('queue.batch.delete.blocked', "Blocked delete for active batch {$id}.");

            return back()->withErrors(__('This batch still has jobs waiting. Let it finish, or cancel it, before you delete it.'));
        }

        DB::table('job_batches')->where('id', $id)->delete();
        $this->audit('queue.batch.delete', "Deleted batch {$id}.");

        return back()->withSuccess(__('Batch deleted'));
    }

    private function decorateQueuedJob(object $job, int $now): object
    {
        $payload = json_decode((string) $job->payload, true);
        $payload = is_array($payload) ? $payload : [];

        $job->uuid = (string) ($payload['uuid'] ?? '');
        $job->display_name = (string) ($payload['displayName'] ?? $payload['job'] ?? 'Unknown job');
        $job->handler = (string) ($payload['job'] ?? '');
        $job->command_name = (string) data_get($payload, 'data.commandName', '');
        $job->summary = $job->command_name !== '' ? $job->command_name : class_basename($job->display_name);
        $inspection = QueueJobInspector::inspectPayload($payload);
        $job->risk_level = $inspection['risk_level'];
        $job->risk_label = $inspection['risk_label'];
        $job->risk_description = $inspection['risk_description'];
        $job->delete_protected = $inspection['delete_protected'];
        $job->state = $job->reserved_at
            ? 'reserved'
            : ((int) $job->available_at > $now ? 'delayed' : 'available');
        $job->available_label = Carbon::createFromTimestamp((int) $job->available_at)->diffForHumans();
        $job->created_label = Carbon::createFromTimestamp((int) $job->created_at)->diffForHumans();
        $job->reserved_label = $job->reserved_at
            ? Carbon::createFromTimestamp((int) $job->reserved_at)->diffForHumans()
            : null;

        return $job;
    }

    private function decorateFailedJob(object $job): object
    {
        $payload = json_decode((string) $job->payload, true);
        $payload = is_array($payload) ? $payload : [];

        $job->display_name = (string) ($payload['displayName'] ?? $payload['job'] ?? 'Unknown job');
        $job->summary = class_basename($job->display_name);
        $inspection = QueueJobInspector::inspectPayload($payload);
        $job->risk_level = $inspection['risk_level'];
        $job->risk_label = $inspection['risk_label'];
        $job->risk_description = $inspection['risk_description'];
        $job->delete_protected = $inspection['delete_protected'];

        return $job;
    }

    private function jobsCount(?callable $callback = null): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $query = DB::table('jobs');
        if ($callback) {
            $callback($query);
        }

        return (int) $query->count();
    }

    /**
     * @return array<int, string>
     */
    private function queueNames(): array
    {
        $queues = array_values(array_filter(array_map('strval', [
            config('queue.connections.database.queue'),
            config('queue.names.default'),
            config('queue.names.uploads'),
            config('queue.names.thumbnails'),
        ])));

        if (Schema::hasTable('jobs')) {
            $queues = array_merge($queues, DB::table('jobs')->distinct()->pluck('queue')->map(fn ($q) => (string) $q)->all());
        }

        return array_values(array_unique(array_filter($queues)));
    }

    private function phpBinary(): ?string
    {
        return PhpBinary::resolve(['QUEUE_WORKER_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
    }

    private function horizonAvailable(): bool
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return false;
        }

        try {
            Redis::connection((string) config('horizon.use', 'default'))->command('PING');
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function readLogTail(string $path, int $lines = 60): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $content = file($path, FILE_IGNORE_NEW_LINES);
        if (! is_array($content)) {
            return [];
        }

        return array_slice($content, -$lines);
    }

    /**
     * @param  array<int, string>  $needles
     * @return array<int, string>
     */
    private function filteredLogTail(string $path, array $needles, int $lines = 60): array
    {
        $haystack = $this->readLogTail($path, 800);
        $filtered = array_filter($haystack, static function (string $line) use ($needles): bool {
            foreach ($needles as $needle) {
                if (stripos($line, $needle) !== false) {
                    return true;
                }
            }

            return false;
        });

        return array_slice(array_values($filtered), -$lines);
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
