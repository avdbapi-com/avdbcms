<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\Authenticate;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\PasswordCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController
{
    /**
     * The sign-in page. It remembers where the visitor came from (member design 6.12, auth-8): the page an entry link
     * names in ?redirect= (header, bottom bar, guest prompt, the watch page's sign-in links), else the same-site page
     * the browser says it came from (Referer). Only a path of this site is kept (ReturnPath), never a sign-in page, and a
     * page the auth middleware bounced to here (it stored its own address) is not replaced by the Referer.
     */
    public function show(Request $request): View
    {
        ReturnPath::rememberFrom($request);

        return view('auth.login');
    }

    public function login (LoginRequest $request) : RedirectResponse {

        $credentials = $request->validated();

        $remember = $request->boolean('remember');

        $user = User::where('username', $credentials['username'])->orWhere('email', $credentials['username'])->first();

        // One password check whether an account has this name or address or not (round 3 R3-021, App\Support\PasswordCheck):
        // the time of the answer no longer tells which addresses have an account.
        $passwordMatches = PasswordCheck::matches($user, $credentials['password']);

        if ($user && $passwordMatches) {

            // A suspended account is never signed in, not even for one request (auth-33): no session, no "last
            // sign-in" written for it. The message says so in the visitor's language, with a way to contact the site;
            // the typed name and "Keep me signed in" stay in the form.
            if ($user->is_banned) {
                return back()
                    ->with('error', __('This account is suspended.'))
                    ->with('auth_banned', true)
                    ->withInput($request->only('username', 'remember'));
            }

            PasswordCheck::rehashAfterSignIn($user, $credentials['password']);

            // The language of this visit (judge R2A-018): the one the sign-in page was in when the visitor chose it (an
            // address under /fr, which the Language middleware keeps in the session, or the language switch), else the
            // member's own language, else the site's. Read BEFORE Auth::login() moves the session to a new id.
            $languages = (array) config('languages', []);
            $chosen = Authenticate::addressLanguage() ?? Authenticate::chosenLanguage($request);
            $saved = is_string($user->language) && array_key_exists($user->language, $languages) ? $user->language : null;

            Auth::login($user, $remember);

            $locale = $chosen ?? $saved ?? site_default_locale();
            session()->put('app_locale', $locale);

            // The page to come back to is shown in the language of this visit: a guest sent from /fr/history who
            // picked German on the sign-in page lands on /de/history (review CA-RV-03).
            Authenticate::rememberedPageInLanguage($request, $locale);

            // The member's saved language (users.language: also the language of their e-mails) is never replaced by the
            // sign-in page's: a French member sent to the English sign-in page, or one who signs in on a page of another
            // language, keeps French. It changes only where the member chooses it (the language switch, Account settings).

            // Build a locale-aware redirect URL
            $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
            $profilePath = '/profile';
            if ($locale !== $defaultLocale) {
                $profilePath = '/' . $locale . '/profile';
            }

            return redirect()->intended($profilePath);
        }

        // One neutral sentence for a wrong name and a wrong password (no account enumeration); the view adds the
        // "Reset your password" link. The name and "Keep me signed in" stay as typed (auth-32).
        return back()
            ->with('error', __('The username or password is wrong.'))
            ->with('auth_failed', true)
            ->withInput($request->only('username', 'remember'));
    }

    /**
     * Sign out (auth-22): a POST with the page's token (the account panel's form). A GET (an old page's "Logout" link,
     * from before 1.2.3 or a page kept in a cache, or a link or an image on another site) changes nothing: a signed-in
     * member sees the sign-out button, anybody else goes home.
     *
     * @param Request  $request
     * @return View|RedirectResponse
     */
    public function logout (Request $request): View|RedirectResponse {

        if ($request->isMethodSafe()) {
            return Auth::check() ? view('auth.logout') : redirect()->to(locale_url('/'));
        }

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
