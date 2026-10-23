<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\Account\DeleteAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeleteUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes for large accounts

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
        $this->user->notify(new DeleteAccount());
        $this->user->forceDelete();
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception) : void
    {

    }
}
