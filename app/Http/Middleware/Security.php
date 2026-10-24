<?php

namespace App\Http\Middleware;

use App\Support\Environment;
use Closure;
use Illuminate\Http\Request;

class Security
{
    /**
     * Set with the https redirect, as a Secure cookie: a browser keeps it only from an answer it got over https. A
     * request that brings it back and still looks like plain http came over https through a proxy that hides the scheme
     * (one that ended TLS, then passed the request on with no header at all): a second redirect would only loop, so it
     * gets the page. Laravel encrypts it like its other cookies, so nobody else can set it.
     */
    private const HTTPS_HOP_COOKIE = 'avdbcms_https_hop';

    protected array $exceptIframe = [
        'embed/*',
        'video/*/embed',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($canonicalUrl = $this->canonicalUrlFor($request)) {
            return redirect()->to($canonicalUrl, 301);
        }

        // Force HTTPS if admin setting is on — honour trusted proxy headers
        // so reverse-proxy setups (Cloudflare, panels, LiteSpeed, FrankenPHP)
        // do not 301-loop when TLS terminates upstream.
        if (
            config('site.force_https', false)
            && app()->environment('production')
            && ! $request->is('healthz', 'up', '_internal/*')
            && $this->visitorUsedHttp($request)
        ) {
            return redirect()->secure($request->getRequestUri(), 301)
                ->withCookie(cookie(self::HTTPS_HOP_COOKIE, '1', 60 * 24, '/', null, true, true, false, 'lax'));
        }

        // Check global IP blacklist
        $blacklist = config('site.global_ip_blacklist', '');
        if (!empty($blacklist)) {
            $blockedIps = array_filter(array_map('trim', preg_split('/[\r\n]+/', $blacklist)));
            if (in_array($request->ip(), $blockedIps)) {
                abort(403, __('Access denied.'));
            }
        }

        $response = $next($request);

        // XSS Protection header — controlled by admin
        if (config('site.xss_protection', true)) {
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }

        // X-Content-Type-Options nosniff — controlled by admin
        if (config('site.nosniff_header', true)) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        $response->headers->set('Referrer-Policy', 'no-referrer-when-downgrade');

        // HSTS — use admin-configured max-age
        if (config('site.hsts_enabled', false)) {
            $maxAge = config('site.hsts_max_age', 31536000);
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        // CSP header if admin enabled it
        if (config('site.csp_enabled', false)) {
            $csp = config('site.csp_directives', '') ?: "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';";
            $csp = $this->isIframeException($request) ? $this->cspForIframeException($csp) : $csp;
            $response->headers->set('Content-Security-Policy', $csp);
        }

        // X-Frame-Options — controlled by admin toggle
        if (config('site.frame_options_header', true) && !$this->isIframeException($request)) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }

    /**
     * "Always use the secure address (https)" sends an http visit to https only where that cannot loop: the visitor's
     * own scheme decides (Environment::visitorScheme(): straight from the visitor, Cloudflare's CF-Visitor, the
     * X-Forwarded-Proto of a proxy the owner listed by its address). Where nothing says it (a proxy in front that sends
     * no header, such as TLS ended upstream without X-Forwarded-Proto), a site whose own address is https does nothing,
     * and one whose address is http redirects as before 1.2.2. A browser that brings back the cookie of an earlier
     * redirect (HTTPS_HOP_COOKIE) is never sent again.
     */
    private function visitorUsedHttp(Request $request): bool
    {
        // This browser followed the redirect over https before and still looks like http here: never a second one.
        if ($request->cookies->get(self::HTTPS_HOP_COOKIE) === '1') {
            return false;
        }

        $scheme = Environment::visitorScheme($request);

        return $scheme !== null ? $scheme === 'http' : ! Environment::isSecureRequest();
    }

    private function isIframeException(Request $request): bool
    {
        foreach ($this->exceptIframe as $except) {
            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }

    private function cspForIframeException(string $csp): string
    {
        $directives = array_values(array_filter(array_map('trim', explode(';', $csp))));
        $directives = array_values(array_filter(
            $directives,
            fn (string $directive) => !str_starts_with(strtolower($directive), 'frame-ancestors')
        ));

        if ($frameAncestors = $this->frameAncestorsDirective()) {
            $directives[] = $frameAncestors;
        }

        return implode('; ', $directives) . ';';
    }

    private function frameAncestorsDirective(): ?string
    {
        $domains = preg_split('/[\r\n,]+/', (string) config('site.embed_allowed_domains', '')) ?: [];
        $sources = [];

        foreach ($domains as $domain) {
            $source = $this->frameAncestorSource(trim($domain));

            if ($source !== null) {
                $sources[$source] = $source;
            }
        }

        if ($sources === []) {
            return null;
        }

        return 'frame-ancestors ' . implode(' ', array_values($sources));
    }

    private function frameAncestorSource(string $source): ?string
    {
        if ($source === '') {
            return null;
        }

        $lower = strtolower($source);

        if (in_array($lower, ['*', "'self'", 'self'], true)) {
            return $lower === 'self' ? "'self'" : $lower;
        }

        if (preg_match('#^https?://[A-Za-z0-9*.-]+(?::[0-9]{1,5})?$#', $source)) {
            return rtrim($source, '/');
        }

        if (preg_match('/^[A-Za-z0-9*.-]+(?::[0-9]{1,5})?$/', $source)) {
            return 'https://' . $source;
        }

        return null;
    }

    private function canonicalUrlFor(Request $request): ?string
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $canonicalHost = parse_url($appUrl, PHP_URL_HOST);
        $canonicalScheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $currentHost = strtolower($request->getHost());

        if (!$canonicalHost || in_array($currentHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        $canonicalHost = strtolower($canonicalHost);

        // Proxy-aware scheme: trust edge TLS termination (Cloudflare/panels) even when
        // APP_URL was captured as http during install (Flexible SSL). Only ever canonicalise
        // toward the MORE secure scheme — never redirect https->http, which loops forever
        // behind "Always Use HTTPS" and downgrades otherwise-secure requests.
        $effectiveScheme = Environment::isSecureRequest() ? 'https' : $request->getScheme();
        $targetScheme = ($effectiveScheme === 'https' || $canonicalScheme === 'https') ? 'https' : $effectiveScheme;

        if ($currentHost === $canonicalHost && $effectiveScheme === $targetScheme) {
            return null;
        }

        return $targetScheme . '://' . $canonicalHost . $request->getRequestUri();
    }
}

