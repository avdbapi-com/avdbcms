<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController
{
    public function show(): View {
        return view('auth.login');
    }

    public function login (LoginRequest $request) : RedirectResponse {

        $credentials = $request->validated();

        $remember = $request->has('remember');

        $user = User::where('username', $credentials['username'])->orWhere('email', $credentials['username'])->first();

        if ($user && Hash::check($credentials['password'], $user->password)) {

            // Capture the locale BEFORE Auth::login() regenerates the session
            $preLoginLocale = session('app_locale') ?: app()->getLocale();

            Auth::login($user, $remember);

            // Priority: pre-login session locale → user's saved language → default
            $locale = $preLoginLocale ?: ($user->language ?: config('app.locale', 'en'));
            session()->put('app_locale', $locale);

            // Also update the user's language preference to match
            if ($user->language !== $locale) {
                $user->update(['language' => $locale]);
            }

            // Build a locale-aware redirect URL
            $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
            $profilePath = '/profile';
            if ($locale !== $defaultLocale) {
                $profilePath = '/' . $locale . '/profile';
            }

            return redirect()->intended($profilePath);
        }

        return back()->with('error', __('auth.failed'))->onlyInput('username');
    }

    /**
     * Log the user out of the application.
     *
     * @param Request  $request
     * @return RedirectResponse
     */
    public function logout (Request $request): RedirectResponse {

        $locale = session('app_locale');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($locale) {
            session()->put('app_locale', $locale);
        }

        // Redirect to locale-prefixed homepage
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        if ($locale && $locale !== $defaultLocale) {
            return redirect()->to('/' . $locale);
        }

        return redirect()->to('/');
    }
}
