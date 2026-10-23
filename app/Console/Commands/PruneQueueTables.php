<?php

namespace App\Console\Commands;

use App\Models\AvdbImportRun;
use App\Support\QueueJobInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneQueueTables extends Command
{
    private const CLEANUP_CHUNK_SIZE = 500;

    protected $signature = 'queue:cleanup-stale
        {--realtime-hours=24 : Delete stale realtime broadcast jobs older than this many hours}
        {--queued-days=30 : Delete non-reserved queued jobs older than this many days}
        {--failed-hours=168 : Delete failed jobs older than this many hours}
        {--batch-hours=168 : Delete finished/cancelled batch metadata older than this many hours}
        {--import-run-days=30 : Delete finished AVDB import run history older than this many days}
        {--keep-import-runs=50 : Always keep this many newest AVDB import runs}
        {--session-days=7 : Delete stale database sessions older than this many days}';

    protected $description = 'Prune stale queue rows, failed jobs, batch metadata, and obsolete AVDB import stubs';

    public function handle(): int
    {
        $realtimeHours = max(1, (int) $this->option('realtime-hours'));
        $queuedDays = max(1, (int) $this->option('queued-days'));
        $failedHours = max(1, (int) $this->option('failed-hours'));
        $batchHours = max(1, (int) $this->option('batch-hours'));
        $importRunDays = max(1, (int) $this->option('import-run-days'));
        $keepImportRuns = max(0, (int) $this->option('keep-import-runs'));
        $sessionDays = max(1, (int) $this->option('session-days'));

        $deletedImportStubs = $this->deleteObsoleteAvdbImportJobs();
        $deletedRealtime = $this->deleteStaleRealtimeJobs($realtimeHours);
        $deletedQueued = $this->deleteOldQueuedJobs($queuedDays);
        $deletedFailed = $this->deleteOldFailedJobs($failedHours);
        $deletedBatches = $this->deleteOldBatches($batchHours);
        [$deletedImportRuns, $deletedImportFiles] = $this->deleteOldAvdbImportRuns($importRunDays, $keepImportRuns);
        $deletedSessions = $this->deleteOldSessions($sessionDays);

        $this->info("Deleted {$deletedImportStubs} obsolete AVDB import queue stubs.");
        $this->info("Deleted {$deletedRealtime} stale realtime broadcast job(s) older than {$realtimeHours} hour(s).");
        $this->info("Deleted {$deletedQueued} queued jobs older than {$queuedDays} day(s).");
        $this->info("Deleted {$deletedFailed} failed jobs older than {$failedHours} hour(s).");
        $this->info("Deleted {$deletedBatches} batch rows older than {$batchHours} hour(s).");
        $this->info("Deleted {$deletedImportRuns} old AVDB import run row(s) and {$deletedImportFiles} import log/export file(s).");
        $this->info("Deleted {$deletedSessions} stale DB session row(s) older than {$sessionDays} day(s).");

        return self::SUCCESS;
    }

    private function deleteObsoleteAvdbImportJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $deleted = 0;
        DB::table('jobs')
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(self::CLEANUP_CHUNK_SIZE, function ($jobs) use (&$deleted): void {
                $ids = [];
                foreach ($jobs as $job) {
                    $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
                    if (($inspection['cleanup_group'] ?? null) === 'obsolete_import') {
                        $ids[] = (int) $job->id;
                    }
                }

                if ($ids !== []) {
                    $deleted += (int) DB::table('jobs')->whereIn('id', $ids)->delete();
                }
            }, 'id');

        return $deleted;
    }

    private function deleteOldQueuedJobs(int $queuedDays): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $now = now()->timestamp;
        $cutoff = now()->subDays($queuedDays)->timestamp;
        $deleted = 0;

        DB::table('jobs')
            ->select(['id', 'payload'])
            ->whereNull('reserved_at')
            ->where('created_at', '<', $cutoff)
            ->where('available_at', '<', $now)
            ->orderBy('id')
            ->chunkById(self::CLEANUP_CHUNK_SIZE, function ($jobs) use (&$deleted): void {
                $ids = [];
                foreach ($jobs as $job) {
                    $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
                    if (! $inspection['delete_protected']) {
                        $ids[] = (int) $job->id;
                    }
                }

                if ($ids !== []) {
                    $deleted += (int) DB::table('jobs')->whereIn('id', $ids)->delete();
                }
            }, 'id');

        return $deleted;
    }

    private function deleteStaleRealtimeJobs(int $realtimeHours): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        $now = now()->timestamp;
        $cutoff = now()->subHours($realtimeHours)->timestamp;
        $deleted = 0;

        DB::table('jobs')
            ->select(['id', 'payload'])
            ->whereNull('reserved_at')
            ->where('created_at', '<', $cutoff)
            ->where('available_at', '<', $now)
            ->orderBy('id')
            ->chunkById(self::CLEANUP_CHUNK_SIZE, function ($jobs) use (&$deleted): void {
                $ids = [];
                foreach ($jobs as $job) {
                    $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
                    if (($inspection['cleanup_group'] ?? null) === 'realtime') {
                        $ids[] = (int) $job->id;
                    }
                }

                if ($ids !== []) {
                    $deleted += (int) DB::table('jobs')->whereIn('id', $ids)->delete();
                }
            }, 'id');

        return $deleted;
    }

    private function deleteOldFailedJobs(int $failedHours): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        $deleted = 0;
        $cutoff = now()->subHours($failedHours);

        DB::table('failed_jobs')
            ->select(['id', 'payload'])
            ->where('failed_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CLEANUP_CHUNK_SIZE, function ($jobs) use (&$deleted): void {
                $ids = [];
                foreach ($jobs as $job) {
                    $inspection = QueueJobInspector::inspectPayload((string) $job->payload);
                    if (! $inspection['delete_protected']) {
                        $ids[] = (int) $job->id;
                    }
                }

                if ($ids !== []) {
                    $deleted += (int) DB::table('failed_jobs')->whereIn('id', $ids)->delete();
                }
            }, 'id');

        return $deleted;
    }

    private function deleteOldBatches(int $batchHours): int
    {
        if (! Schema::hasTable('job_batches')) {
            return 0;
        }

        $cutoff = now()->subHours($batchHours)->timestamp;

        return DB::table('job_batches')
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($finished) use ($cutoff): void {
                    $finished->whereNotNull('finished_at')->where('finished_at', '<', $cutoff);
                })->orWhere(function ($cancelled) use ($cutoff): void {
                    $cancelled->whereNotNull('cancelled_at')->where('cancelled_at', '<', $cutoff);
                });
            })
            ->delete();
    }

    /**
     * @return array{0:int,1:int}
     */
    private function deleteOldAvdbImportRuns(int $importRunDays, int $keepImportRuns): array
    {
        if (! Schema::hasTable('avdb_import_runs')) {
            return [0, 0];
        }

        $keepIds = $keepImportRuns > 0
            ? AvdbImportRun::query()->latest('id')->limit($keepImportRuns)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $runIds = AvdbImportRun::query()
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('finished_at', '<', now()->subDays($importRunDays))
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($runIds === []) {
            return [0, 0];
        }

        $deletedFiles = 0;
        foreach ($runIds as $runId) {
            foreach (glob(storage_path("logs/avdb-import-{$runId}*")) ?: [] as $path) {
                if (is_file($path) && @unlink($path)) {
                    $deletedFiles++;
                }
            }
        }

        $deletedRows = AvdbImportRun::query()->whereIn('id', $runIds)->delete();

        return [(int) $deletedRows, $deletedFiles];
    }

    private function deleteOldSessions(int $sessionDays): int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return 0;
        }

        $cutoff = now()->subDays($sessionDays)->timestamp;
        $deleted = 0;

        while (true) {
            $ids = DB::table('sessions')
                ->select('id')
                ->where('last_activity', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += (int) DB::table('sessions')->whereIn('id', $ids)->delete();

            if (count($ids) < self::CLEANUP_CHUNK_SIZE) {
                break;
            }
        }

        return $deleted;
    }
}
