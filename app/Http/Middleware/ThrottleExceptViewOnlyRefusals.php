<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * The route limits ("throttle:3,10", "throttle:comments" ...), with one exception: a request the view-only demo guard
 * answers itself (ViewOnlyDemo::answersItself(): a view-only account's closed page or refused change) is left to the
 * guard and not counted. None of the site's code runs for it, so there is nothing to limit, and the account keeps
 * getting the guard's answer, its "This is a demo" window, instead of the site's "Too many tries" page from the fourth
 * click on ("Download your data" allows 3 in 10 minutes). Every other request is limited exactly as before.
 *
 * Why the guard cannot simply answer first: it is the last member of the web and api middleware groups (it needs the
 * session, the account and the language), and Laravel's middleware priority runs a route's limits before those groups'
 * later members (ahead of route model binding).
 *
 * The "throttle" alias of app/Http/Kernel.php names this class (1.2.3). Being Laravel's ThrottleRequests underneath, it
 * keeps that class's place in the middleware priority and every way a route names a limit.
 */
class ThrottleExceptViewOnlyRefusals extends ThrottleRequests
{
    /**
     * Laravel's own handle() tells a named limiter ("throttle:comments") from a number of attempts by how many
     * arguments it was given, so they are passed on exactly as they came.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // Limits switched off by Laravel's class (a test's withoutMiddleware(ThrottleRequests::class)) stay off here.
        if (app()->bound(ThrottleRequests::class) && ! app(ThrottleRequests::class) instanceof ThrottleRequests) {
            return $next($request);
        }

        if (ViewOnlyDemo::answersItself($request)) {
            return $next($request);
        }

        return parent::handle(...func_get_args());
    }
}
