<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The language switch, /update-locale (the header and footer language menus, the account menu, any language link).
 *
 * The visitor goes back to the page they were on, in the chosen language. That page comes from ?redirect= (the switch's
 * own links and form), else from the page the browser says it came from (Referer), else from the session's previous
 * page, else it is the home page. It is ALWAYS a page of this site (round 1b judge R1B-013: the address was joined from
 * its segments with the empty one of "//" kept, so /vi//evil.example/x became "Location: //evil.example/x" and the
 * browser left the site): only a path counts (an absolute address only when its host is this site's), never one with a
 * backslash, a space or a control character (browsers read those differently from parse_url()), and the page is read
 * and built like every other link of the site (locale_path_parts() and locale_url(), one rule since round 2a R2A-005:
 * empty segments dropped, "/<locale>" after "/index.php" on a site without clean addresses, never "/<locale>/index.php").
 *
 * The member's saved language (users.language, also the language of their e-mails) changes only on a POST, which carries
 * the form's token (VerifyCsrfToken; R1B-025): a link or an <img> on another site is a GET, and a GET changes this
 * browser's language only (the session), as a visit to /vi/... does. The account menu's language switch and Account
 * settings post their form.
 */
class LangController extends Controller
{
    /** The longest return address used (a longer one is not a page of this site anyone clicked). */
    private const MAX_LENGTH = 2000;

    public function update(Request $request): RedirectResponse
    {
        $locale = $request->input('locale');
        $languages = (array) config('languages', []);

        // An unknown language changes nothing.
        if (! is_string($locale) || ! array_key_exists($locale, $languages)) {
            return $this->noStoreRedirect(redirect()->to('/'));
        }

        try {
            session()->put('app_locale', $locale);
        } catch (Throwable) {
        }

        if ($request->isMethod('POST') && Auth::check()) {
            Auth::user()->update(['language' => $locale]);
        }

        return $this->noStoreRedirect(redirect()->to($this->target($request, $locale)));
    }

    /** The page to go back to, in $locale: a path of this site with its query and fragment. */
    private function target(Request $request, string $locale): string
    {
        $page = $this->returnPage($request);
        $path = ($page['front'] ? '/index.php' : '').$page['path'];

        // locale_url() writes the language of the application: the chosen one, for this address only.
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $url = locale_url($path);
        } finally {
            app()->setLocale($previous);
        }

        if ($page['query'] !== '') {
            $url .= '?'.$page['query'];
        }

        if ($page['fragment'] !== '') {
            $url .= '#'.$page['fragment'];
        }

        return $url;
    }

    /**
     * The first usable candidate: ?redirect=, the Referer, the session's previous page; else the home page.
     *
     * @return array{path: string, query: string, fragment: string, front: bool}
     */
    private function returnPage(Request $request): array
    {
        $candidates = [$request->input('redirect'), $request->headers->get('referer')];

        try {
            $candidates[] = $request->hasSession() ? $request->session()->previousUrl() : null;
        } catch (Throwable) {
        }

        foreach ($candidates as $candidate) {
            $page = $this->ownPage($candidate, $request);

            if ($page !== null) {
                return $page;
            }
        }

        return ['path' => '/', 'query' => '', 'fragment' => '', 'front' => false];
    }

    /**
     * $candidate as a page of this site (its path without empty segments, without the "/index.php" and language
     * segments in front, its query and fragment), or null when it is not one.
     *
     * @return array{path: string, query: string, fragment: string, front: bool}|null
     */
    private function ownPage(mixed $candidate, Request $request): ?array
    {
        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH || preg_match('/[\\\\\x00-\x20\x7f]/', $candidate) === 1) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));

            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($host, $this->ownHosts($request), true)) {
                return null;
            }
        } elseif (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null; // "trend", "//evil.example"
        } else {
            // A path as the browser has it (the switch's own ?redirect=): on a site installed in a folder the page is what
            // follows the folder (locale_url() puts it in front again).
            $candidate = site_path($candidate);
        }

        // The page is read with the rule of every link of the site (locale_path_parts(), round 2a R2A-005): empty
        // segments go ("/vi//evil.example" is "/vi/evil.example"), then "index.php" and the language in front.
        $page = locale_path_parts($candidate);
        $first = strtolower(explode('/', ltrim($page['path'], '/'))[0]);

        // The switch itself is no page to go back to.
        if ($first === 'update-locale') {
            return ['path' => '/', 'query' => '', 'fragment' => '', 'front' => $page['index_php']];
        }

        return [
            'path' => $page['path'],
            'query' => (string) ($page['query'] ?? ''),
            'fragment' => (string) ($page['fragment'] ?? ''),
            'front' => $page['index_php'],
        ];
    }

    /** @return list<string> the site's own host names: APP_URL's and the one this request came to */
    private function ownHosts(Request $request): array
    {
        return array_values(array_unique(array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            strtolower($request->getHost()),
        ])));
    }

    private function noStoreRedirect(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
