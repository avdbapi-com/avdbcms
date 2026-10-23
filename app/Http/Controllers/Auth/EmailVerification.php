<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\EmailVerificationUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerification
{
    public function notice(): View
    {
        if (!config('site.email_verification_required', false)) {
            abort(404);
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('pages.home');
    }

    public function send(Request $request): RedirectResponse
    {
        if (!config('site.email_verification_required', false)) {
            return back()->with(['status' => 'Email verification is disabled.']);
        }

        return $this->sendVerificationNotification(function () use ($request): void {
            $request->user()->sendEmailVerificationNotification();
        });
    }

    public function verifyUpdate(EmailVerificationUpdateRequest $request)
    {
        $emailExists = User::query()->where('email', $request->user()->getTemporaryEmailForVerification())->exists();

        if ($emailExists) {
            return redirect()->route('user.edit')->with(['error' =>'This email is already in use !']);
        }

        $request->fulfill();

        return redirect()->route('user.edit');
    }

    public function sendUpdate(Request $request): RedirectResponse
    {
        if (!$request->user()->getTemporaryEmailForVerification()) {
            return back()->with(['error' => 'No pending email change to verify.']);
        }

        return $this->sendVerificationNotification(function () use ($request): void {
            $request->user()->sendUpdatedEmailVerificationNotification();
        });
    }

    public function cancelUpdate(Request $request)
    {
        $request->user()->update(['temporary_email' => null]);

        return redirect()->route('user.edit');
    }

    private function sendVerificationNotification(callable $sender): RedirectResponse
    {
        try {
            $sender();
        } catch (Throwable $exception) {
            Log::warning('Email verification notification could not be sent.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return back()->with(['error' => $this->mailFailureMessage($exception)]);
        }

        return back()->with(['status' => 'Verification link sent successfully !']);
    }

    private function mailFailureMessage(Throwable $exception): string
    {
        if (str_contains($exception->getMessage(), 'SMTP account is not yet activated')) {
            return 'Verification email could not be sent because the SMTP account is not activated. Activate the Brevo SMTP account, then try again.';
        }

        return 'Verification email could not be sent. Please check the SMTP settings and try again.';
    }
}
