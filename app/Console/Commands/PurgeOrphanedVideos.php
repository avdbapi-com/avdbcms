<?php

namespace App\Console\Commands;

use App\Helpers\File;
use App\Models\Video;
use Illuminate\Console\Command;

class PurgeOrphanedVideos extends Command
{
    protected $signature = 'videos:purge-orphaned {--chunk=100 : Number of videos to process per batch}';

    protected $description = 'Permanently delete videos whose owner account has been deleted (orphaned records)';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        // Find videos where user_id exists but the user record is soft-deleted or missing
        $count = Video::withTrashed()
            ->whereDoesntHave('user')
            ->whereNotNull('user_id')
            ->count();

        if ($count === 0) {
            $this->info('No orphaned videos found.');
            return self::SUCCESS;
        }

        $this->info("Purging {$count} orphaned videos...");

        $purged = 0;

        Video::withTrashed()
            ->whereDoesntHave('user')
            ->whereNotNull('user_id')
            ->chunkById($chunk, function ($videos) use (&$purged) {
                foreach ($videos as $video) {
                    $video->comments()->forceDelete();
                    $video->interactions()->delete();
                    $video->viewsHistory()->delete();
                    $video->playlists()->detach();
                    $video->reports()->delete();

                    $video->forceDelete();

                    $purged++;
                }
            });

        $this->info("Purged {$purged} orphaned videos permanently.");

        return self::SUCCESS;
    }
}
