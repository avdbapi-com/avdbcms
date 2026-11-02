<?php

namespace App\Http\Middleware;

use App\Support\VisitorChoices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A demo site's visitor choices (App\Support\VisitorChoices): the look this browser tries and whether it turned ads on,
 * applied to a page a visitor reads before its controller runs. Web group, before the Appearance draft (an admin's draft
 * link shows the draft). Both settings off: nothing is read and the request passes untouched. Whatever else the public
 * layout draws during the request (the 404 page of an /admin address, the answer to a form) gets the choices from the
 * layout (VisitorChoices::applyForLayout()). A cookie that holds something else than a valid choice is removed on the
 * way out.
 *
 * The choices change the config of this request only: once the page is made, the site's settings are put back, so a
 * process that serves several requests (a queue worker after the response, the test process) never carries one
 * visitor's look or ads into another request.
 */
class ApplyVisitorChoices
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! VisitorChoices::anyOn()) {
            return $next($request);
        }

        $site = config('site');
        VisitorChoices::applyFor($request);

        try {
            $response = $next($request);
        } finally {
            config(['site' => $site]);
        }

        // Found here or by the layout.
        foreach (VisitorChoices::cookiesToForget($request) as $name) {
            $response->headers->setCookie(VisitorChoices::forgetCookie($request, $name));
        }

        return $response;
    }
}
