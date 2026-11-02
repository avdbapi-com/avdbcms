<?php

namespace App\Listeners;

use App\Events\Account\EmailUpdated;
use App\Events\Account\PasswordUpdated;
use App\Notifications\Account\PasswordUpdate;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;

class AccountEventSubscriber
{
    /**
     * A new password: saved together with a new "remember me" token (account-18). Before, the token was changed on the
     * model and never saved, so a stolen "remember me" cookie kept working after the password was changed. The member's
     * current device keeps its own "remember me": the controller queues its cookie again with the new token
     * (ProfileController::keepThisDeviceSignedIn()). The model hashes the plain password once (its mutator).
     */
    public function sendUserPasswordUpdatedNotification(PasswordUpdated $event): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $user->forceFill([
            'password' => $event->password,
            'remember_token' => Str::random(60),
        ])->save();

        $user->notify(new PasswordUpdate());
    }

    /**
     * Handle user email update events.
     */
    public function sendUserEmailUpdatedNotification(EmailUpdated $event): void
    {
        $user = auth()->user();

        $user->update(['temporary_email' => $event->email]);

        $user->sendUpdatedEmailVerificationNotification();
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PasswordUpdated::class => 'sendUserPasswordUpdatedNotification',
            EmailUpdated::class => 'sendUserEmailUpdatedNotification',
        ];
    }
}
