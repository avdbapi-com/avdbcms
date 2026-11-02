<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\EmailVerificationUpdateRequest;
use App\Models\User;
use App\Notifications\Support\MemberMail;
use App\Support\EmailChangeBudget;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class EmailVerification
{
    /**
     * "Confirm your e-mail" (member design 6.12, auth-20): the sign-in card with the address, the last result and the
     * resend countdown. A member whose address is already confirmed is not asked again: home, with that message.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        if (!config('site.email_verification_required', false)) {
            abort(404);
        }

        if ($request->user()?->hasVerifiedEmail()) {
            return $this->alreadyConfirmed();
        }

        return view('auth.verify-email');
    }

    /**
     * The link of the confirmation mail: home with a message the page shows (the layout's toast). It flashed "success",
     * which neither the home page nor the layout prints, so the address was confirmed without a word. An old link of an
     * address that is already confirmed says so instead of welcoming the member a second time.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->alreadyConfirmed();
        }

        $request->fulfill();

        return redirect()->to(locale_url(route('pages.home')))->with('toast', [
            'message' => __('Your e-mail is confirmed. Welcome to :site.', ['site' => config('app.name')]),
            'type' => 'success',
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        if (!config('site.email_verification_required', false)) {
            return back()->with(['status' => __('E-mail confirmation is switched off on this site.')]);
        }

        // "Send the link again" for an address that is confirmed meanwhile (another tab, an older page): no mail.
        if ($request->user()->hasVerifiedEmail()) {
            return $this->alreadyConfirmed();
        }

        return $this->sendVerificationNotification($request, function () use ($request): void {
            $request->user()->sendEmailVerificationNotification();
        });
    }

    public function verifyUpdate(EmailVerificationUpdateRequest $request)
    {
        $emailExists = User::query()->where('email', $request->user()->getTemporaryEmailForVerification())->exists();

        if ($emailExists) {
            return redirect()->route('user.edit')->with(['error' => __('This e-mail is already used by another account.')]);
        }

        $request->fulfill();

        return redirect()->route('user.edit');
    }

    public function sendUpdate(Request $request): RedirectResponse
    {
        if (!$request->user()->getTemporaryEmailForVerification()) {
            return back()->with(['error' => __('There is no new e-mail waiting to be confirmed.')]);
        }

        // The same budget as a new address on the profile: 3 mails to new addresses per member per hour. The refusal
        // says when the budget frees up (it was a bare "Too many attempts" without the time).
        if (! EmailChangeBudget::take($request->user()->id)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn(EmailChangeBudget::key($request->user()->id)) / 60));

            return back()->with(['error' => trans_choice(
                '{1} You asked for too many confirmation links. Try again in :count minute.|[2,*] You asked for too many confirmation links. Try again in :count minutes.',
                $minutes,
                ['count' => $minutes]
            )]);
        }

        return $this->sendVerificationNotification($request, function () use ($request): void {
            $request->user()->sendUpdatedEmailVerificationNotification();
        });
    }

    public function cancelUpdate(Request $request)
    {
        $request->user()->update(['temporary_email' => null]);

        return redirect()->route('user.edit');
    }

    /** Home, with "Your e-mail is already confirmed." (the layout's toast). */
    private function alreadyConfirmed(): RedirectResponse
    {
        return redirect()->to(locale_url(route('pages.home')))->with('toast', [
            'message' => __('Your e-mail is already confirmed.'),
            'type' => 'success',
        ]);
    }

    private function sendVerificationNotification(Request $request, callable $sender): RedirectResponse
    {
        try {
            $sender();
        } catch (Throwable $exception) {
            Log::warning('Email verification notification could not be sent.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return back()->with(['error' => $this->mailFailureMessage($exception, (bool) $request->user()?->is_admin)]);
        }

        // The link's lifetime as the mail itself says it: minutes as hours or days ("24 hours", 6.21, section 7).
        return back()->with(['status' => __('A new link is on its way. It works for :time.', [
            'time' => MemberMail::duration((int) config('auth.verification.expire', 60)),
        ])]);
    }

    /**
     * What the page says when the mail server refused the mail. Members read a plain sentence; how to fix the mail setup
     * (activate the Brevo SMTP account, check the SMTP settings) is for the site's administrators, who still read it,
     * and for the log above (it was shown to every member).
     */
    private function mailFailureMessage(Throwable $exception, bool $forAdministrator): string
    {
        if (! $forAdministrator) {
            return __('We could not send the e-mail right now. Try again in a few minutes. If it keeps failing, contact us.');
        }

        if (str_contains($exception->getMessage(), 'SMTP account is not yet activated')) {
            return __('Verification email could not be sent because the SMTP account is not activated. Activate the Brevo SMTP account, then try again.');
        }

        return __('Verification email could not be sent. Please check the SMTP settings and try again.');
    }
}
