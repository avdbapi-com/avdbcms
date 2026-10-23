<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\ImportXvidVideos;
use App\Support\AdminNav;
use App\Support\AppVersion;
use App\Support\PhpBinary;
use App\Support\Updater\ReleaseCheck;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Slow checks that no admin page waits for: the pages render at once and fetch these after they appear
 * (GET /admin/status/{probe}). Each answer is cached per language (60-600 s; a failure for 120 s) and each check stops
 * after about 3 seconds, so a provider or a disk that does not answer never holds a page.
 *
 * Answer: {ok, probe, status: ok|warn|danger|off, label, detail, data, action: {label, url}|null, checked_at, cached}.
 * A check may keep an answer shorter than its PROBES time (result key "keep", seconds; it is not part of the answer).
 */
class StatusProbeController
{
    /** Probe => seconds a good answer is kept. */
    public const PROBES = [
        'provider' => 300,
        'folders' => 600,
        'schedule' => 300,
        'redis' => 60,
        'disk' => 120,
        'database' => 600,
        'health' => 600,
        'php' => 600,
        'updates' => 43200,
    ];

    private const FAILURE_SECONDS = 120;

    private const BUDGET_SECONDS = 3;

    public function show(string $probe): JsonResponse
    {
        abort_unless(array_key_exists($probe, self::PROBES), 404);

        return response()->json(self::result($probe, request()->boolean('refresh')));
    }

    /** The cached answer of a probe, or null when it has not run lately (the page then asks after it appears). */
    public static function peek(string $probe): ?array
    {
        try {
            $cached = Cache::get(self::key($probe));
        } catch (Throwable) {
            return null;
        }

        return is_array($cached) ? $cached + ['cached' => true] : null;
    }

    /** Run a probe (or answer from its cache) and keep the answer. */
    public static function result(string $probe, bool $fresh = false): array
    {
        if (! $fresh && ($cached = self::peek($probe)) !== null) {
            return $cached;
        }

        $started = microtime(true);

        try {
            $result = match ($probe) {
                'provider' => self::provider(),
                'folders' => self::folders(),
                'schedule' => self::schedule(),
                'redis' => self::redis(),
                'disk' => self::disk(),
                'database' => self::database(),
                'health' => self::health(),
                'php' => self::php(),
                'updates' => self::updates(),
                default => throw new \InvalidArgumentException('Unknown status probe: '.$probe),
            };
        } catch (Throwable $e) {
            report($e);
            $result = ['status' => 'danger', 'label' => __('This check could not run.'), 'detail' => Str::limit(class_basename($e).': '.$e->getMessage(), 160)];
        }

        $keep = isset($result['keep']) ? max(1, min((int) $result['keep'], self::PROBES[$probe])) : self::PROBES[$probe];
        unset($result['keep']);

        $result = array_merge(['ok' => true, 'probe' => $probe, 'detail' => null, 'data' => [], 'action' => null], $result, [
            'ok' => ($result['status'] ?? 'danger') !== 'danger',
            'checked_at' => now()->toIso8601String(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        try {
            Cache::put(self::key($probe), $result, $result['ok'] ? $keep : self::FAILURE_SECONDS);
        } catch (Throwable) {
            // No cache store: the next page asks again.
        }

        return $result + ['cached' => false];
    }

    /** Drops the answer in every language (what made it old, e.g. a folder cleared, holds for all of them). */
    public static function forget(string $probe): void
    {
        foreach (array_unique([app()->getLocale(), ...array_keys((array) config('languages', []))]) as $locale) {
            try {
                Cache::forget(self::key($probe, (string) $locale));
            } catch (Throwable) {
            }
        }
    }

    /** Per language: an answer holds its labels and dates already translated (Home shows them as they are). */
    private static function key(string $probe, ?string $locale = null): string
    {
        return 'admin.status.'.$probe.'.'.($locale ?? app()->getLocale());
    }

    /** A link for the answer, or null when this site cannot open that page (e.g. the operational tools are off). */
    private static function action(string $label, string $route): ?array
    {
        $url = AdminNav::url(['route' => $route, 'params' => []]);

        return $url === null ? null : ['label' => $label, 'url' => $url];
    }

    /**
     * Do the video providers answer? (Only asked while imports are set up; both at once, within one time budget.)
     * They are asked the way the imports ask them (no certificate check), and AVDBAPI is asked its import console's
     * question, so the console shows this answer's catalog numbers (data.providers.AVDBAPI.summary) instead of asking
     * the provider again before the page appears.
     */
    private static function provider(): array
    {
        $providers = [
            'AVDBAPI' => (string) config('site.avdb_api_base_url', 'https://avdbapi.com/api.php/provide/vod'),
            'XVIDAPI' => ImportXvidVideos::DEFAULT_API_BASE,
        ];
        $queries = [
            'AVDBAPI' => AvdbImportController::API_SUMMARY_QUERY,
            'XVIDAPI' => ['ac' => 'list', 'at' => 'json', 'pagesize' => 1],
        ];
        $answers = [];
        $started = microtime(true);

        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $name): mixed => $pool->as($name)
                    ->timeout(self::BUDGET_SECONDS)
                    ->connectTimeout(self::BUDGET_SECONDS)
                    ->withHeaders(['Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0'])
                    ->withOptions(['verify' => false])
                    ->get($providers[$name], $queries[$name]),
                array_keys($providers)
            ));
        } catch (Throwable) {
            $responses = [];
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        foreach (array_keys($providers) as $name) {
            // A provider that does not answer in time comes back as an exception, not a response.
            $response = $responses[$name] ?? null;

            try {
                $data = $response instanceof Response ? $response->json() : null;
                $ok = $response instanceof Response && $response->successful() && is_array($data);
            } catch (Throwable) {
                $data = null;
                $ok = false;
            }

            $answers[$name] = ['ok' => $ok, 'ms' => $ms];

            if ($name === 'AVDBAPI') {
                $answers[$name]['summary'] = AvdbImportController::apiSummaryFrom($ok, $data);
            }
        }

        $down = array_keys(array_filter($answers, fn (array $answer): bool => ! $answer['ok']));

        return [
            'status' => $down === [] ? 'ok' : (count($down) === count($answers) ? 'danger' : 'warn'),
            'label' => $down === [] ? __('Video providers answer') : __(':providers did not answer', ['providers' => implode(', ', $down)]),
            'detail' => $down === [] ? null : __('Imports from there fail until it answers again. Nothing on your site is wrong.'),
            'data' => ['providers' => $answers],
            'action' => $down === [] ? null : self::action(__('See imports'), 'admin.avdb-import.index'),
        ];
    }

    /** Sizes of the folders on the Cache & storage page (each walk stops after the time budget). */
    private static function folders(): array
    {
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $folders = [];

        foreach (StorageController::folders() as $label => $path) {
            $folders[$label] = self::folderSize($path, $deadline);
        }

        $partial = in_array(true, array_column($folders, 'partial'), true);

        return [
            'status' => 'ok',
            'label' => __('Folder sizes'),
            'detail' => $partial ? __('Some folders hold too many files to count in a few seconds: their sizes are at least what is shown.') : null,
            'data' => ['folders' => $folders],
        ];
    }

    /** @return array{exists: bool, size: int, files: int, formatted_size: string, partial: bool} */
    private static function folderSize(string $path, float $deadline): array
    {
        if (! is_dir($path)) {
            return ['exists' => false, 'size' => 0, 'files' => 0, 'formatted_size' => '0 B', 'partial' => false];
        }

        $size = 0;
        $files = 0;
        $partial = false;

        try {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($items as $item) {
                if ($item->isFile()) {
                    $size += (int) $item->getSize();
                    $files++;
                }

                if (($files & 255) === 0 && microtime(true) > $deadline) {
                    $partial = true;
                    break;
                }
            }
        } catch (Throwable) {
            // An unreadable folder counts what could be read.
        }

        return ['exists' => true, 'size' => $size, 'files' => $files, 'formatted_size' => StorageController::formatBytes($size), 'partial' => $partial];
    }

    /** Scheduled tasks (schedule:list) and whether the scheduler ran in the last minutes. */
    private static function schedule(): array
    {
        $tasks = CronController::listScheduledTasks();
        $lastRun = null;

        foreach (['logs/cron.log', 'logs/schedule-run.log', 'logs/schedule-work.out.log', 'framework/scheduler-last-run.timestamp'] as $file) {
            $time = is_file(storage_path($file)) ? @filemtime(storage_path($file)) : false;
            $lastRun = $time !== false && $time > (int) $lastRun ? $time : $lastRun;
        }

        $recent = $lastRun !== null && $lastRun >= now()->subMinutes(10)->getTimestamp();

        return [
            'status' => $tasks === [] ? 'off' : ($recent ? 'ok' : 'warn'),
            'label' => $recent ? __('Scheduled tasks are running') : __('Scheduled tasks are not running'),
            'detail' => $recent ? null : __('Daily imports and clean-ups will not start until this is fixed.'),
            'data' => [
                'tasks' => $tasks,
                'count' => count($tasks),
                'last_run' => $lastRun !== null ? date(DATE_ATOM, $lastRun) : null,
            ],
            'action' => $recent ? null : self::action(__('How to fix'), 'admin.cron.index'),
        ];
    }

    /** Redis: used by this site, and does it answer? */
    private static function redis(): array
    {
        $connection = (string) config('horizon.use', 'default');
        $used = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);
        $horizon = class_exists(\Laravel\Horizon\Horizon::class);

        if (! $used && ! $horizon) {
            return ['status' => 'off', 'label' => __('Redis is not used'), 'data' => ['used' => false, 'reachable' => null, 'horizon' => false]];
        }

        $reachable = self::redisAnswers($connection);

        return [
            // Only a site that keeps its cache, queue or sessions in Redis has a problem when it does not answer.
            'status' => $reachable ? 'ok' : ($used ? 'danger' : 'off'),
            'label' => $reachable ? __('Redis answers') : __('Redis does not answer'),
            'detail' => $reachable || ! $used ? null : __('The cache, queue or sessions are set to use Redis.'),
            'data' => ['used' => $used, 'reachable' => $reachable, 'horizon' => $horizon && $reachable],
        ];
    }

    private static function redisAnswers(string $connection): bool
    {
        $config = (array) config('database.redis.'.$connection, []);
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);

        // A closed port makes the Redis client wait several seconds: knock first, with the time budget.
        if (empty($config['url']) && ! str_starts_with($host, '/') && ! str_starts_with($host, 'unix:')) {
            $socket = @fsockopen($host, $port, $errno, $error, self::BUDGET_SECONDS);

            if ($socket === false) {
                return false;
            }

            fclose($socket);
        }

        try {
            Redis::connection($connection)->command('PING');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function disk(): array
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());

        if (! is_numeric($total) || ! is_numeric($free) || $total <= 0) {
            return ['status' => 'off', 'label' => __('Disk space is not known')];
        }

        $percent = (int) round((1 - $free / $total) * 100);

        return [
            'status' => $percent >= 95 ? 'danger' : ($percent >= 90 ? 'warn' : 'ok'),
            'label' => __('Disk :percent% used', ['percent' => $percent]),
            'detail' => $percent >= 90 ? __('Free some space or ask your host for more: uploads, imports and backups stop when the disk is full.') : null,
            'data' => ['percent' => $percent, 'free' => (int) $free, 'total' => (int) $total, 'free_formatted' => StorageController::formatBytes((int) $free)],
            'action' => $percent >= 90 ? self::action(__('Free up space'), 'admin.storage.index') : null,
        ];
    }

    private static function database(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql' && $connection->getDriverName() !== 'mariadb') {
            return ['status' => 'off', 'label' => __('Database size is not known')];
        }

        $row = DB::selectOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = ?',
            [$connection->getDatabaseName()]
        );
        $bytes = (int) ($row->bytes ?? 0);

        return [
            'status' => 'ok',
            'label' => __('Database :size', ['size' => StorageController::formatBytes($bytes)]),
            'data' => ['bytes' => $bytes, 'formatted' => StorageController::formatBytes($bytes)],
        ];
    }

    /** Can this site start PHP processes for background work (the scheduler's runs, imports)? Starts processes. */
    private static function php(): array
    {
        $path = CronController::findPhpBinary();
        $usable = PhpBinary::usable($path);

        return [
            'status' => $usable ? 'ok' : 'warn',
            'label' => $usable ? __('PHP can run background work') : __('PHP cannot run background work'),
            'detail' => $usable ? null : __('Runs that start a PHP process (scheduled tasks, imports) fall back to slower ways; the host may block them.'),
            'data' => ['path' => $path, 'usable' => $usable],
        ];
    }

    /**
     * Is a newer AVDBCMS out? (Home's "AVDBCMS X.Y.Z is available"; nothing installs from here.) The answer of the
     * GitHub check that Tools > Updates shows too, asked with this probe's 3-second budget only when it holds no answer:
     * kept 12 hours, and for one hour when GitHub could not be asked (as ReleaseCheck keeps it). Never "danger": a check
     * that cannot run is no fault of the site, and a two-minute failure cache would spend the server's 60 GitHub
     * requests an hour.
     */
    private static function updates(): array
    {
        $answer = (new ReleaseCheck())->get(self::BUDGET_SECONDS);
        $state = (string) ($answer['state'] ?? 'error');
        $version = $answer['release']['version'] ?? null;
        $data = [
            'state' => $state,
            'current' => AppVersion::CURRENT,
            'version' => is_string($version) ? $version : null,
            'name' => $answer['release']['name'] ?? null,
            'published_at' => $answer['release']['published_at'] ?? null,
            'source' => $answer['source'] ?? null,
        ];

        if ($state === 'available' && is_string($version)) {
            return [
                'status' => 'warn',
                'label' => __('AVDBCMS :version is available', ['version' => $version]),
                'detail' => __('Your site runs AVDBCMS :version.', ['version' => AppVersion::CURRENT]),
                'data' => $data,
                'action' => self::action(__('See the update'), 'admin.updates.index'),
            ];
        }

        return [
            'status' => $state === 'current' ? 'ok' : 'off',
            'label' => ReleaseCheck::message($answer) ?? __('Your site is up to date.'),
            'data' => $data,
            'keep' => $state === 'error' ? 3600 : null,
        ];
    }

    /** The Health check's counts (the same report as its page). */
    private static function health(): array
    {
        $report = (array) app(PreflightController::class)->json()->getData(true);
        $counts = array_merge(['ok' => 0, 'warn' => 0, 'danger' => 0], (array) ($report['counts'] ?? []));
        $problems = (int) $counts['danger'];

        return [
            // Suggestions (warn) are listed on the Health check page; only problems need the administrator.
            'status' => $problems > 0 ? 'danger' : 'ok',
            'label' => $problems > 0
                ? trans_choice('The health check found 1 problem|The health check found :count problems', $problems, ['count' => $problems])
                : __('The health check found no problems'),
            'detail' => $problems > 0 ? __('Each problem comes with what to do about it.') : null,
            'data' => ['counts' => $counts],
            'action' => $problems > 0 ? self::action(__('See details'), 'admin.preflight.index') : null,
        ];
    }
}
