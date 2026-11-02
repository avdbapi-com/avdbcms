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
        // A member linking an account from Account settings goes back there with the reason; a visitor to the sign-in
        // page (the sign-in page sends a signed-in member away, so the message was lost: account-13).
        $back = Auth::check() ? \App\Http\Controllers\User\ProfileController::sectionUrl('security') : locale_url(route('login'));

        if (! config('site.allow_social_login', true)) {
            return redirect()->to($back)->with('error', __('Social login is currently disabled.'));
        }

        if (! OAuthProviders::isConfigured($service)) {
            return redirect()->to($back)->with('error', __('This social login provider is not configured.'));
        }

        OAuthProviders::configureRedirect($service);

        try {
            return Socialite::driver($service)
                ->with(['auth_type' => 'rerequest'])
                ->redirect();
        } catch (Throwable) {
            return redirect()->to($back)->with('error', __('This social login provider is currently unavailable.'));
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
            return redirect()->to(locale_url(route($route)))->with(['error' => __('Allow access to your e-mail address to sign in with this account.')]);
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

        return redirect()->to(\App\Http\Controllers\User\ProfileController::sectionUrl('security'))->with(['status' => __('Your :provider account is no longer linked.', ['provider' => self::providerName($service)])]);
    }

    /** The provider's name as people know it (a brand: never translated). */
    private static function providerName(string $service): string
    {
        return match ($service) {
            'google' => 'Google',
            'facebook' => 'Facebook',
            default => ucfirst($service),
        };
    }

    private function normalizeService(string $service): string
    {
        $service = OAuthProviders::normalize($service);

        abort_unless(OAuthProviders::isSupported($service), 404);

        return $service;
    }

    public function link(string $service, SocialUser $user): RedirectResponse {

        if (User::where($service.'_id', $user->getId())->exists()) {
            return redirect()->to(\App\Http\Controllers\User\ProfileController::sectionUrl('security'))->with(['error' => __('This :provider account is already linked to another member.', ['provider' => self::providerName($service)])]);
        }

        Auth::user()->update([
            $service.'_id' => $user->getId()
        ]);

        return redirect()->to(\App\Http\Controllers\User\ProfileController::sectionUrl('security'))->with(['status' => __('Your :provider account is linked. You can sign in with it now.', ['provider' => self::providerName($service)])]);
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
            if ($user->is_banned) {
                return redirect()->to(locale_url(route('login')))
                    ->with('error', __('This account is suspended.'))
                    ->with('auth_banned', true);
            }

            $user->update([
                $service_id => $socialUser->getId(),
            ]);
        }

        Auth::login($user);

        // Build locale-aware redirect
        $locale = session('app_locale', $user->language ?: config('app.locale', 'en'));
        session()->put('app_locale', $locale);
        // The page to come back to in the language of this visit, as after a password sign-in (review CA-RV-03).
        \App\Http\Middleware\Authenticate::rememberedPageInLanguage(request(), (string) $locale);
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        $profilePath = '/profile';
        if ($locale !== $defaultLocale) {
            $profilePath = '/' . $locale . '/profile';
        }

        return redirect()->intended($profilePath);
    }
}
