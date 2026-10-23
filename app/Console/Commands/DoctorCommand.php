<?php

namespace App\Console\Commands;

use App\Support\Environment;
use App\Support\EnvironmentRepairer;
use App\Support\SendFile;
use App\Support\ShellRunner;
use Illuminate\Console\Command;

/**
 * `php artisan app:doctor`
 *
 * Prints the same diagnostics the preflight admin page shows, so operators
 * can verify a deployment without logging in to the dashboard.
 */
class DoctorCommand extends Command
{
    protected $signature = 'app:doctor
        {--json : Output machine-readable JSON}
        {--fix : Repair app-owned runtime paths, storage links, and missing APP_KEY}';

    protected $description = 'Run environment diagnostics and print a hosting compatibility report.';

    public function handle(): int
    {
        $repairs = [];

        if ($this->option('fix')) {
            $repairs = EnvironmentRepairer::repairInstallation();
            Environment::reset();
        }

        $snapshot = Environment::snapshot();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot + [
                'streaming' => SendFile::effectiveMode(),
                'shell_available' => ShellRunner::available(),
                'repairs' => $repairs,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('<options=bold>AVDBCMS environment doctor</>');
        $this->line(str_repeat('-', 60));

        if ($this->option('fix')) {
            $this->line('<options=bold>Auto-fix</>');
            if ($repairs === []) {
                $this->line('No app-owned repairs were needed.');
            }

            foreach ($repairs as $repair) {
                $badge = ($repair['severity'] ?? '') === 'warn' ? '<fg=yellow>WARN</>' : '<fg=green>OK</>  ';
                $this->line(sprintf('%s %s - %s', $badge, $repair['scope'], $repair['message']));
            }
            $this->line('');
        }

        $this->line(sprintf('OS family:       %s', $snapshot['os']['family']));
        $this->line(sprintf('PHP:             %s (%s)', $snapshot['php']['version'], $snapshot['php']['sapi']));
        $this->line(sprintf('Web server:      %s', $snapshot['web']['label']));
        $this->line(sprintf('Hosting panel:   %s', $snapshot['web']['panel_label']));
        $this->line(sprintf('HTTPS request:   %s', $snapshot['web']['is_https'] ? 'yes' : 'no'));
        $this->line('');

        $this->line('<options=bold>Runtime</>');
        $this->line(str_repeat('-', 60));
        $this->line(sprintf('proc_open usable:    %s', $snapshot['php']['can_run_processes'] ? 'yes' : 'no'));
        $this->line(sprintf('open_basedir:        %s', $snapshot['php']['open_basedir'] === []
            ? 'unrestricted'
            : implode(', ', $snapshot['php']['open_basedir'])));
        if ($snapshot['php']['disabled_functions'] !== []) {
            $this->line(sprintf('disable_functions:   %s', implode(', ', $snapshot['php']['disabled_functions'])));
        }
        $this->line(sprintf('ffmpeg:              %s', $snapshot['runtime']['ffmpeg'] ?: '- not found'));
        $this->line(sprintf('ffprobe:             %s', $snapshot['runtime']['ffprobe'] ?: '- not found'));
        $this->line(sprintf('mysqldump:           %s', $snapshot['runtime']['mysqldump'] ?: '- not found'));
        $this->line(sprintf('Redis reachable:     %s', $snapshot['runtime']['redis_reachable'] ? 'yes' : 'no'));
        $this->line(sprintf('Stream accelerator:  %s', SendFile::effectiveMode()));
        $this->line('');

        $this->line('<options=bold>PHP limits</>');
        $this->line(str_repeat('-', 60));
        foreach ($snapshot['php']['limits'] as $limit) {
            $badge = match ($limit['severity']) {
                'ok' => '<fg=green>OK</>  ',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>LOW</> ',
            };
            $this->line(sprintf('%s %s - current %s, recommended %s', $badge, $limit['key'], $limit['current'], $limit['recommended']));
        }
        $this->line('');

        $this->line('<options=bold>Storage writability</>');
        $this->line(str_repeat('-', 60));
        foreach ($snapshot['runtime']['writable'] as $path => $ok) {
            $this->line(sprintf('%s %s', $ok ? '<fg=green>OK</>  ' : '<fg=red>FAIL</>', $path));
        }
        $this->line('');

        $danger = collect($snapshot['runtime']['writable'])->filter(fn ($v) => $v === false)->keys();
        if ($danger->isNotEmpty()) {
            $this->warn('Some storage paths are not writable. Fix ownership with:');
            $this->line('  mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache');
            $this->line('  chown www-data:www-data storage storage/app storage/app/public storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache');
            $this->line('  chmod 775 storage storage/app storage/app/public storage/framework storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache');

            return self::FAILURE;
        }

        $this->info('Environment looks healthy.');

        return self::SUCCESS;
    }
}
