<?php

namespace App\Jobs;

use App\Models\XvidImportRun;
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

class RunXvidImport implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200; // 2 hours
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
        return 'xvid-import:' . $this->runId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('xvid-import'))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        $run = XvidImportRun::query()->find($this->runId);
        if (! $run) {
            Log::warning("RunXvidImport: run {$this->runId} not found; job skipped.");
            return;
        }

        if ($run->status !== 'queued') {
            Log::info("RunXvidImport: run {$this->runId} is '{$run->status}', so the queued job was skipped.");
            return;
        }

        $run->appendLog('Queue worker picked up XVIDAPI import run.');

        try {
            Artisan::call('xvid:import', ['--run-id' => $this->runId]);
        } catch (Throwable $e) {
            $this->markFailed($run, $e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = XvidImportRun::query()->find($this->runId);
        if ($run) {
            $this->markFailed($run, $exception);
        }
    }

    private function markFailed(XvidImportRun $run, Throwable $e): void
    {
        $message = Str::limit($e->getMessage(), 450);
        $run->forceFill([
            'status' => 'failed',
            'message' => $message,
            'finished_at' => now(),
        ])->save();
        $run->appendLog("Queue worker failed: {$e->getMessage()}");
    }
}
