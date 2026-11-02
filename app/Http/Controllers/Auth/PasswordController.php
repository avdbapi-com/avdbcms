<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\EmailRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\Support\MemberMail;
use App\Support\ResetLinkTiming;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Throwable;

class PasswordController
{
    public function forgot(): View {
        return view('auth.password.forgot');
    }

    /**
     * "Forgot password": the same answer for every address (auth-21), so the form never tells who has an account: a
     * known address, an unknown one, and one asked for again too soon (the broker's own throttle, which only known
     * addresses reach) all get "If an account uses this e-mail, we sent a link to it". A mail server that refuses the
     * link is logged for the owner and answered the same way.
     *
     * And in the same time (R1R-14): the link was made and mailed inside the request for known addresses only, so they
     * answered measurably later. The answer now leaves first; the address is looked up and the link made and sent right
     * after it, in this same web process (it has the admin's mail settings; a queued job would not, and would wait for
     * the cron). The work is the same for every address up to the answer. PHP-FPM and LiteSpeed end the answer before
     * the work, and PHP's built-in server passes it on when PHP flushes it (its exact length tells the browser it is
     * complete). Servers that can hold the answer until PHP has finished (php-cgi behind mod_fcgid or IIS, nginx's
     * FastCGI buffering, Apache's compression in front of mod_php) are asked to pass it on, and there a request that
     * mailed nothing waits as long as a recent link mail took: ResetLinkTiming.
     */
    public function email (EmailRequest $request) : RedirectResponse {
        $credentials = $request->only('email');
        $timing = app(ResetLinkTiming::class);
        $done = false;

        // A terminating callback runs once per request (and once only in a process that serves several: $done).
        app()->terminating(static function () use ($credentials, $timing, &$done): void {
            if ($done) {
                return;
            }

            $done = true;

            $timing->afterTheAnswer(static function () use ($credentials): bool {
                try {
                    return Password::sendResetLink($credentials) === Password::RESET_LINK_SENT;
                } catch (Throwable $exception) {
                    Log::warning('Password reset link could not be sent.', [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);

                    // a link was made and the mail server refused it: that took a mail's time too
                    return true;
                }
            });
        });

        $answer = back()
            // the link's lifetime as the mail says it: minutes as hours or days (6.21, section 7)
            ->with('status', __('If an account uses this e-mail, we sent a link to it. The link works for :time.', [
                'time' => MemberMail::duration(self::linkMinutes()),
            ]))
            ->with('reset_link_sent', true)
            ->withInput($request->only('email'));

        // Its exact length on every server; where the server may hold it, the requests to pass it on at once.
        $timing->prepare($answer);

        return $answer;
    }

    /**
     * The "Choose a new password" page. The e-mail is filled in (and read-only) only when the link carries it; a link a
     * mail client or a scanner cut (no ?email=) leaves the field open, so the member can type it (auth-15).
     */
    public function reset (Request $request, string $token) : View|RedirectResponse {
        $email = $request->query('email');
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

        return view('auth.password.reset', [
            'token' => $token,
            'linkEmail' => $email,
        ]);
    }

    public function update (ResetPasswordRequest $request) : RedirectResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        // Signed out, the member signs in with the new password: the e-mail is already in the form (auth-32).
        if ($status === Password::PasswordReset) {
            return redirect()->to(locale_url(route('login')))
                ->with('success', __('Your password is changed. Sign in with the new password.'))
                ->withInput(['username' => (string) $request->input('email')]);
        }

        // Every other answer of the broker (an address without an account, a wrong, used or expired link) gets the same
        // reply, so this form never tells whether an address has an account: the page says the link does not work any
        // more and offers a new one (components/auth-errors). It showed the broker's own sentence before ("We can't find
        // a user with that email address." against "This password reset token is invalid."). The form is limited like
        // the other sign-in forms (throttle:password-reset, routes/auth.php).
        return back()
            ->with('reset_link_invalid', true)
            ->withInput($request->only('email'));
    }

    /** How long a reset link works (config auth.passwords.<broker>.expire, 60 by default). */
    public static function linkMinutes(): int
    {
        $broker = (string) config('auth.defaults.passwords', 'users');

        return max(1, (int) config('auth.passwords.'.$broker.'.expire', 60));
    }
}
