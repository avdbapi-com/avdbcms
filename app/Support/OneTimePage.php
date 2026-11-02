<?php

namespace App\Support;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A page that can show one visitor's one-time message: data the previous request flashed into the session (a toast
 * such as "Your account is closed", a status line, form errors). Such a page belongs to that visitor. A CDN or the
 * browser must not keep it as the public copy of the address, or every visitor would get the message for minutes.
 *
 * The layout (components/layout.blade.php) calls markIfFlashed() while it renders, the only moment the flash data is
 * still in the session: StartSession ages it away when it saves the session, before an outer middleware such as
 * EdgeCacheVideoHtml looks at the response.
 *
 * The mark is enforced on the finished response, after every middleware: the first
 * mark of an application registers one listener on the HTTP kernel's RequestHandled event, which the kernel dispatches
 * once the whole middleware stack has returned, right before the response is sent. For a marked request it makes the
 * response private and uncacheable, whatever a controller (the watch page is public for guests) or a cache middleware
 * (EdgeCacheVideoHtml makes guest pages public) decided. So the page is never public, whatever
 * EdgeCacheVideoHtml does, and with any other caching middleware.
 */
final class OneTimePage
{
    public const ATTRIBUTE = 'avd.one_time_page';

    /** Container key: the RequestHandled listener is registered on this application instance. */
    private const LISTENER = 'avd.one_time_page.listener';

    /** Marks the request when the session holds data flashed for it (Laravel's `_flash.old` list); returns the mark. */
    public static function markIfFlashed(?Request $request = null): bool
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return self::isMarked($request);
        }

        try {
            $flashed = $request->session()->get('_flash.old', []);
        } catch (Throwable) {
            return self::isMarked($request);
        }

        if (is_array($flashed) && $flashed !== []) {
            self::mark($request);
        }

        return self::isMarked($request);
    }

    /** Marks the request's page as one visitor's (for a page that prints one-time data from elsewhere). */
    public static function mark(?Request $request = null): void
    {
        ($request ?? request())->attributes->set(self::ATTRIBUTE, true);
        self::enforceOnTheFinishedResponse();
    }

    public static function isMarked(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true;
    }

    /**
     * The headers of a page for one visitor: never stored by a CDN or a shared cache, nor kept by the browser as the
     * copy of the address (the same headers as an appearance preview, AppearanceDraft::markPrivate, without its
     * noindex: the address itself is a normal page).
     */
    public static function makePrivate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->remove('Expires');

        return $response;
    }

    private static function enforceOnTheFinishedResponse(): void
    {
        try {
            $app = app();

            if ($app->bound(self::LISTENER)) {
                return;
            }

            $app->instance(self::LISTENER, true);
            $app['events']->listen(RequestHandled::class, static function (RequestHandled $event): void {
                if (self::isMarked($event->request)) {
                    self::makePrivate($event->response);
                }
            });
        } catch (Throwable) {
            // Never break the page that is being rendered; the request attribute stays for the middleware.
        }
    }
}
