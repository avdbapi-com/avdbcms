<?php

namespace App\Jobs;

use App\Models\AvdbImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queue-backed runner for AVDB imports.
 *
 * Supersedes the `popen('php artisan avdb:import …', 'r')` background
 * fork that only worked on trusted hosts. Running the import through
 * the queue means:
 *
 *   - No dependency on `popen` / `proc_open` being enabled.
 *   - The admin UI sees progress through the same `avdb_import_runs`
 *     rows as before, because `avdb:import` already writes to them.
 *   - Retries and cancellation are centralised in Horizon.
 *   - One import per process, thanks to a WithoutOverlapping lock keyed
 *     on the run id, so two admins cannot queue conflicting runs.
 */
class RunAvdbImport implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200; // 2 hours — imports can be long when full-lang is on

    public int $tries = 1;

    public int $backoff = 30;

    public bool $deleteWhenMissingModels = true;

    public int $runId;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
        $this->onQueue((string) (config('site.avdb_import_queue') ?: config('queue.names.default', 'default')));
    }

    public function uniqueId(): string
    {
        return 'avdb-import:' . $this->runId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('avdb-import'))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $run = AvdbImportRun::query()->find($this->runId);
        if (! $run) {
            Log::warning("RunAvdbImport: run {$this->runId} not found; job skipped.");
            return;
        }

        if ($run->status !== 'queued') {
            Log::info("RunAvdbImport: run {$this->runId} is '{$run->status}', so the queued job was skipped.");
            return;
        }

        $run->appendLog('Queue worker picked up import run.');

        try {
            Artisan::call('avdb:import', ['--run-id' => $this->runId]);
        } catch (Throwable $e) {
            $this->markFailed($run, $e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = AvdbImportRun::query()->find($this->runId);
        if ($run) {
            $this->markFailed($run, $exception);
        }
    }

    private function markFailed(AvdbImportRun $run, Throwable $e): void
    {
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $run->forceFill([
            'status' => 'failed',
            'message' => 'Queue job failed: ' . Str::limit($e->getMessage(), 300, ''),
            'finished_at' => now(),
        ])->save();
    }
}
