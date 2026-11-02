<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Events\Video\VideoBanned;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The creators of the videos Content > All videos banned with the bulk bar hear of each ban, as with "Ban video" on one
 * row (the VideoBanned event: the bell and an e-mail). From the queue: a page of up to 100 videos would otherwise send
 * as many e-mails before the admin gets an answer. A video unbanned before this runs is left out; one try only, so a
 * failure never sends a creator the same e-mail twice (a failing creator is reported and the others still hear).
 */
class NotifyBannedVideos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param list<int> $videoIds the videos this bulk action banned (not those that were banned already) */
    public function __construct(public array $videoIds)
    {
    }

    public function handle(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->videoIds)));

        if ($ids === []) {
            return;
        }

        Video::query()
            ->whereIn('id', $ids)
            ->where('status', VideoStatus::BANNED->value)
            ->with('user')
            ->chunkById(50, function ($videos): void {
                foreach ($videos as $video) {
                    if ($video->user === null) {
                        continue;
                    }

                    try {
                        VideoBanned::dispatch($video);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
