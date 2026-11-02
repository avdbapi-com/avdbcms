<?php

namespace App\Console\Commands;

use App\Notifications\Support\NotificationIndexes;
use Illuminate\Console\Command;

/**
 * Builds the index of the notifications page's "Unread" tab that "Finish update" leaves to the scheduler on a big
 * notifications table (NotificationIndexes; migration 2026_10_27_120000). Scheduled every minute while it is missing,
 * in the background; online on MySQL / MariaDB (the site keeps reading and writing the table), giving up at once when a
 * long transaction holds the table and trying again later. Quiet when there is nothing to do. Can also be run by hand
 * (or with "Run now" in Admin > Tools > Background jobs) on a site whose scheduler does not run.
 */
class BuildNotificationIndexes extends Command
{
    protected $signature = 'notifications:index {--force : Try now, even while a pause after a failure runs}';

    protected $description = 'Build the notifications index that "Finish update" left to the scheduler on a big table';

    public function handle(): int
    {
        if (! $this->option('force') && ! NotificationIndexes::due()) {
            if (NotificationIndexes::missing() === []) {
                $this->line(sprintf('%s - notifications: the indexes are in place', now()->format('Y-m-d H:i:s')));
            } elseif (($next = NotificationIndexes::nextTry()) !== null) {
                $this->line(sprintf('%s - notifications: an earlier try failed; the next one is after %s (--force tries now)',
                    now()->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s')));
            }

            return self::SUCCESS;
        }

        $result = NotificationIndexes::build();

        if ($result['built'] !== []) {
            $this->info(sprintf('%s - notifications: built %s in %.1f s', now()->format('Y-m-d H:i:s'), implode(', ', $result['built']), $result['seconds']));
        } elseif ($result['skipped'] !== null) {
            $this->warn(sprintf('%s - notifications: index not built yet (%s); the scheduler tries again later', now()->format('Y-m-d H:i:s'), $result['skipped']));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (cheap: the cache; the database only while an index is missing). */
    public static function due(): bool
    {
        return NotificationIndexes::due();
    }
}
