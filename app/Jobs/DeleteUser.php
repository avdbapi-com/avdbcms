<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\Account\DeleteAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes a member who deleted their account (member design 6.9, account-17).
 *
 * The account is already closed when this job is queued: ProfileController::delete() soft-deletes it at once, so the
 * member is signed out everywhere, cannot sign in again and no longer appears anywhere (their channel answers 404,
 * their videos leave every list). This job then removes the rest for good; the queue restores the closed account
 * (a queued model is found with its soft-deleted rows), and a job that runs twice finds nothing and stops.
 */
class DeleteUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes for large accounts

    /** A large account may need another try (a lock, a slow disk): three in all, a minute apart. */
    public int $tries = 3;

    public int $backoff = 60;

    public User $user;

    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     * Permanently delete user and ALL related data.
     * UserObserver.forceDeleting handles full cascade:
     * videos, comments, playlists, interactions, subscriptions, notifications,
     * views, activity, reports, favorites, tokens, premium, transactions,
     * searches, avatar/banner files
     *
     * @return void
     * @throws \Exception
     */
    public function handle() : void
    {
        // The goodbye e-mail goes once, on the first try.
        if ($this->attempts() <= 1) {
            try {
                $this->user->notify(new DeleteAccount());
            } catch (Throwable $exception) {
                // A mail server that refuses the message never keeps the account on the site.
                Log::warning('The account deletion e-mail could not be sent.', [
                    'user_id' => $this->user->getKey(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $this->user->forceDelete();
    }

    /**
     * Handle a job failure: the account stays closed (soft-deleted), so nobody can use it or see it; the queue's failed
     * jobs list keeps the job to try again from the admin.
     *
     * @param Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception) : void
    {
        Log::error('An account deletion did not finish; the account stays closed.', [
            'user_id' => $this->user->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }
}
