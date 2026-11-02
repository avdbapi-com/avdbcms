<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RegistrationController
{
    /**
     * The sign-up page. With sign-ups switched off (registration_enabled) the visitor lands on the sign-in page, which
     * then shows the "Sign-ups are closed" state with the reason (member design 6.12 states): every "Create account"
     * entry is hidden on the site then, so only an old link or a typed address comes here.
     */
    public function show(): View|RedirectResponse
    {
        if (!config('site.registration_enabled', true)) {
            return self::closed();
        }

        return view('auth.register');
    }

    public function register(RegisterRequest $request, UserService $userService): RedirectResponse
    {
        // A form left open in a tab while the admin closed sign-ups.
        if (!config('site.registration_enabled', true)) {
            return self::closed();
        }

        $validated = $request
            ->safe()
            ->merge([
                'slug'     => User::generateSlug($request->get('username')),
                'is_admin' => null
            ])
            ->except('cgu');

        $userService->register($validated);

        // Build locale-aware redirect
        $locale = session('app_locale', config('app.locale', 'en'));
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        $editPath = '/profile/edit';
        if ($locale !== $defaultLocale) {
            $editPath = '/' . $locale . $editPath;
        }

        // A welcome on the first page the new member sees (auth-32).
        return redirect()->to($editPath)
            ->with('success', __('Welcome to :site. Your account is ready.', ['site' => config('app.name')]));
    }

    /** Sign-ups are closed: the sign-in page (in the visitor's language) with the "Sign-ups are closed" state (6.12). */
    private static function closed(): RedirectResponse
    {
        return redirect()->to(locale_url(route('login')))
            ->with('error', __('Sign-ups are closed'))
            ->with('registration_closed', true);
    }
}
