<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\ReturnPath;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * The request attribute that carries the page to come back to after signing in, with the language of its address
     * (redirectTo()); App\Exceptions\Handler::unauthenticated() stores it.
     */
    public const INTENDED = 'avd.auth.intended';

    /**
     * Where a guest who opens a member page is sent: the sign-in page in the language of the page they opened, and that
     * page, with its language, is where they come back to after signing in (judge R2A-018: /fr/history sent to the
     * English /login, and back to the English /history).
     *
     * This middleware runs before the Language middleware (the router's middleware priority puts "auth" ahead of the
     * web group's middleware) and public/index.php takes the /fr off the address before Laravel sees it. So the language
     * is read where the Language middleware reads it: the address's own (DETECTED_LOCALE), else the one this browser
     * chose (the session's app_locale), else the site's language. Laravel's redirect()->guest() remembers the address as
     * the request saw it, without the /fr: the address with it goes in a request attribute, and the exception handler
     * stores it after redirect()->guest() (App\Exceptions\Handler::unauthenticated()). An address without a language of
     * its own (/history, the admin's pages) is remembered as Laravel remembers it: after the sign-in the Language
     * middleware sends it to the language of the visit (the session's), and the admin's pages never take one. A visitor
     * who picks another language on the sign-in page comes back to the page in that one (rememberedPageInLanguage()).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if ($request->expectsJson()) {
            return null;
        }

        $addressLanguage = self::addressLanguage();

        if ($addressLanguage !== null && $request->isMethod('GET') && $request->route() !== null) {
            $query = $request->getQueryString();
            $page = $request->getPathInfo().($query !== null && $query !== '' ? '?'.$query : '');

            $request->attributes->set(self::INTENDED, url(self::inLanguage($addressLanguage, static fn (): string => locale_url($page))));
        }

        return self::inLanguage($addressLanguage ?? self::chosenLanguage($request) ?? site_default_locale(), static fn (): string => locale_url(route('login')));
    }

    /** The language of the address the visitor opened (/fr/...: public/index.php keeps it in DETECTED_LOCALE), or null. */
    public static function addressLanguage(): ?string
    {
        $detected = $_SERVER['DETECTED_LOCALE'] ?? null;

        return is_string($detected) && array_key_exists($detected, (array) config('languages', [])) ? $detected : null;
    }

    /**
     * The language this browser chose (a page under /fr, the language switch: the session's app_locale), when the site
     * offers it, else null.
     */
    public static function chosenLanguage(Request $request): ?string
    {
        try {
            $chosen = $request->hasSession() ? $request->session()->get('app_locale') : null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($chosen) && array_key_exists($chosen, (array) config('languages', [])) ? $chosen : null;
    }

    /**
     * The page to come back to after signing in, in the language of the visit (review CA-RV-03). A guest sent from
     * /fr/history to /fr/login who then picks German there (the language switch, or a /de address) and signs in lands on
     * /de/history, not on the French page they left; one who picks the site's own language lands on /history. The
     * sign-in controllers call it with the visit's language right before redirect()->intended().
     *
     * Only a remembered page of this site with a language of its own is rewritten (ReturnPath::safePath(): never
     * another host). A plain one (/history, the admin's pages) stays as it is: the Language middleware sends a plain
     * page to the visit's language, and the admin's pages never take one.
     */
    public static function rememberedPageInLanguage(Request $request, string $locale): void
    {
        if (! $request->hasSession() || ! array_key_exists($locale, (array) config('languages', []))) {
            return;
        }

        $intended = $request->session()->get('url.intended');
        $page = is_string($intended) ? ReturnPath::safePath($intended, $request) : null;
        $pageLanguage = $page !== null ? self::pathLanguage($page) : null;

        if ($page === null || $pageLanguage === null || $pageLanguage === $locale) {
            return;
        }

        $request->session()->put('url.intended', url(self::inLanguage($locale, static fn (): string => locale_url($page))));
    }

    /** The language an address of this site starts with ("/fr/...", "/index.php/fr/..."), or null. */
    private static function pathLanguage(string $page): ?string
    {
        $languages = (array) config('languages', []);
        $segments = array_values(array_filter(
            explode('/', (string) parse_url($page, PHP_URL_PATH)),
            static fn (string $segment): bool => $segment !== ''
        ));

        foreach (array_slice($segments, 0, 2) as $segment) {
            if (array_key_exists($segment, $languages)) {
                return $segment;
            }

            if (strtolower($segment) !== 'index.php') {
                return null;
            }
        }

        return null;
    }

    /** locale_url() writes the application's language: $build runs with $locale, and the language before is put back. */
    private static function inLanguage(string $locale, callable $build): string
    {
        $previous = app()->getLocale();

        if ($previous === $locale) {
            return $build();
        }

        app()->setLocale($locale);

        try {
            return $build();
        } finally {
            app()->setLocale($previous);
        }
    }
}
