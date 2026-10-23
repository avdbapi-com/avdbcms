<?php

namespace App\Http\Controllers\Admin;

use App\Support\Environment;
use App\Support\EnvironmentRepairer;
use App\Support\RuntimeEnv;
use App\Support\SendFile;
use App\Support\ShellRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runtime diagnostics page for operators: environment snapshot, detected
 * web server / panel / OS, PHP ini vs recommended values, queue / cache
 * / redis reachability, streaming accelerator status, and the last set
 * of auto-adjustments applied by the {@see \App\Providers\EnvironmentServiceProvider}.
 */
class PreflightController
{
    public function index(): View
    {
        return view('admin.preflight.index', [
            'report' => $this->buildReport(),
        ]);
    }

    public function json(): JsonResponse
    {
        return response()->json($this->buildReport());
    }

    public function refresh(): \Illuminate\Http\RedirectResponse
    {
        $this->clearCachedProbes();
        Environment::reset();

        return redirect()->route('admin.preflight.index')
            ->withSuccess(__('Environment detection refreshed.'));
    }

    public function fix(): \Illuminate\Http\RedirectResponse
    {
        $repairs = EnvironmentRepairer::repairInstallation();
        $this->clearCachedProbes();
        Environment::reset();

        $fixed = count(array_filter($repairs, static fn ($repair) => ($repair['severity'] ?? null) === 'success'));
        $warnings = count(array_filter($repairs, static fn ($repair) => ($repair['severity'] ?? null) === 'warn'));

        return redirect()->route('admin.preflight.index')
            ->with('preflight_repairs', $repairs)
            ->withSuccess(__('Auto-fix completed: :fixed fixed, :warnings warnings.', [
                'fixed' => $fixed,
                'warnings' => $warnings,
            ]));
    }

    private function clearCachedProbes(): void
    {
        try {
            Cache::deleteMultiple([
                'env.probe.redis.reachable',
                'env.probe.bin.ffmpeg',
                'env.probe.bin.ffprobe',
                'env.probe.bin.mysqldump',
            ]);
        } catch (Throwable) {
            // continue; Environment::reset() will still force in-process re-probes
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(): array
    {
        $snapshot = Environment::snapshot();

        $checks = $this->runChecks($snapshot);

        return [
            'snapshot' => $snapshot,
            'checks' => $checks,
            'adjustments' => (array) config('site.environment_adjustments', []),
            'streaming' => [
                'mode' => SendFile::effectiveMode(),
                'prefix' => SendFile::internalPrefix(),
            ],
            'queue' => [
                'connection' => (string) config('queue.default', 'sync'),
                'avdb_mode' => (string) (RuntimeEnv::get('AVDB_IMPORT_MODE') ?: 'auto'),
            ],
            'counts' => [
                'ok' => count(array_filter($checks, static fn ($c) => $c['severity'] === 'ok')),
                'warn' => count(array_filter($checks, static fn ($c) => $c['severity'] === 'warn')),
                'danger' => count(array_filter($checks, static fn ($c) => $c['severity'] === 'danger')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{category:string,label:string,severity:string,detail:string}>
     */
    private function runChecks(array $snapshot): array
    {
        $checks = [];

        // PHP version
        $checks[] = [
            'category' => 'PHP',
            'label' => __('PHP version'),
            'severity' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'danger',
            'detail' => PHP_VERSION,
        ];

        // Shell exec
        $canRun = ShellRunner::available();
        $checks[] = [
            'category' => 'PHP',
            'label' => __('Subprocess support (proc_open)'),
            'severity' => $canRun ? 'ok' : 'warn',
            'detail' => $canRun
                ? __('Enabled — backups, ffmpeg, and AVDB inline spawn are all usable.')
                : __('Disabled — disable_functions blocks proc_open. AVDB imports will run through the queue; backups + ffmpeg will need an alternative.'),
        ];

        // open_basedir
        $basedir = Environment::openBasedirPaths();
        $checks[] = [
            'category' => 'PHP',
            'label' => 'open_basedir',
            'severity' => $basedir === [] ? 'ok' : 'warn',
            'detail' => $basedir === []
                ? __('Unrestricted.')
                : __('Restricted to: :paths', ['paths' => implode(', ', $basedir)]),
        ];

        // PHP limits
        foreach (Environment::phpLimitsReport() as $limit) {
            $checks[] = [
                'category' => 'PHP limits',
                'label' => $limit['key'],
                'severity' => $limit['severity'],
                'detail' => __('Current :current · Recommended :recommended', [
                    'current' => $limit['current'],
                    'recommended' => $limit['recommended'],
                ]),
            ];
        }

        // Storage writability
        foreach ((array) ($snapshot['runtime']['writable'] ?? []) as $path => $writable) {
            $checks[] = [
                'category' => 'Storage',
                'label' => $path,
                'severity' => $writable ? 'ok' : 'danger',
                'detail' => $writable ? __('Writable.') : __('NOT writable. The web server / FPM user must own this directory.'),
            ];
        }

        // FFmpeg
        $ffmpeg = (string) ($snapshot['runtime']['ffmpeg'] ?? '');
        $checks[] = [
            'category' => 'Binaries',
            'label' => 'ffmpeg',
            'severity' => $ffmpeg !== '' ? 'ok' : 'warn',
            'detail' => $ffmpeg !== ''
                ? $ffmpeg
                : __('Not found. Install ffmpeg via your panel / apt / yum, or set FFMPEG_BINARY.'),
        ];

        $ffprobe = (string) ($snapshot['runtime']['ffprobe'] ?? '');
        $checks[] = [
            'category' => 'Binaries',
            'label' => 'ffprobe',
            'severity' => $ffprobe !== '' ? 'ok' : 'warn',
            'detail' => $ffprobe !== ''
                ? $ffprobe
                : __('Not found. Thumbnail generation will fall back to remote posters.'),
        ];

        $mysqldump = (string) ($snapshot['runtime']['mysqldump'] ?? '');
        $checks[] = [
            'category' => 'Binaries',
            'label' => 'mysqldump / mariadb-dump',
            'severity' => $mysqldump !== '' ? 'ok' : 'warn',
            'detail' => $mysqldump !== ''
                ? $mysqldump
                : __('Not found. Database backups will be unavailable until this is installed or MYSQLDUMP_PATH is set.'),
        ];

        // Redis reachability
        $redisReachable = (bool) ($snapshot['runtime']['redis_reachable'] ?? false);
        $redisUsed = in_array('redis', [
            config('cache.default'),
            config('session.driver'),
            config('queue.default'),
        ], true);
        $checks[] = [
            'category' => 'Runtime',
            'label' => 'Redis',
            'severity' => $redisUsed ? ($redisReachable ? 'ok' : 'warn') : 'ok',
            'detail' => $redisUsed
                ? ($redisReachable ? __('Connected.') : __('Not reachable. Drivers were temporarily rerouted to file/database.'))
                : __('Not in use.'),
        ];

        // Database
        try {
            DB::connection()->getPdo();
            $checks[] = [
                'category' => 'Runtime',
                'label' => 'Database',
                'severity' => 'ok',
                'detail' => sprintf(
                    '%s://%s:%s/%s',
                    (string) config('database.default'),
                    (string) config('database.connections.' . config('database.default') . '.host'),
                    (string) config('database.connections.' . config('database.default') . '.port'),
                    (string) config('database.connections.' . config('database.default') . '.database'),
                ),
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'category' => 'Runtime',
                'label' => 'Database',
                'severity' => 'danger',
                'detail' => __('Connection failed: :err', ['err' => $e->getMessage()]),
            ];
        }

        // Streaming
        $mode = SendFile::effectiveMode();
        $checks[] = [
            'category' => 'Streaming',
            'label' => __('Accelerator'),
            'severity' => $mode === 'none' ? 'warn' : 'ok',
            'detail' => $mode === 'none'
                ? __('Large files stream through PHP. Configure an internal location and set site.stream_accelerator to nginx/apache/litespeed for better throughput.')
                : __('Using :mode', ['mode' => $mode]),
        ];

        // HTTPS
        $checks[] = [
            'category' => 'HTTP',
            'label' => __('HTTPS'),
            'severity' => Environment::isSecureRequest() ? 'ok' : 'warn',
            'detail' => Environment::isSecureRequest()
                ? __('Secure — cookies hardened automatically.')
                : __('The request appears to be over HTTP. Enable HTTPS at the panel / web server.'),
        ];

        return $checks;
    }
}
