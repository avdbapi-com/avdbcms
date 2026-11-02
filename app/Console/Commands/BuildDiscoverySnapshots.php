<?php

namespace App\Console\Commands;

use App\Support\DiscoverySnapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Works out, in the background, the answers that a page of a big site leaves to the scheduler
 * (App\Support\DiscoverySnapshots): Home's tags with the most videos, its creators posting now and its trending actors,
 * its shorts shelf and the Ctrl+K search chips, exactly as 1.2.2 counted them, for the languages and settings pages
 * asked for in the last hour; each is worked out again before it stops being current, one that failed after
 * DiscoverySnapshots::RETRY_SECONDS. Scheduled every minute while one is wanted, one run at a time, in the
 * background; a run starts no new answer after --seconds (the rest the next minute). Quiet when there is nothing to do.
 * Can also be run by hand (or with "Run now" in Admin > Tools > Background jobs).
 */
class BuildDiscoverySnapshots extends Command
{
    protected $signature = 'discovery:snapshots {--seconds=50 : Start no new answer after this many seconds}';

    protected $description = 'Work out the home page rails and search chips that pages of a big site leave to the scheduler';

    public function handle(): int
    {
        $budget = max(0, (int) $this->option('seconds'));
        $started = microtime(true);
        $done = 0;
        $answers = DiscoverySnapshots::dueAnswers();

        foreach ($answers as $answer) {
            if (microtime(true) - $started >= $budget) {
                $this->line(sprintf('%s - discovery: %d %s worked out (more in the next run)', now()->format('Y-m-d H:i:s'), $done, Str::plural('answer', $done)));

                return self::SUCCESS;
            }

            try {
                $seconds = DiscoverySnapshots::refresh($answer);
                $done++;
                $this->line(sprintf('%s - discovery: %s %s in %.2f s', now()->format('Y-m-d H:i:s'), $answer['name'], (string) json_encode($answer['params']), $seconds));
            } catch (Throwable $e) {
                report($e);
                DiscoverySnapshots::failed($answer);
                $this->warn(sprintf('%s - discovery: %s %s not worked out (%s); tried again in %d minutes', now()->format('Y-m-d H:i:s'),
                    $answer['name'], (string) json_encode($answer['params']), mb_substr(class_basename($e).': '.$e->getMessage(), 0, 200),
                    intdiv(DiscoverySnapshots::RETRY_SECONDS, 60)));
            }
        }

        if ($done > 0) {
            $this->info(sprintf('%s - discovery: %d %s worked out', now()->format('Y-m-d H:i:s'), $done, Str::plural('answer', $done)));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (cheap: the cache only). */
    public static function due(): bool
    {
        return DiscoverySnapshots::due();
    }
}
