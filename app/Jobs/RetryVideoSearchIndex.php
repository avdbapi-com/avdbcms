<?php

namespace App\Jobs;

use App\Support\VideoSearchIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tells the optional external search index (Meilisearch, Algolia) of videos it missed because it did not answer when
 * they changed (a ban, an unban, a save: App\Support\VideoSearchIndex::guard()). Each video is written or removed as it
 * is now. While the search server still does not answer the job fails and the queue tries it again later (a few times
 * over a few hours); after the last try it waits in Background jobs > Failed, where the admin can retry it.
 */
class RetryVideoSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 120;

    /** @param list<int> $videoIds */
    public function __construct(public array $videoIds)
    {
    }

    /** @return list<int> seconds before each next try */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(): void
    {
        VideoSearchIndex::sync($this->videoIds);
    }
}
