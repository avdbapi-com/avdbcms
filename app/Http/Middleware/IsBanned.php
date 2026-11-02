<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A banned account is signed out and refused, on the site (web group) and on the API (api group, after the optional
 * token sign-in): a page goes to the sign-in page with the message, a script or app gets 403 JSON. A session the
 * account still has is ended, so it cannot keep using the API in the background either.
 *
 * When a script call of an open page is what ended the session (a like, a poll, a history update), the next page the
 * browser opens goes to the sign-in page with the same message, once: the new session carries a note for it
 * (self::NOTICE). It showed the sign-in page without a word before.
 *
 * Only the page itself is sent to the sign-in page (self::opensAsPage()). A picture, a frame or a prefetch of the open
 * page is refused like a script call: a lazy avatar of the home page was redirected to the sign-in page, the browser
 * followed that redirect out of sight and the one-time message was used up there, so the next page again showed the
 * sign-in page without a word.
 */
class IsBanned
{
    /** The note a refused script call leaves in the new session for the next page. */
    public const NOTICE = 'auth_banned_notice';

    /**
     * Handle an incoming request.
     *
     * @param Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $page = $this->opensAsPage($request);

        if ($user instanceof User && $user->is_banned) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();

                $request->session()->invalidate();

                $request->session()->regenerateToken();

                // The page that sent this call learns why on its next page (a background GET and a picture keep the
                // session as it was, so the ban meets that next page itself: the same message).
                if (! $page) {
                    $request->session()->put(self::NOTICE, true);
                }
            }

            if (! $page) {
                return $this->refusal($request);
            }

            return $this->toSignIn();
        }

        // The first page after a refused call: the sign-in page with the reason (only a page takes the note).
        if ($page && $request->hasSession() && ! BackgroundRequest::is($request) && $request->session()->pull(self::NOTICE)) {
            return $this->toSignIn();
        }

        return $next($request);
    }

    /**
     * Whether the browser opens this answer as the page itself (a link, an address, a form): only a page shows the
     * member a message. Script and app calls, pictures, fonts, styles, frames and prefetches are not pages: a redirect
     * would be followed out of sight and use up the one-time message. The browser says what it fetches
     * (Sec-Fetch-Dest); without that header (older browsers, tools) a page is what asks for HTML.
     */
    private function opensAsPage(Request $request): bool
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return false;
        }

        $purpose = strtolower($request->headers->get('Sec-Purpose', '').' '.$request->headers->get('Purpose', ''));
        if (str_contains($purpose, 'prefetch')) {
            return false;
        }

        $destination = strtolower((string) $request->headers->get('Sec-Fetch-Dest', ''));
        if ($destination !== '') {
            return $destination === 'document';
        }

        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html');
    }

    /** A refused call that is not a page: 403, JSON for scripts and apps, the plain sentence for a picture or a frame. */
    private function refusal(Request $request)
    {
        $message = __('Your account was suspended');
        $response = $request->expectsJson() || $request->is('api/*')
            ? response()->json(['message' => $message], 403)
            : response($message, 403, ['Content-Type' => 'text/plain; charset=UTF-8']);

        // one visitor's answer: never kept by a CDN for the address (a picture's address is otherwise cacheable)
        return $response->header('Cache-Control', 'no-store, private');
    }

    /**
     * The sign-in page in the member's language: "This account is suspended. If you think this is a mistake, contact
     * us." (auth-19, auth-33; the page builds the contact link, the message stays plain text).
     */
    private function toSignIn()
    {
        return redirect()
            ->to(locale_url(route('login')))
            ->with('error', __('This account is suspended.'))
            ->with('auth_banned', true);
    }
}
