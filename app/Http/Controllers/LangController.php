<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LangController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $locale        = (string) $request->get('locale', '');
        $defaultLocale = config('site.default_locale', config('app.locale', 'en'));
        $languages     = config('languages', []);

        // Validate locale exists
        if (!array_key_exists($locale, $languages)) {
            return $this->noStoreRedirect(redirect()->to('/'));
        }

        // Store in session
        try {
            session()->put('app_locale', $locale);
        } catch (\Throwable) {
        }

        // Store in user record if authenticated
        if (Auth::check()) {
            Auth::user()->update(['language' => $locale]);
        }

        // Build locale-prefixed redirect URL from the previous page.
        $previousUrl = (string) $request->get('redirect', url()->previous());
        $previousPath = parse_url($previousUrl, PHP_URL_PATH) ?? '/';
        $previousQuery = parse_url($previousUrl, PHP_URL_QUERY);
        $previousFragment = parse_url($previousUrl, PHP_URL_FRAGMENT);

        // Strip any existing locale prefix from the previous path
        $segments = explode('/', ltrim($previousPath, '/'));
        if (!empty($segments[0]) && array_key_exists($segments[0], $languages)) {
            array_shift($segments);
        }
        $cleanPath = implode('/', $segments);
        $cleanPath = $cleanPath ? '/' . $cleanPath : '/';

        // Build new URL with locale prefix
        if ($locale === $defaultLocale) {
            $newUrl = $cleanPath;
        } else {
            $newUrl = '/' . $locale . ($cleanPath === '/' ? '' : $cleanPath);
        }

        if (is_string($previousQuery) && $previousQuery !== '') {
            $newUrl .= '?' . $previousQuery;
        }

        if (is_string($previousFragment) && $previousFragment !== '') {
            $newUrl .= '#' . $previousFragment;
        }

        return $this->noStoreRedirect(redirect($newUrl ?: '/'));
    }

    private function noStoreRedirect(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
