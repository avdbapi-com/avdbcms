<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;


class SuccessfulLogin
{
    /**
     * Handle the event: the member's "last sign-in" (time and address) for the admin's member list.
     *
     * A suspended account never gets a fresh one (auth-33): the sign-in form refuses it before signing in, and a "Keep me
     * signed in" cookie that still signs it in for one request is undone at once by IsBanned (which signs it out and
     * cycles the cookie's token), so nothing is written for it here either.
     *
     * @param Login $event
     * @return void
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User && $user->is_banned) {
            return;
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->getClientIp(),
        ]);
    }
}
