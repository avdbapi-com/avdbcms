<?php

namespace App\Console\Commands;

use App\Jobs\RunXvidImport;
use App\Models\XvidImportRun;
use App\Models\XvidImportSchedule;
use App\Support\Environment;
use App\Support\PhpBinary;
use App\Support\RuntimeEnv;
use App\Support\ShellRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RunScheduledXvidImports extends Command
{
    private const STALE_ACTIVE_RUN_MINUTES = 30;

    protected $signature = 'xvid:schedules:run {--id= : Run one schedule immediately}';

    protected $description = 'Run due XVIDAPI import schedules';

    public function handle(): int
    {
        if (! Schema::hasTable('xvid_import_schedules')) {
            $this->line('XVIDAPI import schedule table is not installed.');
            return self::SUCCESS;
        }

        \App\Http\Controllers\Admin\XvidImportController::ensureDefaultScheduleExists();

        $this->markStaleActiveRuns();

        $scheduleId = $this->option('id');
        $query = XvidImportSchedule::query();

        if ($scheduleId !== null && $scheduleId !== '') {
            // Run Now is an explicit one-off request; it does not enable cron.
            $query->whereKey((int) $scheduleId);
        } else {
            $query->where('is_active', true)->where(function ($due): void {
                $due->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            });
        }

        $count = 0;
        $query->orderBy('id')->each(function (XvidImportSchedule $schedule) use (&$count): void {
            $count++;
            $this->runSchedule($schedule);
        });

        $this->line("Checked {$count} XVIDAPI import schedule(s).");

        return self::SUCCESS;
    }

    private function runSchedule(XvidImportSchedule $schedule): void
    {
        $activeRun = XvidImportRun::query()
            ->whereIn('status', XvidImportSchedule::ACTIVE_RUN_STATUSES)
            ->latest('id')
            ->first();

        if ($activeRun && $schedule->skip_if_active) {
            $message = "Skipped because XVIDAPI import run #{$activeRun->id} is active.";
            $schedule->forceFill([
                'last_run_at' => now(),
                'next_run_at' => $schedule->calculateNextRunAt(),
            ])->save();
            $this->line("Schedule #{$schedule->id}: {$message}");
            return;
        }

        $settings = (array) $schedule->settings;
        // Older schedules may carry an empty category list (the form had no selector); treat it as "default set".
        $categories = array_values(array_filter(array_map('intval', (array) ($schedule->categories ?? []))));
        if ($categories === []) {
            $categories = array_values(array_filter(array_map('intval', (array) ($settings['categories'] ?? []))));
        }
        $settings['categories'] = $categories !== [] ? $categories : \App\Http\Controllers\Admin\XvidImportController::DEFAULT_SCHEDULE_CATEGORIES;
        $settings['pages'] = (int) ($schedule->pages ?? $settings['pages'] ?? 1);
        $settings['start_page'] = (int) ($schedule->start_page ?? $settings['start_page'] ?? 1);
        $settings['pagesize'] = (int) ($schedule->pagesize ?? $settings['pagesize'] ?? 50);
        $settings['delay'] = (int) ($schedule->delay ?? $settings['delay'] ?? 0);
        $settings['date_seed'] = (string) Str::uuid();

        $run = XvidImportRun::create([
            'status' => 'queued',
            'settings' => $settings,
            'message' => "Queued by cron schedule #{$schedule->id}.",
            'log' => '['.now()->format('H:i:s')."] Queued by cron schedule #{$schedule->id} ({$schedule->name}).",
        ]);

        $started = $this->startRun($run);
        if (! $started) {
            $run->forceFill([
                'status' => 'failed',
                'message' => 'Cron could not start the import.',
                'finished_at' => now(),
            ])->save();
        }

        $schedule->forceFill([
            'last_run_at' => now(),
            'last_run_id' => $run->id,
            'next_run_at' => $schedule->calculateNextRunAt(),
        ])->save();
    }

    private function startRun(XvidImportRun $run): bool
    {
        $mode = strtolower(trim((string) RuntimeEnv::get('XVID_IMPORT_MODE', 'auto')));
        if ($mode === 'queue') {
            return $this->dispatchQueueImport($run);
        }

        if ($mode === 'inline') {
            return $this->runInlineImport($run);
        }

        if (Environment::canRunProcesses() && $this->spawnProcessImport($run)) {
            return true;
        }

        if ($this->dispatchQueueImport($run)) {
            return true;
        }

        return $this->runInlineImport($run);
    }

    private function dispatchQueueImport(XvidImportRun $run): bool
    {
        try {
            // A failed subprocess start may already have marked the run as starting.
            // Queue workers accept only queued runs.
            $run->forceFill(['status' => 'queued'])->save();
            RunXvidImport::dispatch($run->id);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Queue dispatch for XVIDAPI schedule failed: ' . $e->getMessage());

            return false;
        }
    }

    private function spawnProcessImport(XvidImportRun $run): bool
    {
        if (! Environment::canRunProcesses()) {
            return false;
        }

        $php = (string) (config('site.xvid_import_php_binary')
            ?: config('site.avdb_import_php_binary')
            ?: PhpBinary::resolve(['XVID_IMPORT_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY'])
            ?: PHP_BINARY);

        if (! PhpBinary::usable($php)) {
            $php = PhpBinary::resolve(['XVID_IMPORT_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']) ?: $php;
        }

        if (! PhpBinary::usable($php)) {
            return false;
        }

        $artisan = base_path('artisan');
        $log = storage_path("logs/xvid-import-{$run->id}.log");
        $command = [$php, $artisan, 'xvid:import', '--run-id=' . $run->id];

        $run->forceFill([
            'status' => 'starting',
            'message' => 'Starting background import process from cron.',
            'started_at' => now(),
            'updated_at' => now(),
        ])->save();

        return ShellRunner::runBackground($command, $log, base_path());
    }

    private function runInlineImport(XvidImportRun $run): bool
    {
        try {
            @ignore_user_abort(true);
            @set_time_limit(0);
            Artisan::call('xvid:import', ['--run-id' => $run->id]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Inline XVIDAPI scheduled import failed: ' . $e->getMessage());
            return false;
        }
    }

    private function markStaleActiveRuns(): void
    {
        $staleBefore = now()->subMinutes(self::STALE_ACTIVE_RUN_MINUTES);
        XvidImportRun::query()
            ->whereIn('status', XvidImportSchedule::ACTIVE_RUN_STATUSES)
            ->where('updated_at', '<', $staleBefore)
            ->update([
                'status' => 'failed',
                'message' => 'Marked failed automatically because of inactivity.',
                'finished_at' => now(),
            ]);
    }
}
