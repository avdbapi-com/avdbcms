<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [
        'stripe-webhook',
        'paypal-webhook',
    ];

    /**
     * Use the trust-aware scheme detection so the middleware produces
     * `__Secure-` cookies on HTTPS behind a reverse proxy.
     */
    protected function addCookieToResponse($request, $response)
    {
        $response = parent::addCookieToResponse($request, $response);

        if (method_exists($response, 'headers')) {
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() !== 'XSRF-TOKEN') {
                    continue;
                }

                if (! $cookie->isSecure() && $request->secure()) {
                    $response->headers->setCookie(
                        $cookie->withSecure(true)->withSameSite('lax')
                    );
                }
            }
        }

        return $response;
    }
}
