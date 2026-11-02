<?php

namespace App\Http\Middleware;

use App\Support\SerialAttempts;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * password.confirm: the member's current password before a change of the password, "Sign out of all other devices" and
 * deleting the account (member design 6.9).
 *
 * A wrong or missing password goes back to the form with the message under the password field (account-38: before,
 * one line at the top of the page, and the delete window was gone); the other fields come back filled, never the
 * passwords. A script gets the same message as a 422 answer. With the right password the route's own answer is handed
 * back whatever it is (a redirect, a page, JSON, a 404: the member scaffold widened the type; a RedirectResponse
 * declaration turned every other answer into a 500).
 *
 * Wrong passwords are limited per account (R1-085): the fifth wrong one within 10 minutes closes the check for 10
 * minutes ("Too many wrong passwords. Try again in 10 minutes.", 429 for a script), the right one included, so a
 * session somebody else got hold of cannot try passwords until one fits and then take the account over. The same rule
 * as the password of Tools > Updates. An empty field is not a try; the right password clears the count. The checks of
 * one account run one at a time (App\Support\SerialAttempts): guesses sent side by side cannot pass the count.
 */
class RequirePassword
{
    /** Wrong passwords allowed within WINDOW seconds; the fifth closes the check for WINDOW seconds. */
    public const TRIES = 5;

    public const WINDOW = 600;

    /**
     * Handle an incoming request.
     *
     * @param Request  $request
     * @param  \Closure(Request): (Response|RedirectResponse)  $next
     * @return \Symfony\Component\HttpFoundation\Response the redirect back, or the route's own answer (a redirect, a page, a 404, JSON)
     */
    public function handle(Request $request, Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        $password = $request->input('current_password');
        // the request's user, else the signed-in user of the guard (a request made in code has no user resolver)
        $user = $request->user() ?? auth()->user();

        if (! is_string($password) || $password === '') {
            return $this->refuse($request, __('Enter your password.'), 422);
        }

        if ($user === null) {
            return $this->refuse($request, __('That password is wrong. Try again.'), 422);
        }

        $refusal = SerialAttempts::run(
            'password-confirm:'.$user->getAuthIdentifier(),
            fn (): ?array => $this->check($user, $password),
            // another check of this account did not end in time (guesses sent side by side): nothing is compared
            fn (): array => [__('Too many tries. Wait a minute and try again.'), 429, 60]
        );

        if ($refusal !== null) {
            return $this->refuse($request, ...$refusal);
        }

        return $next($request);
    }

    /**
     * The counted check: null when the password is right, else [message, status, seconds to wait or null].
     *
     * @return array{0: string, 1: int, 2: int|null}|null
     */
    private function check(Authenticatable $user, string $password): ?array
    {
        if (($wait = self::lockedFor($user)) > 0) {
            return [self::lockedMessage($wait), 429, $wait];
        }

        if (Hash::check($password, (string) $user->getAuthPassword())) {
            RateLimiter::clear(self::attemptsKey($user));

            return null;
        }

        RateLimiter::hit(self::attemptsKey($user), self::WINDOW);

        if (RateLimiter::attempts(self::attemptsKey($user)) >= self::TRIES) {
            // The block lasts WINDOW seconds from the fifth wrong try (not from the first).
            RateLimiter::clear(self::attemptsKey($user));
            RateLimiter::hit(self::lockKey($user), self::WINDOW);

            return [self::lockedMessage(self::WINDOW), 429, self::WINDOW];
        }

        return [__('That password is wrong. Try again.'), 422, null];
    }

    private function refuse(Request $request, string $message, int $status, ?int $retryAfter = null): \Symfony\Component\HttpFoundation\Response
    {
        if ($request->expectsJson()) {
            $response = response()->json(['message' => $message, 'errors' => ['current_password' => [$message]]], $status);

            return $retryAfter !== null ? $response->header('Retry-After', (string) $retryAfter) : $response;
        }

        return back()
            ->withInput($request->except(['current_password', 'new_password', 'new_password_confirmation', 'password', 'password_confirmation']))
            ->withErrors(['current_password' => $message]);
    }

    /** Seconds until this account may confirm with its password again (0: now). */
    public static function lockedFor(Authenticatable $user): int
    {
        return RateLimiter::tooManyAttempts(self::lockKey($user), 1) ? max(1, RateLimiter::availableIn(self::lockKey($user))) : 0;
    }

    private static function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return trans_choice('Too many wrong passwords. Try again in 1 minute.|Too many wrong passwords. Try again in :count minutes.', $minutes, ['count' => $minutes]);
    }

    private static function attemptsKey(Authenticatable $user): string
    {
        return 'password-confirm:'.$user->getAuthIdentifier();
    }

    private static function lockKey(Authenticatable $user): string
    {
        return 'password-confirm-lock:'.$user->getAuthIdentifier();
    }
}
