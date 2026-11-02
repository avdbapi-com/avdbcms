<?php

namespace App\Jobs;

use App\Notifications\Support\NotificationTasks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Carries on the waiting notification tasks (NotificationTasks: "Mark all as read", "Delete all", a deleted member's
 * notifications, a new video to the subscribers, an admin's notification to every member) for up to WORKER_SECONDS.
 *
 * One chain of jobs: each job carries the token NotificationTasks gave it; a job whose token is not the chain's (an
 * extra one, or one replaced by a job that runs sooner) stops at once. While work is left the job asks for the next
 * one: at once when a task can be taken, else for the moment the first held task is free again (another worker holds
 * it, or it waits after a failure), so a held task never makes the queue spin. Never used on the "sync" queue (it
 * would run inside the page): the scheduler and the next requests carry on there.
 */
class RunNotificationTasks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public ?string $token = null)
    {
    }

    public function handle(): void
    {
        // a job without a token (queued by hand) carries on only when no chain is on record
        $token = $this->token ?? Str::random(40);

        if (! NotificationTasks::takeChain($token)) {
            return; // another job carries the work on
        }

        NotificationTasks::passChainOn($token, NotificationTasks::work(NotificationTasks::$workerSeconds));
    }
}
