<?php

namespace App\Console\Commands;

use App\Support\BlockedWordsPass;
use Illuminate\Console\Command;

/**
 * Goes on with the pass of the update to 1.2.3 that hides the videos whose title, tags or category use words that are
 * always blocked (App\Support\BlockedWordsPass), in short slices: the scheduler starts it every minute while the pass is
 * not finished (on a site too big for the update's own slice), and each run goes on where the last one stopped. Quiet
 * when it had nothing to do.
 */
class HideBlockedWordVideos extends Command
{
    protected $signature = 'videos:hide-blocked-words {--seconds=20 : The longest this run may work}';

    protected $description = 'Hide the videos whose titles, tags or categories use words that are always blocked (after the update to 1.2.3)';

    public function handle(): int
    {
        $result = BlockedWordsPass::run((float) max(1, min(600, (int) $this->option('seconds'))));

        if ($result['checked'] > 0) {
            $this->info(sprintf('%s - blocked words: %s videos checked, %s hidden, %s', now()->format('Y-m-d H:i:s'),
                number_format($result['checked']), number_format($result['hidden']),
                $result['done'] ? 'every video is done' : 'the next run goes on'));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (the pass is not finished). */
    public static function due(): bool
    {
        return BlockedWordsPass::due();
    }
}
