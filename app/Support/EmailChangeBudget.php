<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Every e-mail address change mails the new address (a confirm link from the site's own domain). A member gets
 * MAX such mails per hour, shared by Profile > Save (a new address) and "Send the link again", so nobody can use the
 * site to mail any address without limit (change, cancel, change again ...). Security plan S26.
 */
final class EmailChangeBudget
{
    public const MAX = 3;

    public const SECONDS = 3600;

    public static function key(int|string $userId): string
    {
        return 'email-change:'.$userId;
    }

    /** Takes one mail from the member's budget: false (and nothing taken) when the hour's budget is used up. */
    public static function take(int|string $userId): bool
    {
        $key = self::key($userId);

        if (RateLimiter::tooManyAttempts($key, self::MAX)) {
            return false;
        }

        RateLimiter::hit($key, self::SECONDS);

        return true;
    }
}
