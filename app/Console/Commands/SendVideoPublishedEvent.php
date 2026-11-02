<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Events\Video\VideoPublished;
use App\Models\Video;
use App\Notifications\Support\NotificationTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendVideoPublishedEvent extends Command
{
    /** A minute the scheduler missed (a busy server, a late cron) is caught up within this many minutes. */
    private const CATCH_UP_MINUTES = 60;

    /** When this version started announcing: older videos were announced by the version before (never twice). */
    private const SINCE_KEY = 'notifications.announce.since';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:published';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send notification to subscribers when planned video become public for the first time';

    /**
     * Execute the console command: the planned videos that went public this minute (and those of minutes the scheduler
     * missed), each one announced once to its channel's subscribers (in batches, App\Notifications\Support\NotificationTasks).
     *
     * @return int
     */
    public function handle() : int
    {
        $minute = now()->startOfMinute();
        $from = $minute->copy();

        if (NotificationTasks::available()) {
            try {
                $since = (int) Cache::rememberForever(self::SINCE_KEY, fn () => $minute->getTimestamp());
            } catch (Throwable) {
                $since = $minute->getTimestamp();
            }

            $from = $minute->copy()->subMinutes(self::CATCH_UP_MINUTES)->max($minute->copy()->setTimestamp($since));
        }

        $videos = Video::where('status', VideoStatus::PLANNED)
            ->whereBetween('scheduled_at', [$from, $minute])
            ->whereColumn('published_at', 'scheduled_at')
            ->orderBy('id')
            ->get();

        foreach ($videos as $video) {
            if (NotificationTasks::wasAnnounced((int) $video->id)) {
                continue;
            }

            VideoPublished::dispatch($video);
            $this->info(now()->format('Y-m-d H:i:s').' - Video: '.$video->title.' ('.$video->id.') published.');
        }

        return Command::SUCCESS;
    }
}
