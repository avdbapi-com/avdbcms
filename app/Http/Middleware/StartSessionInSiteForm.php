<?php

namespace App\Http\Middleware;

use App\Support\CleanUrls;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * Laravel's StartSession, with one difference: the page it remembers for back() (the session's "previous URL") is kept
 * in a form that reaches the site. public/index.php takes /index.php off the address before Laravel sees the request,
 * so on a server without rewrite rules (every page only as /index.php/...: the fleet's nodes 2, 4 and 7) a refused form
 * sent without a Referer header (a privacy extension, a strict Referrer-Policy) went back to the clean address: the web
 * server's 404 page (round 1b review BWR-07). Bound in place of Laravel's class by EnvironmentServiceProvider, so the
 * web group of the HTTP kernel stays as it is.
 */
class StartSessionInSiteForm extends StartSession
{
    /**
     * A page the session remembered before the site's links needed /index.php is given the site's form before anything
     * goes back to it (round 2 R1BR-03): every session that was open while a site updated from 1.2.2 (which remembered
     * the address as the request saw it), or one of a site whose rewrite rules went away after its check said yes. A
     * form refused without a Referer header went back to the clean address: the web server's 404 page.
     *
     * @param  \Illuminate\Contracts\Session\Session  $session
     * @return \Illuminate\Contracts\Session\Session
     */
    protected function startSession(Request $request, $session)
    {
        $session = parent::startSession($request, $session);
        $previous = $session->previousUrl();

        if (is_string($previous) && $previous !== '') {
            $inSiteForm = CleanUrls::requestForm($previous);

            if ($inSiteForm !== $previous) {
                $session->setPreviousUrl($inSiteForm);
            }
        }

        return $session;
    }

    /**
     * @param  \Illuminate\Contracts\Session\Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session)
    {
        parent::storeCurrentUrl($request, $session);

        $current = $request->fullUrl();

        if ($session->previousUrl() === $current) {
            $session->setPreviousUrl(CleanUrls::requestForm($current));
        }
    }
}
