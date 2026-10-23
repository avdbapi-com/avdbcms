<?php

namespace App\Support;

use App\Jobs\SyncAvdbDurationBatch;
use App\Models\Video;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class AvdbDurationBackfill
{
    private const PRIORITY_CACHE_KEY = 'avdb:duration-priority-video-ids';

    private const STOP_TOKEN_CACHE_KEY = 'avdb:duration-stop-token';

    private const PRIORITY_RETRY_MINUTES = 5;

    /**
     * @return array{available: bool, videos: int, jobs: int, message: string}
     */
    public static function start(int $limit = 1000, int $chunkSize = 100): array
    {
        $mode = self::mode();

        if ($mode === 'off') {
            return [
                'available' => false,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'Duration backfill is disabled.',
            ];
        }

        if ($mode === 'queue') {
            return self::queueMissing($limit, $chunkSize);
        }

        $process = self::startBackgroundProcess($limit, $chunkSize);
        if ($process['available'] || $mode === 'process') {
            return $process;
        }

        return [
            'available' => true,
            'videos' => 0,
            'jobs' => 0,
            'message' => $process['message'].' Laravel scheduler will retry duration sync on the next avdb:queue-duration-sync tick.',
        ];
    }

    /**
     * @return array{available: bool, videos: int, jobs: int, message: string}
     */
    public static function queueMissing(int $limit = 1000, int $chunkSize = 100): array
    {
        if (! self::queueIsAsync()) {
            $driver = (string) config('queue.default', 'sync');

            return [
                'available' => false,
                'videos' => 0,
                'jobs' => 0,
                'message' => "Duration backfill needs an async queue driver; current QUEUE_CONNECTION is {$driver}.",
            ];
        }

        $limit = max(1, min(10000, $limit));
        $chunkSize = max(1, min(50, $chunkSize));

        $videoIds = Video::query()
            ->withoutGlobalScopes()
            ->without('thumbnail')
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id);

        if ($videoIds->isEmpty()) {
            return [
                'available' => true,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'No AVDB videos need duration sync.',
            ];
        }

        $jobs = 0;
        foreach ($videoIds->chunk($chunkSize) as $chunk) {
            SyncAvdbDurationBatch::dispatch($chunk->all());
            $jobs++;
        }

        return [
            'available' => true,
            'videos' => $videoIds->count(),
            'jobs' => $jobs,
            'message' => "Queued {$jobs} AVDB duration job(s) for {$videoIds->count()} video(s).",
        ];
    }

    public static function queueIsAsync(): bool
    {
        $driver = (string) config('queue.default', 'sync');

        return ! in_array($driver, ['', 'sync', 'null'], true)
            && is_array(config("queue.connections.{$driver}"));
    }

    /**
     * @param  iterable<int, int|string>  $videoIds
     * @return array{available: bool, videos: int, jobs: int, message: string}
     */
    public static function prioritizeVisible(iterable $videoIds): array
    {
        if (app()->environment('testing')) {
            return [
                'available' => false,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'Duration backfill is disabled in tests.',
            ];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, is_array($videoIds) ? $videoIds : iterator_to_array($videoIds)),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [
                'available' => true,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'No visible AVDB videos need duration priority.',
            ];
        }

        $hasAttemptedAt = Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at');
        $retryBefore = now()->subMinutes(self::PRIORITY_RETRY_MINUTES);

        $missingIds = Video::query()
            ->withoutGlobalScopes()
            ->without('thumbnail')
            ->whereIn('id', $ids)
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->when($hasAttemptedAt, function ($query) use ($retryBefore): void {
                $query->where(function ($due) use ($retryBefore): void {
                    $due->whereNull('avdb_duration_sync_attempted_at')
                        ->orWhere('avdb_duration_sync_attempted_at', '<=', $retryBefore);
                });
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($missingIds === []) {
            return [
                'available' => true,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'Visible AVDB durations are already synced or recently attempted.',
            ];
        }

        self::rememberPriorityIds($missingIds);

        return self::start(500, 50);
    }

    /**
     * @return array<int, int>
     */
    public static function pullPriorityIds(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $ids = Cache::get(self::PRIORITY_CACHE_KEY, []);
        $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
        if ($ids === []) {
            return [];
        }

        $pulled = array_slice($ids, 0, $limit);
        $remaining = array_slice($ids, $limit);

        if ($remaining === []) {
            Cache::forget(self::PRIORITY_CACHE_KEY);
        } else {
            Cache::put(self::PRIORITY_CACHE_KEY, $remaining, now()->addHours(6));
        }

        return $pulled;
    }

    public static function clearPriorityQueue(): void
    {
        Cache::forget(self::PRIORITY_CACHE_KEY);
    }

    public static function requestStopWorkers(): string
    {
        $token = (string) Str::uuid();
        Cache::forever(self::STOP_TOKEN_CACHE_KEY, $token);

        return $token;
    }

    public static function stopToken(): string
    {
        return (string) Cache::get(self::STOP_TOKEN_CACHE_KEY, '');
    }

    /**
     * @param  array<int, int>  $ids
     */
    private static function rememberPriorityIds(array $ids): void
    {
        $existing = Cache::get(self::PRIORITY_CACHE_KEY, []);
        $existing = is_array($existing) ? array_map('intval', $existing) : [];

        $merged = array_values(array_unique(array_merge($ids, $existing)));

        Cache::put(self::PRIORITY_CACHE_KEY, array_slice($merged, 0, 1000), now()->addHours(6));
    }

    /**
     * @return array{available: bool, videos: int, jobs: int, message: string}
     */
    public static function startBackgroundProcess(int $limit = 1000, int $chunkSize = 100): array
    {
        $php = PhpBinary::resolve(['AVDB_DURATION_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
        if (! $php) {
            return [
                'available' => false,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'Duration backfill could not start because no PHP CLI binary was found.',
            ];
        }

        if (! ShellRunner::available()) {
            return [
                'available' => false,
                'videos' => 0,
                'jobs' => 0,
                'message' => 'Duration backfill could not start because PHP subprocess support is disabled.',
            ];
        }

        $limit = max(1, min(10000, $limit));
        $chunkSize = max(1, min(200, $chunkSize));

        try {
            $started = ShellRunner::runBackground(
                [
                    $php,
                    base_path('artisan'),
                    'avdb:sync-durations',
                    '--limit='.$limit,
                    '--chunk='.$chunkSize,
                    '--until-empty',
                    '--sleep=1',
                    '--retry-minutes=15',
                    '--watch-retries',
                    '--remote-concurrency='.self::remoteConcurrency(),
                    '--remote-timeout='.self::remoteTimeout(),
                ],
                storage_path('logs/avdb-duration-sync.log'),
                base_path()
            );
        } catch (Throwable $e) {
            $started = false;
        }

        return [
            'available' => $started,
            'videos' => 0,
            'jobs' => 0,
            'message' => $started
                ? 'Started AVDB duration sync in the background until no due videos remain.'
                : 'Duration backfill could not start in the background.',
        ];
    }

    private static function mode(): string
    {
        $explicitMode = $_ENV['AVDB_DURATION_BACKFILL_MODE'] ?? $_SERVER['AVDB_DURATION_BACKFILL_MODE'] ?? null;
        if (app()->environment('testing') && ($explicitMode === null || $explicitMode === '')) {
            return 'off';
        }

        $mode = strtolower(trim((string) RuntimeEnv::get('AVDB_DURATION_BACKFILL_MODE', 'auto')));
        if (! in_array($mode, ['auto', 'process', 'queue', 'off'], true)) {
            $mode = 'auto';
        }

        return $mode;
    }

    public static function remoteConcurrency(): int
    {
        return max(1, min(100, (int) RuntimeEnv::get('AVDB_DURATION_REMOTE_CONCURRENCY', 4)));
    }

    public static function remoteTimeout(): int
    {
        return max(2, min(30, (int) RuntimeEnv::get('AVDB_DURATION_REMOTE_TIMEOUT', 8)));
    }
}
