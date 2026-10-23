<?php

namespace App\Jobs;

use App\Models\Video;
use App\Support\RemoteVideoDuration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAvdbDurationBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const API = 'https://avdbapi.com/api.php/provide/vod';

    public int $timeout = 600;

    public int $tries = 2;

    public int $backoff = 60;

    /**
     * @var array<int, int>
     */
    public array $videoIds;

    /**
     * @param  array<int, int|string>  $videoIds
     */
    public function __construct(array $videoIds)
    {
        $this->videoIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $videoIds),
            static fn (int $id): bool => $id > 0
        )));

        $this->onQueue((string) config('queue.names.default', 'default'));
    }

    public function uniqueId(): string
    {
        $ids = $this->videoIds;
        sort($ids);

        return 'avdb-duration-batch:'.sha1(implode(',', $ids));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter(900)
                ->dontRelease(),
        ];
    }

    public function handle(): void
    {
        if ($this->videoIds === []) {
            return;
        }

        $videos = Video::query()
            ->withoutGlobalScopes()
            ->without('thumbnail')
            ->whereIn('id', $this->videoIds)
            ->whereNotNull('avdb_id')
            ->missingDuration()
            ->get(['id', 'title', 'avdb_id', 'duration']);

        if ($videos->isEmpty()) {
            return;
        }

        $avdbIds = $videos
            ->pluck('avdb_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        $itemsById = $this->fetchAvdbItems($avdbIds);
        if ($itemsById === []) {
            return;
        }

        foreach ($videos as $video) {
            $item = $itemsById[(int) $video->avdb_id] ?? null;
            $seconds = is_array($item) ? RemoteVideoDuration::fromAvdbItem($item, true) : null;

            if (! $seconds) {
                continue;
            }

            $video->forceFill(['duration' => $seconds])->save();
        }
    }

    /**
     * @param  array<int, int>  $avdbIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchAvdbItems(array $avdbIds): array
    {
        $avdbIds = array_values(array_unique(array_filter($avdbIds)));
        if ($avdbIds === []) {
            return [];
        }

        try {
            $response = Http::timeout(30)
                ->withOptions(['verify' => false])
                ->get(self::API, [
                    'ac' => 'detail',
                    'ids' => implode(',', $avdbIds),
                ]);
        } catch (Throwable $e) {
            Log::warning('AVDB duration batch request failed: '.$e->getMessage());

            return [];
        }

        if (! $response->successful()) {
            Log::warning('AVDB duration batch request returned HTTP '.$response->status());

            return [];
        }

        $itemsById = [];
        foreach ($response->json('list') ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $itemsById[$id] = $item;
            }
        }

        return $itemsById;
    }
}
