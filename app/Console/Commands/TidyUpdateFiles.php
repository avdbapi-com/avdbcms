<?php

namespace App\Console\Commands;

use App\Support\Updater\Updater;
use Illuminate\Console\Command;

/**
 * updates:tidy - deletes the work files a one-click update (Tools > Updates) no longer needs: the work folder of a
 * finished update (its ~18,500 unpacked files and the zip) and file backups that were pruned or deleted. They leave
 * their place with one rename into storage/app/updates/.trash during the update and are deleted here, for at most
 * --seconds per run; the Updates page does the same in slices of a few seconds while it is open. The scheduler runs
 * this every five minutes, and only while something waits and no update or undo runs (app/Console/Kernel.php); while
 * one runs, nothing is deleted here either (its own steps empty the trash: see Updater::tidy()).
 */
class TidyUpdateFiles extends Command
{
    protected $signature = 'updates:tidy {--seconds=15 : Stop after this many seconds (1-300)}';

    protected $description = 'Delete the work files of finished one-click updates, a few seconds at a time';

    public function handle(): int
    {
        $seconds = max(1, min(300, (int) $this->option('seconds')));
        $updater = app()->bound(Updater::class) ? app(Updater::class) : new Updater();
        $result = $updater->tidy((float) $seconds);

        $this->line(now()->format('Y-m-d H:i:s').' - updates:tidy '.(! empty($result['running'])
            ? 'deleted nothing: an update or undo is running (its own steps clean up; the next run goes on after it)'
            : 'deleted '.$result['deleted'].' files and folders; '
                .($result['busy'] ? 'another clean-up is running' : ($result['pending'] ? 'more is left for the next run' : 'nothing is left'))));

        return self::SUCCESS;
    }
}
