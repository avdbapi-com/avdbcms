<?php

namespace App\Console\Commands;

use App\Support\EngagementCounters;
use Illuminate\Console\Command;

/**
 * Counts the likes, dislikes and public comments of every video again, in short slices (App\Support\EngagementCounters):
 * the scheduler starts it every five minutes while a pass is due (once a day, and right after the update to 1.2.3), and
 * each run goes on where the last one stopped. A big site is never held up: a slice reads 500 videos at a time and
 * writes only the counts that are wrong. Quiet when it had nothing to do.
 */
class RecountVideoEngagement extends Command
{
    protected $signature = 'videos:recount-engagement {--seconds=50 : The longest this run may work} {--fresh : Start a new pass from the first video}';

    protected $description = 'Count the likes, dislikes and public comments of every video again, in short slices';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            EngagementCounters::request();
        }

        $result = EngagementCounters::run((float) max(1, min(600, (int) $this->option('seconds'))));

        if ($result['checked'] > 0) {
            $this->info(sprintf('%s - video counts: %s checked, %s put right, %s', now()->format('Y-m-d H:i:s'),
                $this->videos($result['checked']), $this->videos($result['corrected']),
                $result['done'] ? 'every video is counted' : 'the next run goes on'));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (cheap: the pass's state from the cache). */
    public static function due(): bool
    {
        return EngagementCounters::due();
    }

    private function videos(int $count): string
    {
        return $count === 1 ? '1 video' : number_format($count).' videos';
    }
}
