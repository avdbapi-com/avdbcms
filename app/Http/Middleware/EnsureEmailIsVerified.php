<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as Middleware;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "verified" check (subscribe, like / dislike, report, comments, uploads, new playlists, the Premium checkout) on
 * sites with "E-mail confirmation required".
 *
 * Laravel's own answered a script with its untranslated "... address is not verified." sentence in every language, and
 * said nothing about how to get a new link. Here a script or app gets 403 with a sentence in the member's language that
 * says how ("open the link we sent you, or ask for a new one in Account settings", where "Send the link again" is),
 * plus the address of the "Confirm your e-mail" page (verify_url); a page goes to that page in the member's language
 * (Laravel's redirect dropped the /fr of the address). Everything else is Laravel's check, unchanged.
 */
class EnsureEmailIsVerified extends Middleware
{
    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     */
    public function handle($request, Closure $next, $redirectToRoute = null): Response
    {
        $user = $request->user();

        if ($user && (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail())) {
            return $next($request);
        }

        $page = locale_url(URL::route($redirectToRoute ?: 'verification.notice'));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Confirm your e-mail first. Open the link we sent you, or ask for a new one in Account settings.'),
                'verify_url' => $page,
            ], 403);
        }

        return redirect()->guest($page);
    }
}
