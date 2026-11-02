<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language this browser chose, from the moment the session is read (judge R1-050, the side of it on the pages).
 *
 * An address under a language (/fr/...) has its language before any middleware runs (public/index.php sets it). A
 * plain address (/profile, where the member pages' forms send their data) gets the language the visitor chose (the
 * session's app_locale, kept by the Language middleware and the language switch) only in the Language middleware, and
 * that runs late in the web group: what answers before it answered in the site's default language. A French member's
 * "This page timed out" page after Account settings stayed open too long (the page token check), "Too many tries" of
 * the limits of the routes, and the sign-in bounce were English. This sets the same language the Language middleware
 * sets (the session's, when the site offers it), right after the session starts, and nothing else: no redirect, no
 * header, no session write. The Language middleware still does all of that later, unchanged.
 */
final class UseChosenLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Authenticate::addressLanguage() === null) {
            $chosen = Authenticate::chosenLanguage($request);

            if ($chosen !== null && App::getLocale() !== $chosen) {
                App::setLocale($chosen);
                Carbon::setLocale($chosen);
            }
        }

        return $next($request);
    }
}
