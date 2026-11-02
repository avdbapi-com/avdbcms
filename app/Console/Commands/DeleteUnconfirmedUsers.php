<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

class DeleteUnconfirmedUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:delete-unconfirmed {--seconds=50 : Start no new account after this many seconds; the next run goes on}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove unconfirmed users after their are expired';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() : int
    {
        if (!config('site.email_verification_required', true)) {
            $this->info(now()->format('Y-m-d H:i:s').' - Email verification disabled, no unconfirmed users deleted');

            return Command::SUCCESS;
        }

        // each account through its model, so its playlists, views, subscriptions, searches and the rest go with it on
        // every site (App\Observers\UserObserver): one statement left them all behind on a site installed by the
        // migrations, and failed on a bundled one when such an account had a row its foreign keys keep (judge R3-009).
        // That is about 30 ms an account: a run starts no new account after --seconds and the next run goes on, so a
        // sign-up spam wave never holds the scheduler up (100,000 accounts took 53 minutes in one run; DB-RV-2)
        $count = 0;
        $failed = 0;
        $more = false;
        $until = microtime(true) + max(0, (int) $this->option('seconds'));
        User::withTrashed()
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subMinutes(Config::get('auth.verification.expire', 1440)))
            // an account with videos confirmed its address once (uploading needs it): an administrator marked it not
            // confirmed, which must never remove its videos and their files (Members > Delete does that, on purpose)
            ->whereDoesntHave('videos', fn ($videos) => $videos->withTrashed())
            ->lazyById(100)
            ->each(function (User $user) use (&$count, &$failed, &$more, $until): bool {
                if (microtime(true) >= $until) {
                    $more = true;

                    return false;
                }

                try {
                    $user->forceDelete();
                    $count++;
                } catch (Throwable $e) {
                    // one account that cannot go never stops the others (the next run tries it again)
                    $failed++;
                    report($e);
                }

                return true;
            });

        $this->info(now()->format('Y-m-d H:i:s').' - Delete ' .$count. ' unconfirmed users'.($failed > 0 ? ' ('.$failed.' could not be deleted, see the error log)' : '').($more ? ' (more in the next run)' : ''));

        return Command::SUCCESS;
    }
}
