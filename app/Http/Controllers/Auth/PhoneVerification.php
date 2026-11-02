<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Profile\CodeRequest;
use App\Models\User;
use App\Support\SerialAttempts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Phone verification (member design 6.17; drawn on the Profile section of Account settings). Only when
 * the site can send texts (SmsAvailability::canSend(): a Brevo key is set); the code is sent by a QUEUED
 * notification (VerifyPhone), so the request never waits for the SMS service (account-11). A code works for 10 minutes
 * and for 5 tries; the answers are translated sentences with the tries left, as a flash message for the page or as JSON
 * ({message, tries_left, expires_in, resend_in, verified}) for a script.
 */
class PhoneVerification
{
    /** How long a code works. */
    public const CODE_MINUTES = 10;

    /** Wrong codes allowed before a new code is needed. */
    public const MAX_TRIES = 5;

    /** The wait before another code (the route's own limit is one a minute: rate:1). */
    public const RESEND_SECONDS = 60;

    public function send(Request $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! self::smsAvailable()) {
            return self::answer($request, 'error', __('Phone checks are not set up on this site.'), 422);
        }

        if (! $user->getPhone()) {
            return self::answer($request, 'error', __('Add your phone number first.'), 422);
        }

        if (! $user->sendPhoneVerificationNotification()) {
            // MustVerifyPhone sends at most 5 codes an hour per member: nothing was sent, the code sent
            // before keeps working, and the member reads when the next one can come.
            return self::answer($request, 'error', $user->phoneCodeLimitMessage(), 429, [
                'resend_in' => $user->phoneCodeResendSeconds(),
            ]);
        }

        Cache::put(self::sentKey($user), now()->getTimestamp(), now()->addMinutes(self::CODE_MINUTES));
        Cache::forget(self::triesKey($user));

        // a counted line: Arabic says 10 minutes with another word than 1, 2 or 11 minutes (R2A-040)
        return self::answer($request, 'status', self::codeSentMessage(), 200, [
            'tries_left' => self::MAX_TRIES,
            'expires_in' => self::CODE_MINUTES * 60,
            'resend_in' => self::RESEND_SECONDS,
        ]);
    }

    public function verify(CodeRequest $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // One check at a time per member (R1-164): the tries of a code are read, compared and written under the member's
        // lock, so codes sent side by side cannot all pass the count of 5 (the route also allows 10 checks a minute).
        // A request that does not get its turn compares nothing.
        return SerialAttempts::run(
            $user->phoneCodeCheckKey(),
            fn (): RedirectResponse|JsonResponse => $this->checkCode($request, $user),
            fn (): RedirectResponse|JsonResponse => self::answer($request, 'error', __('Too many tries. Wait a minute and try again.'), 429)
        );
    }

    private function checkCode(CodeRequest $request, User $user): RedirectResponse|JsonResponse
    {
        $sentAt = Cache::get(self::sentKey($user));
        $stored = (string) ($user->getPhoneCodeVerification() ?? '');

        if ($stored === '' || ! is_numeric($sentAt)) {
            return self::answer($request, 'error', __('This code has expired. Send a new one.'), 422, ['tries_left' => 0]);
        }

        $expiresIn = max(0, (int) $sentAt + self::CODE_MINUTES * 60 - now()->getTimestamp());
        $tries = (int) Cache::get(self::triesKey($user), 0);

        if ($tries >= self::MAX_TRIES) {
            return self::answer($request, 'error', self::tooManyTries($expiresIn), 429, ['tries_left' => 0, 'expires_in' => $expiresIn]);
        }

        if (hash_equals($stored, (string) $request->input('code'))) {
            $user->markPhoneAsVerified();
            Cache::forget(self::sentKey($user));
            Cache::forget(self::triesKey($user));

            return self::answer($request, 'status', __('Your phone number is verified.'), 200, ['verified' => true]);
        }

        $tries++;
        Cache::put(self::triesKey($user), $tries, now()->addSeconds(max(1, $expiresIn)));
        $left = max(0, self::MAX_TRIES - $tries);

        if ($left === 0) {
            return self::answer($request, 'error', self::tooManyTries($expiresIn), 429, ['tries_left' => 0, 'expires_in' => $expiresIn]);
        }

        return self::answer($request, 'error', trans_choice(
            '{1} That code is wrong. Check the message and try again. :count try left.|[2,*] That code is wrong. Check the message and try again. :count tries left.',
            $left,
            ['count' => $left]
        ), 422, ['tries_left' => $left, 'expires_in' => $expiresIn]);
    }

    /**
     * Whether the site can send texts: SmsAvailability::canSend(), else the same answer here: a Brevo key in
     * .env (BREVO_API_KEY, config services.brevo.api_key), or a local install (VerifyPhone e-mails the code there).
     */
    public static function smsAvailable(): bool
    {
        try {
            if (class_exists(\App\Support\SmsAvailability::class) && method_exists(\App\Support\SmsAvailability::class, 'canSend')) {
                return (bool) \App\Support\SmsAvailability::canSend();
            }

            return trim((string) config('services.brevo.api_key', '')) !== '' || app()->isLocal();
        } catch (Throwable) {
            return false;
        }
    }

    /** The sentence after a code was sent, with the minutes it works (Account settings after a new code or a new number). */
    public static function codeSentMessage(): string
    {
        return trans_choice('{1} Code sent. It works for :count minute.|[2,*] Code sent. It works for :count minutes.', self::CODE_MINUTES, ['count' => self::CODE_MINUTES]);
    }

    /** "Too many tries. Send a new code in 10 minutes." (the minutes until the code runs out, at least one) */
    private static function tooManyTries(int $expiresIn): string
    {
        $minutes = max(1, (int) ceil($expiresIn / 60));

        return trans_choice('{1} Too many tries. Send a new code in :count minute.|[2,*] Too many tries. Send a new code in :count minutes.', $minutes, ['count' => $minutes]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function answer(Request $request, string $kind, string $message, int $status, array $extra = []): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $extra, $status);
        }

        // A refused code (wrong, expired, too many tries) is the code field's error (R1-115): printed under the field,
        // which is marked invalid and gets the focus, like every other error of Account settings (not a message on top).
        if ($kind === 'error' && $request->routeIs('phone.verify')) {
            return back()->withErrors(['code' => $message])->with('phone_verification', ['kind' => $kind] + $extra);
        }

        return back()->with($kind, $message)->with('phone_verification', ['kind' => $kind] + $extra);
    }

    private static function sentKey(User $user): string
    {
        return 'phone-code.sent.'.$user->getKey();
    }

    private static function triesKey(User $user): string
    {
        return 'phone-code.tries.'.$user->getKey();
    }
}
