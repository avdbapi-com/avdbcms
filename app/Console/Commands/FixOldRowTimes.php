<?php

namespace App\Console\Commands;

use App\Support\LegacyRowTimes;
use Illuminate\Console\Command;

/**
 * Puts the history, subscription and saved-playlist rows, the "Your activity" rows of likes and dislikes and the "Date
 * added" of playlist videos that 1.2.0 to 1.2.2 dated with the database server's clock in the site's time zone, in short
 * slices (App\Support\LegacyRowTimes): the scheduler starts it every minute while rows
 * wait (right after the update to 1.2.3), and each run goes on where the last one stopped. The zone is the one the update
 * noted from the admin's Time zone setting (read by the conversion itself, not taken from this process's configuration).
 * A row is never shifted twice. Quiet when it had nothing to do.
 */
class FixOldRowTimes extends Command
{
    protected $signature = 'history:fix-old-times {--seconds=20 : The longest this run may work}';

    protected $description = 'Put the times of history, subscription, saved-playlist, like / dislike activity and playlist "Date added" rows written before 1.2.3 in the site time zone';

    public function handle(): int
    {
        $result = LegacyRowTimes::run((float) max(1, min(600, (int) $this->option('seconds'))));

        if ($result['checked'] > 0) {
            $this->info(sprintf('%s - row times: %s checked, %s put in the site time zone (%s), %s', now()->format('Y-m-d H:i:s'),
                number_format($result['checked']), number_format($result['changed']), (string) $result['zone'],
                $result['done'] ? 'every row is done' : 'the next run goes on'));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (some rows still wait). */
    public static function due(): bool
    {
        return LegacyRowTimes::due();
    }
}
