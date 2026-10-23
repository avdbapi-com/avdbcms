<?php

namespace App\Console\Commands;

use App\Services\PlaybackProgressSignalService;
use Illuminate\Console\Command;

class PrunePlaybackProgressSignals extends Command
{
    protected $signature = 'playback-progress:prune {--chunk=1000 : Rows to delete per database batch}';

    protected $description = 'Delete expired playback progress signals.';

    public function handle(PlaybackProgressSignalService $progress): int
    {
        $deleted = $progress->pruneExpired(now(), (int) $this->option('chunk'));

        $this->info("Deleted {$deleted} expired playback progress signal rows.");

        return Command::SUCCESS;
    }
}
