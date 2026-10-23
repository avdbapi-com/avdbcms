<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Support\AvdbDurationBackfill;
use App\Support\RemoteVideoDuration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SyncAvdbDurations extends Command
{
    protected $signature = 'avdb:sync-durations
        {--limit=1000 : Maximum videos to scan}
        {--chunk=100 : AVDB IDs per API request}
        {--until-empty : Keep processing batches until no due missing-duration videos remain}
        {--sleep=1 : Seconds to sleep between batches when --until-empty is used}
        {--retry-minutes=60 : Wait this many minutes before retrying a video that could not resolve}
        {--max-runtime=0 : Stop after this many seconds when --until-empty is used; 0 means no time limit}
        {--watch-retries : Keep running when unresolved videos are waiting for their retry window}
        {--remote-concurrency=25 : Concurrent Upload18/player lookups per batch}
        {--remote-timeout=8 : Timeout seconds for each Upload18/player request}
        {--show-items : Print each updated video}
        {--dry-run : Show updates without writing}';

    protected $description = 'Sync missing duration values for AVDB imported videos';

    private const API = 'https://avdbapi.com/api.php/provide/vod';

    private const REMOTE_FAILURE_THRESHOLD = 3;

    private const REMOTE_COOLDOWN_SECONDS = 60;

    private const SLOW_REMOTE_SECONDS = 12.0;

    private const DURATION_SYNC_INDEX = 'videos_avdb_missing_duration_metric_idx';

    /** @var resource|null */
    private $lockHandle = null;

    private string $durationStopToken = '';

    private int $remoteFailureStreak = 0;

    private int $remotePausedUntil = 0;

    private bool $lastRemoteLookupSkipped = false;

    public function handle(): int
    {
        if (! $this->acquireLock()) {
            $this->info('AVDB duration sync is already running.');

            return self::SUCCESS;
        }

        $this->durationStopToken = AvdbDurationBackfill::stopToken();

        try {
            return $this->syncDurations();
        } finally {
            $this->releaseLock();
        }
    }

    private function syncDurations(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, min(200, (int) $this->option('chunk')));
        $untilEmpty = (bool) $this->option('until-empty');
        $sleep = max(0, min(60, (int) $this->option('sleep')));
        $retryMinutes = max(0, (int) $this->option('retry-minutes'));
        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        $watchRetries = (bool) $this->option('watch-retries');
        $remoteConcurrency = max(1, min(100, (int) $this->option('remote-concurrency')));
        $remoteTimeout = max(2, min(30, (int) $this->option('remote-timeout')));
        $startedAt = time();
        $deadline = $maxRuntime > 0 ? $startedAt + $maxRuntime : null;

        $totalScanned = 0;
        $totalUpdated = 0;
        $totalMissing = 0;

        do {
            if ($this->durationStopRequested()) {
                $this->info("AVDB duration sync stopped because a clean/reimport requested a fresh worker. Scanned {$totalScanned}; updated {$totalUpdated}; {$totalMissing} could not resolve yet.");

                return self::SUCCESS;
            }

            $batchLimit = $untilEmpty ? min($limit, $chunkSize) : $limit;
            $result = $this->syncDurationBatch($batchLimit, $chunkSize, $retryMinutes, $remoteConcurrency, $remoteTimeout, $deadline);
            $totalScanned += $result['scanned'];
            $totalUpdated += $result['updated'];
            $totalMissing += $result['missing'];

            if ($this->durationStopRequested()) {
                $this->info("AVDB duration sync stopped because a clean/reimport requested a fresh worker. Scanned {$totalScanned}; updated {$totalUpdated}; {$totalMissing} could not resolve yet.");

                return self::SUCCESS;
            }

            if (($result['remote_skipped'] ?? 0) > 0) {
                $this->line("Upload18 duration lookup is paused; waiting to retry {$result['remote_skipped']} remote-only video(s).");
                if ($this->timeLimitReached($deadline)) {
                    $this->info("AVDB duration sync paused after {$maxRuntime}s. Scanned {$totalScanned}; updated {$totalUpdated}; {$totalMissing} could not resolve yet.");

                    return self::SUCCESS;
                }

                $this->sleepWhileWatching(max(1, min(60, $this->remotePauseRemainingSeconds(), $this->secondsUntilDeadline($deadline))));
            }

            if (! $untilEmpty) {
                $this->printBatchResult($result['updated'], $result['missing']);

                return self::SUCCESS;
            }

            if ($result['scanned'] === 0) {
                $waitingForRetry = $watchRetries ? $this->missingDurationCount() : 0;
                if ($waitingForRetry > 0) {
                    $this->line("AVDB duration sync is waiting to retry {$waitingForRetry} unresolved video(s).");

                    if ($this->timeLimitReached($deadline)) {
                        $this->info("AVDB duration sync paused after {$maxRuntime}s. Scanned {$totalScanned}; updated {$totalUpdated}; {$totalMissing} could not resolve yet.");

                        return self::SUCCESS;
                    }

                    $this->sleepWhileWatching(max(1, min(60, $retryMinutes > 0 ? $retryMinutes * 60 : 60, $this->secondsUntilDeadline($deadline))));

                    continue;
                }

                $this->info("AVDB duration sync caught up. Scanned {$totalScanned}; updated {$totalUpdated}; {$totalMissing} could not resolve yet.");

                return self::SUCCESS;
            }

            $this->line("Duration batch scanned {$result['scanned']}; updated {$result['updated']}; {$result['missing']} could not resolve yet.");

            if ($this->timeLimitReached($deadline)) {
                $this->info("AVDB duration sync paused after {$maxRuntime}s. Scanned {$totalScanned}; updated {$totalUpdated}; {$totalMissing} could not resolve yet.");

                return self::SUCCESS;
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        } while (true);
    }

    private function missingDurationCount(): int
    {
        return Video::query()
            ->withoutGlobalScopes()
            ->when(
                DB::connection()->getDriverName() === 'mysql' && $this->durationSyncIndexExists(),
                fn ($query) => $query->forceIndex(self::DURATION_SYNC_INDEX)
            )
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->count();
    }

    private function sleepWhileWatching(int $seconds): void
    {
        $remaining = $seconds;
        while ($remaining > 0 && ! $this->durationStopRequested()) {
            $nap = min(5, $remaining);
            sleep($nap);
            $remaining -= $nap;
        }
    }

    /**
     * @return array{scanned: int, updated: int, missing: int, remote_skipped: int}
     */
    private function syncDurationBatch(int $limit, int $chunkSize, int $retryMinutes, int $remoteConcurrency, int $remoteTimeout, ?int $deadline = null): array
    {
        $hasAttemptedAt = Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at');
        $hasFailedCount = Schema::hasColumn('videos', 'avdb_duration_sync_failed_count');
        $priorityIds = AvdbDurationBackfill::pullPriorityIds($limit);
        $columns = ['id', 'title', 'avdb_id', 'duration'];
        if ($hasAttemptedAt) {
            $columns[] = 'avdb_duration_sync_attempted_at';
        }

        if ($hasFailedCount) {
            $columns[] = 'avdb_duration_sync_failed_count';
        }

        $missingQuery = function () {
            return Video::query()
                ->withoutGlobalScopes()
                ->without('thumbnail')
                ->whereNotNull('avdb_id')
                ->missingDuration();
        };

        $videos = collect();
        if ($priorityIds !== []) {
            $videos = $missingQuery()
                ->whereIn('id', $priorityIds)
                ->orderByDesc('id')
                ->limit($limit)
                ->get($columns);
        }

        $remainingLimit = max(0, $limit - $videos->count());
        if ($remainingLimit > 0) {
            $normalVideos = $missingQuery()
                ->when($priorityIds !== [], function ($query) use ($priorityIds): void {
                    $query->whereNotIn('id', $priorityIds);
                })
                ->when($hasAttemptedAt, function ($query) use ($retryMinutes): void {
                    $retryBefore = now()->subMinutes($retryMinutes);
                    $query->where(function ($due) use ($retryBefore): void {
                        $due->whereNull('avdb_duration_sync_attempted_at')
                            ->orWhere('avdb_duration_sync_attempted_at', '<=', $retryBefore);
                    });
                })
                ->when(
                    DB::connection()->getDriverName() === 'mysql' && $this->durationSyncIndexExists(),
                    fn ($query) => $query->forceIndex(self::DURATION_SYNC_INDEX)
                )
                ->when($hasAttemptedAt, fn ($query) => $query->orderBy('avdb_duration_sync_attempted_at'))
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit($remainingLimit)
                ->get($columns);

            $videos = $videos
                ->concat($normalVideos)
                ->unique('id')
                ->values();
        }

        if ($videos->isEmpty()) {
            return ['scanned' => 0, 'updated' => 0, 'missing' => 0, 'remote_skipped' => 0];
        }

        $updated = 0;
        $missing = 0;
        $remoteSkipped = 0;
        $scanned = 0;
        $dryRun = (bool) $this->option('dry-run');
        $showItems = (bool) $this->option('show-items');

        foreach ($videos->chunk($chunkSize) as $chunk) {
            if ($this->durationStopRequested() || $this->timeLimitReached($deadline)) {
                break;
            }

            $ids = $chunk->pluck('avdb_id')->filter()->map(fn ($id) => (int) $id)->values();
            if ($ids->isEmpty()) {
                continue;
            }

            $scanned += $chunk->count();
            $apiTimeout = max(5, min(15, $remoteTimeout + 5));

            try {
                $response = Http::timeout($apiTimeout)
                    ->withOptions(['verify' => false])
                    ->get(self::API, [
                        'ac' => 'detail',
                        'ids' => $ids->implode(','),
                    ]);
            } catch (\Throwable $e) {
                $this->warn('AVDB request failed: '.$e->getMessage());

                continue;
            }

            if (! $response->successful()) {
                $this->warn('AVDB request returned HTTP '.$response->status());

                continue;
            }

            if ($this->timeLimitReached($deadline)) {
                break;
            }

            $itemsById = collect($response->json('list') ?? [])->keyBy(fn ($item) => (int) ($item['id'] ?? 0));
            $durationItems = [];
            foreach ($chunk as $video) {
                $item = $itemsById->get((int) $video->avdb_id);
                if (is_array($item)) {
                    $durationItems[(int) $video->avdb_id] = $item;
                }
            }
            $durationsByAvdbId = $this->durationsFromItems($durationItems, $remoteConcurrency, $remoteTimeout);
            $remoteLookupSkipped = $this->lastRemoteLookupSkipped;

            if ($this->timeLimitReached($deadline)) {
                break;
            }

            foreach ($chunk as $video) {
                if ($this->durationStopRequested() || $this->timeLimitReached($deadline)) {
                    break 2;
                }

                if (! $this->videoStillNeedsDuration((int) $video->id)) {
                    continue;
                }

                $seconds = $durationsByAvdbId[(int) $video->avdb_id] ?? null;

                if (! $seconds) {
                    $item = $durationItems[(int) $video->avdb_id] ?? null;
                    if ($remoteLookupSkipped && is_array($item) && $this->itemNeedsRemoteDuration($item)) {
                        $remoteSkipped++;

                        continue;
                    }

                    $missing++;
                    $this->markDurationAttempt($video->id, false, $dryRun);

                    continue;
                }

                if (! $dryRun) {
                    $saved = Video::query()
                        ->withoutGlobalScopes()
                        ->whereKey($video->id)
                        ->whereNotNull('avdb_id')
                        ->missingDuration()
                        ->update([
                            'duration' => $seconds,
                            'avdb_duration_sync_attempted_at' => now(),
                            'avdb_duration_sync_failed_count' => 0,
                            'updated_at' => now(),
                        ]);

                    if ($saved < 1) {
                        continue;
                    }
                }

                $updated++;
                if ($showItems) {
                    $this->line("{$video->id}: {$video->title} => {$this->formatSeconds($seconds)}");
                }
            }
        }

        return ['scanned' => $scanned, 'updated' => $updated, 'missing' => $missing, 'remote_skipped' => $remoteSkipped];
    }

    private function timeLimitReached(?int $deadline): bool
    {
        return $deadline !== null && time() >= $deadline;
    }

    private function secondsUntilDeadline(?int $deadline): int
    {
        return $deadline === null ? 60 : max(1, $deadline - time());
    }

    private function durationStopRequested(): bool
    {
        $currentToken = AvdbDurationBackfill::stopToken();

        return $currentToken !== '' && $currentToken !== $this->durationStopToken;
    }

    private function videoStillNeedsDuration(int $videoId): bool
    {
        return Video::query()
            ->withoutGlobalScopes()
            ->whereKey($videoId)
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->exists();
    }

    private function durationSyncIndexExists(): bool
    {
        try {
            return Schema::hasIndex('videos', self::DURATION_SYNC_INDEX);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function printBatchResult(int $updated, int $missing): void
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? 'Would update ' : 'Updated ')."{$updated} video(s). {$missing} could not resolve a duration.");
    }

    private function markDurationAttempt(int $videoId, bool $resolved, bool $dryRun): void
    {
        if ($dryRun || ! Schema::hasColumn('videos', 'avdb_duration_sync_attempted_at')) {
            return;
        }

        $updates = ['avdb_duration_sync_attempted_at' => now()];

        if (Schema::hasColumn('videos', 'avdb_duration_sync_failed_count')) {
            $updates['avdb_duration_sync_failed_count'] = $resolved
                ? 0
                : DB::raw('COALESCE(avdb_duration_sync_failed_count, 0) + 1');
        }

        Video::query()
            ->withoutGlobalScopes()
            ->whereKey($videoId)
            ->update($updates);
    }

    private function acquireLock(): bool
    {
        $environment = preg_replace('/[^A-Za-z0-9_.-]/', '-', app()->environment()) ?: 'default';
        $path = storage_path("framework/avdb-duration-sync-{$environment}.lock");
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'c');
        if (! is_resource($handle)) {
            return true;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return false;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, 'pid='.(getmypid() ?: 'unknown').' started_at='.now()->toDateTimeString().PHP_EOL);
        $this->lockHandle = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
        }

        $this->lockHandle = null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int|null>
     */
    private function durationsFromItems(array $items, int $remoteConcurrency, int $remoteTimeout): array
    {
        $this->lastRemoteLookupSkipped = false;

        if ($items === []) {
            return [];
        }

        $remoteCandidates = 0;
        foreach ($items as $item) {
            if (
                RemoteVideoDuration::fromDurationFields($item) === null
                && RemoteVideoDuration::playableLinkFromAvdbItem($item) !== null
            ) {
                $remoteCandidates++;
            }
        }

        if (! $this->remoteLookupAllowed()) {
            $this->lastRemoteLookupSkipped = $remoteCandidates > 0;

            return RemoteVideoDuration::fromAvdbItems($items, false, $remoteConcurrency, $remoteTimeout);
        }

        $startedAt = microtime(true);
        $durations = RemoteVideoDuration::fromAvdbItems($items, true, $remoteConcurrency, $remoteTimeout);
        $elapsed = microtime(true) - $startedAt;

        if ($remoteCandidates > 0) {
            $resolvedRemote = 0;
            foreach ($items as $avdbId => $item) {
                if (
                    RemoteVideoDuration::fromDurationFields($item) === null
                    && array_key_exists((int) $avdbId, $durations)
                    && $durations[(int) $avdbId] !== null
                ) {
                    $resolvedRemote++;
                }
            }

            $this->recordRemoteLookupBatchResult($resolvedRemote, $remoteCandidates - $resolvedRemote, $elapsed);
        }

        return $durations;
    }

    private function itemNeedsRemoteDuration(array $item): bool
    {
        return RemoteVideoDuration::fromDurationFields($item) === null
            && RemoteVideoDuration::playableLinkFromAvdbItem($item) !== null;
    }

    private function remoteLookupAllowed(): bool
    {
        return $this->remotePausedUntil <= time();
    }

    private function remotePauseRemainingSeconds(): int
    {
        return max(1, $this->remotePausedUntil - time());
    }

    private function recordRemoteLookupBatchResult(int $resolved, int $failed, float $elapsed): void
    {
        if ($resolved > 0) {
            $this->remoteFailureStreak = 0;
        }

        if ($failed <= 0) {
            return;
        }

        $this->remoteFailureStreak += $failed;

        if ($elapsed >= self::SLOW_REMOTE_SECONDS || $this->remoteFailureStreak >= self::REMOTE_FAILURE_THRESHOLD) {
            $this->remotePausedUntil = time() + self::REMOTE_COOLDOWN_SECONDS;
            $this->warn('Upload18 duration lookup is slow/unavailable; pausing remote duration lookups for '.self::REMOTE_COOLDOWN_SECONDS.' seconds.');
            $this->remoteFailureStreak = 0;
        }
    }

    private function formatSeconds(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
