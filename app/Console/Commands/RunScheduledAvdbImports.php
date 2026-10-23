<?php

namespace App\Console\Commands;

use App\Jobs\RunAvdbImport;
use App\Models\AvdbImportRun;
use App\Models\AvdbImportSchedule;
use App\Support\Environment;
use App\Support\PhpBinary;
use App\Support\RuntimeEnv;
use App\Support\ShellRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RunScheduledAvdbImports extends Command
{
    private const STALE_ACTIVE_RUN_MINUTES = 30;

    protected $signature = 'avdb:schedules:run {--id= : Run one schedule immediately}';

    protected $description = 'Run due AVDB import schedules';

    public function handle(): int
    {
        if (! Schema::hasTable('avdb_import_schedules')) {
            $this->line('AVDB import schedule table is not installed.');

            return self::SUCCESS;
        }

        \App\Http\Controllers\Admin\AvdbImportController::ensureDefaultScheduleExists();

        $this->markStaleActiveRuns();
        $this->syncFinishedScheduleStatuses();

        $scheduleId = $this->option('id');
        $query = AvdbImportSchedule::query()->where('enabled', true);

        if ($scheduleId !== null && $scheduleId !== '') {
            $query->whereKey((int) $scheduleId);
        } else {
            $query->where(function ($due): void {
                $due->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            });
        }

        $count = 0;
        $query->orderBy('id')->each(function (AvdbImportSchedule $schedule) use (&$count): void {
            $count++;
            $this->runSchedule($schedule);
        });

        $this->line("Checked {$count} AVDB import schedule(s).");

        return self::SUCCESS;
    }

    private function runSchedule(AvdbImportSchedule $schedule): void
    {
        $activeRun = AvdbImportRun::query()
            ->whereIn('status', AvdbImportSchedule::ACTIVE_RUN_STATUSES)
            ->latest('id')
            ->first();

        if ($activeRun && $schedule->skip_if_active) {
            $message = "Skipped because AVDB import run #{$activeRun->id} is active.";
            $schedule->forceFill([
                'last_status' => 'skipped',
                'last_message' => $message,
                'next_run_at' => $schedule->calculateNextRunAt(),
            ])->save();
            $this->line("Schedule #{$schedule->id}: {$message}");

            return;
        }

        $settings = $this->freshSettings((array) $schedule->settings);
        $run = AvdbImportRun::create([
            'status' => 'queued',
            'settings' => $settings,
            'message' => "Queued by cron schedule #{$schedule->id}.",
            'log' => '['.now()->format('H:i:s')."] Queued by cron schedule #{$schedule->id} ({$schedule->name}).",
        ]);

        $started = $this->startRun($run);
        if (! $started) {
            $run->forceFill([
                'status' => 'failed',
                'message' => 'Cron could not start the import. Check PHP process support, queue worker, or AVDB_IMPORT_PHP_BINARY.',
                'finished_at' => now(),
            ])->save();
        }

        $schedule->forceFill([
            'last_run_at' => now(),
            'last_run_id' => $run->id,
            'last_status' => $started ? 'started' : 'failed',
            'last_message' => $started ? "Started import run #{$run->id}." : "Could not start import run #{$run->id}.",
            'next_run_at' => $schedule->calculateNextRunAt(),
        ])->save();

        $this->line("Schedule #{$schedule->id}: ".($started ? "started run #{$run->id}." : "failed to start run #{$run->id}."));
    }

    private function markStaleActiveRuns(): void
    {
        AvdbImportRun::query()
            ->whereIn('status', ['starting', 'running'])
            ->where('cancel_requested', false)
            ->orderBy('id')
            ->each(function (AvdbImportRun $run): void {
                $lastProgressAt = $run->updated_at ?: $run->started_at ?: $run->created_at;
                if ($lastProgressAt && $lastProgressAt->gt(now()->subMinutes(self::STALE_ACTIVE_RUN_MINUTES))) {
                    return;
                }

                $message = 'Cancelled stale import run before cron scheduling because no progress was reported for over '.self::STALE_ACTIVE_RUN_MINUTES.' minutes.';
                $run->forceFill([
                    'status' => 'cancelled',
                    'cancel_requested' => true,
                    'message' => $message,
                    'progress' => 100,
                    'finished_at' => now(),
                ])->save();
                $run->appendLog($message);
                $this->warn("Run #{$run->id}: {$message}");
            });
    }

    private function syncFinishedScheduleStatuses(): void
    {
        AvdbImportSchedule::query()
            ->whereNotNull('last_run_id')
            ->whereIn('last_status', ['started', 'running', 'queued'])
            ->with('lastRun')
            ->orderBy('id')
            ->each(function (AvdbImportSchedule $schedule): void {
                $run = $schedule->lastRun;
                if (! $run || in_array((string) $run->status, AvdbImportSchedule::ACTIVE_RUN_STATUSES, true)) {
                    return;
                }

                $message = trim((string) $run->message);
                if ($message === '') {
                    $message = "Import run #{$run->id} {$run->status}.";
                }

                $schedule->forceFill([
                    'last_status' => (string) $run->status,
                    'last_message' => $message,
                ])->save();
            });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function freshSettings(array $settings): array
    {
        $settings['date_seed'] = (string) Str::uuid().'-'.Str::random(16);
        $settings['starter_views_seed'] = (string) Str::uuid().'-'.Str::random(16);

        return $settings;
    }

    private function startRun(AvdbImportRun $run): bool
    {
        $mode = strtolower(trim((string) RuntimeEnv::get('AVDB_IMPORT_MODE', 'auto')));
        if ($mode === 'queue') {
            return $this->dispatchQueueImport($run);
        }

        if ($mode === 'inline') {
            return $this->runInlineImport($run);
        }

        if ($mode !== 'inline' && $this->spawnProcessImport($run)) {
            return true;
        }

        return $this->runInlineImport($run);
    }

    private function dispatchQueueImport(AvdbImportRun $run): bool
    {
        try {
            RunAvdbImport::dispatch($run->id);
            $run->forceFill([
                'status' => 'queued',
                'message' => 'Queued by cron. Waiting for a Laravel queue worker.',
            ])->save();
            $run->appendLog('Dispatched to Laravel queue by cron schedule.');

            return true;
        } catch (\Throwable $e) {
            Log::warning('Scheduled AVDB import queue dispatch failed: '.$e->getMessage());

            return false;
        }
    }

    private function spawnProcessImport(AvdbImportRun $run): bool
    {
        if (! Environment::canRunProcesses()) {
            return false;
        }

        $php = $this->phpBinary();
        if (! PhpBinary::usable($php)) {
            return false;
        }

        $run->forceFill([
            'status' => 'starting',
            'message' => 'Starting background import process from cron.',
            'started_at' => now(),
            'updated_at' => now(),
        ])->save();
        $run->appendLog('Starting background import process from cron.');

        return ShellRunner::runBackground(
            [$php, base_path('artisan'), 'avdb:import', '--run-id='.$run->id],
            storage_path("logs/avdb-import-{$run->id}.log"),
            base_path()
        );
    }

    private function runInlineImport(AvdbImportRun $run): bool
    {
        try {
            @ignore_user_abort(true);
            @set_time_limit(0);
            $run->appendLog('Running scheduled import inline because no background process is available.');
            Artisan::call('avdb:import', ['--run-id' => $run->id]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Scheduled AVDB inline import failed: '.$e->getMessage());

            return false;
        }
    }

    private function phpBinary(): ?string
    {
        return PhpBinary::resolve(['AVDB_IMPORT_PHP_BINARY']);
    }
}
