<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\UserService;
use App\Support\OAuthProviders;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Contracts\User as SocialUser;
use Throwable;

class OAuthController
{
    public function __construct(
        protected UserService $userService,
    ){}

    public function connect(string $service): RedirectResponse {
        $service = $this->normalizeService($service);

        if (! config('site.allow_social_login', true)) {
            return redirect()->to(locale_url(route('login')))->with('error', __('Social login is currently disabled.'));
        }

        if (! OAuthProviders::isConfigured($service)) {
            return redirect()->to(locale_url(route('login')))->with('error', __('This social login provider is not configured.'));
        }

        OAuthProviders::configureRedirect($service);

        try {
            return Socialite::driver($service)
                ->with(['auth_type' => 'rerequest'])
                ->redirect();
        } catch (Throwable) {
            return redirect()->to(locale_url(route('login')))->with('error', __('This social login provider is currently unavailable.'));
        }
    }

    public function callback(string $service): RedirectResponse {
        $service = $this->normalizeService($service);

        $route = match (Auth::check()) {
            true => 'user.edit',
            false => 'login'
        };

        if (! config('site.allow_social_login', true) || ! OAuthProviders::isConfigured($service)) {
            return redirect()->to(locale_url(route($route)))->with('error', __('This social login provider is currently unavailable.'));
        }

        OAuthProviders::configureRedirect($service);

        try {
            $user = Socialite::driver($service)->user();
        } catch (Throwable) {
            return redirect()->to(locale_url(route($route)))->with('error', __('Social login could not be completed. Please try again.'));
        }

        if (!$user->getEmail()) {
            return redirect()->to(locale_url(route($route)))->with(['error' => 'Please agree to give your email']);
        }

        return match (Auth::check()) {
            true => $this->link($service, $user),
            false => $this->register($service, $user)
        };
    }

    public function unlink(string $service): RedirectResponse {
        $service = $this->normalizeService($service);

        $service_id = $service.'_id';

        Auth::user()->update([
            $service_id => null
        ]);

        return redirect()->to(locale_url(route('user.edit')))->with(['status' => 'Your account has been successfully unlinked from '.ucfirst($service)]);
    }

    private function normalizeService(string $service): string
    {
        $service = OAuthProviders::normalize($service);

        abort_unless(OAuthProviders::isSupported($service), 404);

        return $service;
    }

    public function link(string $service, SocialUser $user): RedirectResponse {

        if (User::where($service.'_id', $user->getId())->exists()) {
            return redirect()->to(locale_url(route('user.edit')))->with(['error' => 'This '.ucfirst($service).' account is already linked to another user']);
        }

        Auth::user()->update([
            $service.'_id' => $user->getId()
        ]);

        return redirect()->to(locale_url(route('user.edit')))->with(['status' => 'Your account has been successfully linked to '.ucfirst($service)]);
    }

    public function register (string $service, SocialUser $socialUser) : RedirectResponse {

        $service_id = $service.'_id';

        $user = User::where($service_id, $socialUser->getId())->orWhere('email', $socialUser->getEmail())->first();

        if (!$user) {

            // Block social registration if either toggle is off
            if (!config('site.registration_enabled', true) || !config('site.allow_social_login', true)) {
                return redirect()->to(locale_url(route('login')))->with('error', __('Registration is currently disabled.'));
            }

            $socialUsername = $socialUser->getNickname() ?? $socialUser->getName();

            // Check if username already exist
            $usernameExist = User::where('username', $socialUsername)->exists();

            $username = $usernameExist ? $socialUsername .'-'. random_int(10, 1000)  : $socialUsername;

            $user = $this->userService->register([
                'username' => $username,
                'slug' => User::generateSlug($username),
                'password' => '',
                'email' => $socialUser->getEmail(),
                'email_verified_at' => Carbon::now(),
                $service_id => $socialUser->getId(),
            ]);

            if ($socialUser->getAvatar()) {

                $fileName = Str::of(uniqid())->pipe('md5') . '.png';
                Storage::put(User::AVATAR_FOLDER.'/'.$fileName, file_get_contents($socialUser->getAvatar()));

                $user->update([
                    'avatar' => $fileName
                ]);
            }
        }

        else {
            $user->update([
                $service_id => $socialUser->getId(),
            ]);
        }

        Auth::login($user);

        // Build locale-aware redirect
        $locale = session('app_locale', $user->language ?: config('app.locale', 'en'));
        session()->put('app_locale', $locale);
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        $profilePath = '/profile';
        if ($locale !== $defaultLocale) {
            $profilePath = '/' . $locale . '/profile';
        }

        return redirect()->intended($profilePath);
    }
}
