<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Requests a page sends by itself: a part of the page filled in after it appears, a progress poll, a search as you
 * type, the site's lists and comments. Their scripts mark them with the header X-Background-Request: 1, set by one
 * helper per script bundle (the admin: resources/admin/lib/dom.ts backgroundGet(); the site: jsonFetch() of
 * resources/js/functions/api.ts; the admin's own page scripts print self::HEADER).
 *
 * A marked GET or HEAD reads the session like any other request (the account stays signed in, the page sees its
 * messages), but it never changes the session:
 *  - it does not use up a one-time message: Laravel moves "flash" data along on every request that saves the session,
 *    so a background request that arrived between "Saved" (or a refusal) and the page that shows it used it up;
 *  - it never writes back a session it read earlier: a request that ran next to another one wrote its older copy over
 *    what the other one had just stored (a message, even a sign-in or a sign-out);
 *  - it is not kept as "the page you came from", and it never gives the browser a new or changed session cookie.
 *
 * Two things it still does, as every request did before (BackgroundSessionHandler):
 *  - it keeps the session alive: a page left open with only its background requests (an import's progress, a long
 *    list) must not sign the account out after the session lifetime. For the session the browser sent, still stored
 *    and not ended, the time it is kept moves on without changing what is in it (files, database, Redis, Memcached,
 *    the other cache stores and the cookie driver each in their own way), and the response renews the same cookie. An
 *    ended session is never brought back;
 *  - when the session had ended and the request signed the account in again from its "remember me" cookie, that new
 *    session is stored once (without the background address as the previous page), so the next requests use it
 *    instead of signing in again every time.
 *
 * It sits right before StartSession in the web group, and first in the api group (Sanctum's stateful middleware starts
 * the session there for the site's own scripts): for a marked request the session store reads through that handler,
 * and afterwards the store is put back as it was. Every other request (pages, forms, JSON writes, a POST with the
 * header, any request without it) runs exactly as before. A marked request can only do less than an unmarked one.
 *
 * 1.2.3 round 2 (R2A-008): the Upload18 checks a page sends by itself from a script that cannot carry the header (the
 * upload flow is hands-off: the draft page's completion check every 10 s, the upload window's progress check and its
 * settings) are background requests by their route (ROUTES): a check that reached the site between "Save draft" and
 * the page it leads to used up "Draft saved.". They write nothing to the session (their answers come from the
 * database and Upload18), so they keep working as before: signed in, the session kept alive.
 *
 * 1.2.3 round 3 (review SB-X1): one POST is a background request too, by its route (POST_ROUTES): the view count the
 * player sends by itself when a video starts. It changes the site (the count, a member's history), never the session,
 * and its copy of the session, written back when it ended, could replace what the sign-in page had stored meanwhile
 * (the session the sign-in form was printed with, after the old one had ended). Its token check is the same as before.
 */
final class BackgroundRequest
{
    public const HEADER = 'X-Background-Request';

    /**
     * The GET routes that are always a page's own background request, header or not (names of routes/api.php).
     *
     * @var list<string>
     */
    public const ROUTES = [
        'videos.create-status',    // the draft page's completion check (users/videos/partials/create-status-poll.blade.php)
        'videos.upload18.status',  // the upload window's progress check (VideoUpload.tsx)
        'videos.upload18.config',  // the upload window's settings, read when an upload starts (VideoUpload.tsx)
    ];

    /**
     * The POST routes that are always a page's own background request (names of routes/api.php): they write nothing to
     * the session, so their copy of it is never written back.
     *
     * @var list<string>
     */
    public const POST_ROUTES = [
        'videos.view',             // the view count the player sends when a video starts (Player.tsx)
    ];

    /**
     * Whether this request is a background GET or HEAD (the method the client really sent: a POST is only on one of
     * POST_ROUTES, override or not): marked with the header, or sent to one of ROUTES (once the router has matched it).
     */
    public static function is(Request $request): bool
    {
        $method = strtoupper($request->getRealMethod());

        if ($method === 'POST') {
            $route = $request->route();

            return $route instanceof Route && in_array($route->getName(), self::POST_ROUTES, true);
        }

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->headers->get(self::HEADER) === '1') {
            return true;
        }

        $route = $request->route();

        return $route instanceof Route && in_array($route->getName(), self::ROUTES, true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $store = self::is($request) ? self::store() : null;

        if ($store === null) {
            return $next($request);
        }

        $handler = $store->getHandler();
        // What this process already holds in memory: nothing on a normal request (a new store); a long-running worker
        // or the test client reuses the store, and gets it back as it was.
        $before = $store->all();

        // The cookie driver keeps the session in the request's cookies and is handed the request only through the store.
        if ($handler instanceof CookieSessionHandler) {
            $handler->setRequest($request);
        }

        $background = new BackgroundSessionHandler($handler, $store, $request);
        $store->setHandler($background);

        try {
            $response = $next($request);
        } finally {
            $store->setHandler($handler);
            $store->flush();
            $store->put($before);
        }

        // Otherwise the browser keeps the session cookie it has: the request may have started a session (a new id) that
        // is never stored, or have been given another id.
        if (! $background->keepsCookie()) {
            $name = $store->getName();
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() === $name) {
                    $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
                }
            }
        }

        return $response;
    }

    /** The session store StartSession is about to start (the same object: the manager keeps one per driver), or null. */
    private static function store(): ?Store
    {
        try {
            $store = app('session')->driver();
        } catch (Throwable) {
            return null; // no session configured: nothing to keep apart
        }

        return $store instanceof Store ? $store : null;
    }
}
