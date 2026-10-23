<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RegistrationController
{
    public function show(): View|RedirectResponse
    {
        // Check if registration is enabled in admin settings
        if (!config('site.registration_enabled', true)) {
            return redirect()->to(locale_url(route('login')))
                ->with('error', __('Registration is currently disabled.'));
        }

        return view('auth.register');
    }

    public function register(RegisterRequest $request, UserService $userService): RedirectResponse
    {
        // Double-check toggle on form submit too
        if (!config('site.registration_enabled', true)) {
            return redirect()->to(locale_url(route('login')))
                ->with('error', __('Registration is currently disabled.'));
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

        return redirect()->to($editPath);
    }
}
