<?php

namespace App\Console\Commands;

use App\Support\Upload18\PendingUploadFinisher;
use Illuminate\Console\Command;

/**
 * "Never lose a video": finishes the uploads whose page was closed while Upload18 processed them (the members' pending
 * drafts). The scheduler runs it as two tasks (App\Console\Kernel), each only while it has work:
 *  - every minute, one short pass for all due drafts (no sleeping, a few Upload18 list calls for all of them);
 *  - every minute with --thumbnails, server thumbnails for one finished draft whose browser sent none (this can take
 *    minutes, so the check of the other drafts never waits for it).
 */
class FinishPendingUploads extends Command
{
    protected $signature = 'upload18:finish-pending
        {--seconds=50 : Start no new check after this many seconds}
        {--thumbnails : Make server thumbnails for one finished upload whose browser sent none, instead of the checks}';

    protected $description = 'Finish member uploads that Upload18 completed after the upload page was closed';

    public function handle(PendingUploadFinisher $finisher): int
    {
        if ($this->option('thumbnails')) {
            $this->line(sprintf(
                '[%s] upload18:finish-pending --thumbnails: %d thumbnails',
                now()->toDateTimeString(),
                $finisher->thumbnails()
            ));

            return self::SUCCESS;
        }

        $stats = $finisher->run(max(5, min(300, (int) $this->option('seconds'))));

        $this->line(sprintf(
            '[%s] upload18:finish-pending: %d due, %d finished, %d waiting, %d failed%s',
            now()->toDateTimeString(),
            $stats['due'],
            $stats['finished'],
            $stats['waiting'],
            $stats['failed'],
            $stats['unavailable'] ? ' (Upload18 did not answer: checking again in 2 minutes)' : ''
        ));

        return self::SUCCESS;
    }

    /** The minute task's "when": only while a pending draft is due. */
    public static function due(): bool
    {
        return PendingUploadFinisher::due();
    }

    /** The thumbnail task's "when": only while a finished draft waits for server thumbnails. */
    public static function thumbnailsDue(): bool
    {
        return PendingUploadFinisher::thumbnailsDue();
    }
}
