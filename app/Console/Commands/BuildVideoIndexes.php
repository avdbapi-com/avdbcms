<?php

namespace App\Console\Commands;

use App\Support\VideoIndexes;
use Illuminate\Console\Command;

/**
 * Gives the video indexes that "Finish update" left to the scheduler on a big videos table their 1.2.3 definitions
 * (App\Support\VideoIndexes; migration 2026_10_29_110000, judge R3-009). Scheduled every minute while one is not right,
 * in the background; online on MySQL / MariaDB (the site keeps reading and writing the table), giving up at once when a
 * long transaction holds the table and trying again later. Quiet when there is nothing to do. Can also be run by hand
 * (or with "Run now" in Admin > Tools > Background jobs) on a site whose scheduler does not run.
 */
class BuildVideoIndexes extends Command
{
    protected $signature = 'videos:index {--force : Try now, even while a pause after a failure runs}';

    protected $description = 'Give the video indexes that "Finish update" left to the scheduler on a big table their 1.2.3 definitions';

    public function handle(): int
    {
        if (! $this->option('force') && ! VideoIndexes::due()) {
            if (VideoIndexes::pending() === []) {
                $this->line(sprintf('%s - videos: the indexes are in place', now()->format('Y-m-d H:i:s')));
            } elseif (($next = VideoIndexes::nextTry()) !== null) {
                $this->line(sprintf('%s - videos: an earlier try failed; the next one is after %s (--force tries now)',
                    now()->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s')));
            }

            return self::SUCCESS;
        }

        $result = VideoIndexes::align();

        if ($result['built'] !== []) {
            $this->info(sprintf('%s - videos: built %s in %.1f s', now()->format('Y-m-d H:i:s'), implode(', ', $result['built']), $result['seconds']));
        } elseif ($result['skipped'] !== null) {
            $this->warn(sprintf('%s - videos: index not built yet (%s); the scheduler tries again later', now()->format('Y-m-d H:i:s'), $result['skipped']));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (cheap: the cache; the database only while an index is not right). */
    public static function due(): bool
    {
        return VideoIndexes::due();
    }
}
