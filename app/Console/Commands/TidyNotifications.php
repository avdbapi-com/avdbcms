<?php

namespace App\Console\Commands;

use App\Notifications\Support\NotificationRetention;
use App\Notifications\Support\NotificationTasks;
use Illuminate\Console\Command;

/**
 * Scheduled every minute while there is work: carries on the notification tasks the queue did not finish (the "sync"
 * queue has no worker; a worker can be missing; a task another worker holds starts it again once it is free), then,
 * once a day, removes old notifications in small batches (NotificationRetention: read ones after 180 days, everything
 * after a member's newest 1,000, those of members that no longer exist; unread ones younger than 30 days always stay).
 * Quiet when it had nothing to do.
 */
class TidyNotifications extends Command
{
    protected $signature = 'notifications:tidy {--seconds=20 : The longest this run may work}';

    protected $description = 'Carry on long notification work and remove old notifications in small batches';

    public function handle(): int
    {
        $seconds = max(5, min(300, (int) $this->option('seconds')));
        $deadline = microtime(true) + $seconds;
        $hadTasks = NotificationTasks::hasWork();
        $tasksLeft = $hadTasks ? ! NotificationTasks::runPending($seconds * 0.6) : false;
        $retention = ['deleted' => 0, 'phase' => 'idle'];

        if (NotificationRetention::due() && ($left = $deadline - microtime(true)) > 1) {
            $retention = NotificationRetention::run($left);
        }

        $forgotten = NotificationTasks::forgetFinished();

        if ($hadTasks || $retention['deleted'] > 0 || $retention['phase'] !== 'idle' || $forgotten > 0) {
            $this->info(sprintf('%s - notifications: tasks %s, %d old removed (%s)', now()->format('Y-m-d H:i:s'),
                $hadTasks ? ($tasksLeft ? 'carried on' : 'finished') : 'none', $retention['deleted'], $retention['phase']));
        }

        return self::SUCCESS;
    }

    /** Whether the scheduler should start this command (cheap: one small query, the retention state). */
    public static function due(): bool
    {
        return NotificationTasks::hasWork() || NotificationRetention::due();
    }
}
