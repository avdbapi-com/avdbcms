<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The picture routes of the site (a channel's avatar and banner, a video's thumbnails: routes/web.php) read the
 * visitor's session, so their owner still sees an avatar or a thumbnail the public cannot, but they never write it and
 * never send a session cookie (watch-26, watch-28):
 *  - a page can show 20 avatars: each one used to store the session again, the picture's address as "the page you
 *    came from" included, so a refused form went "back" to an SVG avatar; requests running side by side wrote their
 *    copies over each other (a one-time message could be lost);
 *  - without a session cookie the answer is the same for everybody who may see it, and the browser and a CDN can keep
 *    it.
 *
 * The routes drop StartSession (and the session-bound middleware of the web group: REPLACES) and run this one in its
 * place. It implements the framework's AuthenticatesRequests marker, so the router's middleware priority
 * (Illuminate\Foundation\Http\Kernel::$middlewarePriority) runs it where StartSession ran: after the cookies are
 * decrypted and BEFORE the route bindings and the web group's middleware that ask for the signed-in account (IsBanned,
 * Language, CheckMaintenanceMode, ViewOnlyDemo). A route middleware is otherwise appended after the whole group, and
 * those would see a guest: an admin got the maintenance page for every picture, and a member with a "remember me"
 * cookie was signed in again (the Login event, a users row write) on every picture.
 *
 * What it does, in StartSession's place:
 *  - the session the browser sent is read from its store (ReadOnlySessionHandler); nothing is written, ended or cleaned
 *    up through it, the picture is never stored as the previous page, and no session cookie is sent;
 *  - one exception: when the session had ended and the request signed the account in again from its "remember me"
 *    cookie, that new session is stored once and its cookie sent, as a page view would do it, so the next requests use
 *    it instead of signing in again every time (BackgroundRequest does the same for the site's background requests).
 *    That one answer is private (it carries the new session): never kept by a CDN or another shared cache.
 *
 * Every other route keeps the web group's session exactly as before.
 *
 * 1.2.3 round 3: the video's own file runs it too (routes/web.php video.file; its older copy of the session replaced the
 * sign-in page's question and token, SB-X1), with the option KEEP_ALIVE (check SB-V1-01). During a long film the page's
 * <video> may be the only thing that asks the site for anything, and in 1.2.2 each of its requests stored the session
 * again, which kept a member signed in. With the option the session the browser sent is kept alive the way the page's
 * background requests keep it (BackgroundSessionHandler): still stored and not ended, its time moves on without a byte
 * of it written again, and the answer renews the same cookie (an answer that is never kept by a shared cache). An ended
 * session is never brought back, a visitor without a stored session gets no cookie, a "remember me" sign-in on an ended
 * session is stored once, and nothing older is ever written over what another request stored meanwhile.
 */
final class ReadOnlySession implements AuthenticatesRequests
{
    /** The web group's session-bound middleware a picture route runs without (routes/web.php ->withoutMiddleware()). */
    public const REPLACES = [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\ApplyAppearanceDraft::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ];

    /**
     * The option of a route whose requests keep the visitor's session alive:
     * ->middleware(ReadOnlySession::class.':'.ReadOnlySession::KEEP_ALIVE) (routes/web.php: the video's own file).
     */
    public const KEEP_ALIVE = 'keep-alive';

    public function __construct(private readonly SessionManager $manager)
    {
    }

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $store = $this->store();

        if ($store === null || $request->hasSession()) {
            return $next($request);
        }

        $handler = $store->getHandler();

        // The cookie driver keeps the session in the request's cookies.
        if ($handler instanceof CookieSessionHandler) {
            $handler->setRequest($request);
        }

        if (in_array(self::KEEP_ALIVE, $options, true)) {
            return $this->keepingAlive($request, $next, $store, $handler);
        }

        $readOnly = new ReadOnlySessionHandler($handler);
        $store->setHandler($readOnly);

        try {
            $id = $request->cookies->get($store->getName());
            $store->setId(is_string($id) ? $id : null);

            try {
                $store->start();
            } catch (Throwable) {
                // An unreadable store (a full disk, a lost database): the request goes on as a guest's.
            }

            $request->setLaravelSession($store);

            $response = $next($request);

            if (! $readOnly->found() && $this->signedInAgainFromRememberCookie()) {
                $this->storeNewSignIn($store, $readOnly, $response);
            }

            return $response;
        } finally {
            $store->setHandler($handler);
        }
    }

    /**
     * KEEP_ALIVE: the session is read as above, through the background requests' handler (BackgroundSessionHandler),
     * and "saved" through it once the answer is ready. That keeps the session the browser sent alive without writing
     * what is in it (files: their time, database: the row's last activity, each at most once a minute; Redis, Memcached,
     * the other cache stores and the cookie driver each in their own way), or stores a "remember me" sign-in on an ended
     * session once (without the video as the previous page). The answer then renews the same cookie, or gives the new
     * sign-in's, and is never kept by a shared cache (it carries the visitor's live session). Otherwise the browser
     * keeps the cookie it has: an ended or missing session, a visitor without one, a store that did not answer.
     */
    private function keepingAlive(Request $request, Closure $next, Store $store, SessionHandlerInterface $handler): Response
    {
        $background = new BackgroundSessionHandler($handler, $store, $request);
        $store->setHandler($background);

        try {
            $id = $request->cookies->get($store->getName());
            $store->setId(is_string($id) ? $id : null);

            try {
                $store->start();
            } catch (Throwable) {
                // An unreadable store (a full disk, a lost database): the request goes on as a guest's.
            }

            $request->setLaravelSession($store);

            $response = $next($request);

            try {
                $store->save();
            } catch (Throwable) {
                return $response; // nothing renewed or stored: the browser keeps the cookie it has
            }

            if ($background->keepsCookie()) {
                $response->headers->setCookie($this->sessionCookie($store));
                EdgeCacheVideoHtml::neverSharedWithCookies($response);
            }

            return $response;
        } finally {
            $store->setHandler($handler);
        }
    }

    /** Whether the web guard signed the account in during this request from its "remember me" cookie. */
    private function signedInAgainFromRememberCookie(): bool
    {
        try {
            $guard = Auth::guard();
        } catch (Throwable) {
            return false;
        }

        return $guard instanceof SessionGuard && $guard->viaRemember() && $guard->hasUser();
    }

    /**
     * The session the "remember me" sign-in made (the browser's own had ended) is stored once, as a page view stores it,
     * but without the picture as the previous page, and the browser gets its cookie.
     *
     * That answer carries the member's new live session, so it is this browser's alone (R1-087): the picture's
     * "public, immutable" headers would let a shared cache that keeps Set-Cookie hand the session to everybody who asks
     * for the same picture. It is marked private and never stored; the next picture, without a cookie, is public again.
     */
    private function storeNewSignIn(Store $store, ReadOnlySessionHandler $readOnly, Response $response): void
    {
        $readOnly->allowWrites();

        try {
            $store->forget('_previous');
            $store->save();
        } catch (Throwable) {
            return; // nothing stored: the browser keeps the cookie it has
        } finally {
            $readOnly->denyWrites();
        }

        $response->headers->setCookie($this->sessionCookie($store));

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('Expires');
        foreach (['CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control'] as $header) {
            if ($response->headers->has($header)) {
                $response->headers->set($header, 'no-store');
            }
        }
    }

    /** The session's cookie as StartSession gives it: the same name, path, domain, lifetime and flags. */
    private function sessionCookie(Store $store): Cookie
    {
        $config = $this->manager->getSessionConfig();
        $minutes = (int) ($config['lifetime'] ?? 120);

        return new Cookie(
            $store->getName(),
            $store->getId(),
            ($config['expire_on_close'] ?? false) ? 0 : now()->addMinutes($minutes),
            $config['path'] ?? '/',
            $config['domain'] ?? null,
            $config['secure'] ?? null,
            $config['http_only'] ?? true,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false,
        );
    }

    private function store(): ?Store
    {
        if (config('session.driver') === null) {
            return null;
        }

        try {
            $store = $this->manager->driver();
        } catch (Throwable) {
            return null;
        }

        return $store instanceof Store ? $store : null;
    }
}
