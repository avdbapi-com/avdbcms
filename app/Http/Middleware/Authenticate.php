<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            // Store the locale-prefixed intended URL so that after login
            // redirect()->intended() returns the user to the correct locale path
            $locale = session('app_locale', app()->getLocale());
            $defaultLocale = config('site.default_locale', config('app.locale', 'en'));

            if ($locale !== $defaultLocale) {
                $path = $request->getPathInfo();
                // Only prefix if not already prefixed
                if (!str_starts_with($path, '/' . $locale . '/') && $path !== '/' . $locale) {
                    $localePath = '/' . $locale . ($path === '/' ? '' : $path);
                    $qs = $request->getQueryString();
                    session()->put('url.intended', url($localePath . ($qs ? '?' . $qs : '')));
                }
            }

            return route('login');
        }
    }
}
