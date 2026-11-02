<?php

namespace App\Http\Middleware;

use App\Support\Environment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class Security
{
    /**
     * Set with the https redirect, as a Secure cookie: a browser keeps it only from an answer it got over https. A
     * request that brings it back and still looks like plain http came over https through a proxy that hides the scheme
     * (one that ended TLS, then passed the request on with no header at all): a second redirect would only loop, so it
     * gets the page. Laravel encrypts it like its other cookies, so nobody else can set it.
     */
    private const HTTPS_HOP_COOKIE = 'avdbcms_https_hop';

    /**
     * The same guard for clients that keep no cookies (search bots), used only where a loop is possible: on a site whose
     * https requests never reach this server as https (see TLS_CACHE_KEY). After a redirect that rests on a guess (see
     * visitorUsedHttp()), the same client asking for the same http address again within HOP_WINDOW seconds came back
     * over https through a proxy that hides it, and gets the page. So each address costs such a client one redirect,
     * never a loop; any other address is redirected as usual.
     *
     * A client is its connection (REMOTE_ADDR and the first X-Forwarded-For), its browser and its languages. The
     * redirects are kept in HOP_BUCKETS cache entries with fixed names (a client's connection picks one), each holding
     * at most HOP_PER_BUCKET of them (the oldest go first): the cache never holds more entries, whatever the clients send.
     * Without a working cache, the redirect stays as before (a browser still has the hop cookie).
     */
    public const HOP_WINDOW = 600;

    public const HOP_BUCKETS = 16;

    public const HOP_PER_BUCKET = 64;

    public const HOP_CACHE_PREFIX = 'avdbcms-https-hop:';

    /**
     * An https request for the site's own address that reached this server over TLS (the web server's HTTPS variable,
     * not a header anybody can send) proves that a redirect to https comes back here as https: no loop is possible. Then
     * a guessed http request is redirected every time, as 1.2.2 did, and the guard above is not used (it writes
     * nothing). Remembered for TLS_SECONDS after the last such request, written again at most every TLS_REFRESH seconds.
     */
    public const TLS_CACHE_KEY = 'avdbcms-https-tls-here';

    public const TLS_SECONDS = 86400;

    public const TLS_REFRESH = 3600;

    /**
     * Host-only cookies (1.2.3): up to 1.2.2 the installer wrote SESSION_DOMAIN=<host>, so the session, XSRF-TOKEN and
     * remember-me cookies carried "Domain=<host>" and reached every subdomain. Now they are host-only; a browser that
     * still holds the old Domain cookies would send both under the same names (PHP takes the first, the old one), so
     * the first normal page tells it once to drop them (an expired Domain=<host> cookie per name, the remember-me cookie
     * set again host-only) and leaves this mark. Not encrypted (EncryptCookies::$except): a forged mark only skips the
     * clean-up for that browser.
     */
    public const HOST_COOKIES_MARK = 'avd_hc';

    /** 400 days in minutes, the lifetime of Laravel's remember-me cookie. */
    private const LONG_COOKIE_MINUTES = 576000;

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
            // The same page as the visitor's browser has it (browserAddress(): its language and /index.php too), always a
            // path on this site: "//evil.example/x" (or "/\evil.example") would otherwise be kept as another host by the
            // URL generator. Without the folder of a site installed in one (site_path()): the URL generator puts it in
            // front (R2R-05: /site/site/...).
            return redirect()->secure('/'.ltrim(site_path($this->browserAddress($request)), '/\\'), 301)
                ->withCookie(cookie(self::HTTPS_HOP_COOKIE, '1', 60 * 24, '/', null, true, true, false, 'lax'));
        }

        // Blocked addresses (1.2.3): Members > Blocked addresses and its list "Blocked ranges" (addresses and CIDR ranges,
        // IPv4 and IPv6). The sign-in page and signed-in administrators are never blocked (App\Support\BlockedAddresses).
        if (\App\Support\BlockedAddresses::refuses($request)) {
            abort(403, __('Access denied.'));
        }

        $response = $next($request);

        $this->forgetDomainCookies($request, $response);

        // XSS Protection header — controlled by admin
        if (config('site.xss_protection', true)) {
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }

        // X-Content-Type-Options nosniff — controlled by admin
        if (config('site.nosniff_header', true)) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        // The password-reset page has the reset token in its address: no request it makes (fonts, images) names it.
        $response->headers->set('Referrer-Policy', $request->routeIs('password.reset') ? 'no-referrer' : 'no-referrer-when-downgrade');

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
     *
     * Cloudflare and a listed proxy say what the visitor used. "Straight from the visitor" and "nothing says it" are
     * guesses: a proxy that ended TLS, sends no header and passes the visitor's address on looks exactly like a visitor
     * on http. Where https requests are known to reach this server as https (TLS_CACHE_KEY), such a guess cannot loop
     * and is redirected every time; elsewhere it is guarded by cameBack() too, which needs no cookie.
     */
    private function visitorUsedHttp(Request $request): bool
    {
        // TLS ended at this very server: remember it. It decides nothing here (Cloudflare or a listed proxy that talks
        // TLS to this server still says what the visitor used, below).
        if (self::overTlsHere($request)) {
            $this->rememberTls($request);
        }

        // This browser followed the redirect over https before and still looks like http here: never a second one.
        if ($request->cookies->get(self::HTTPS_HOP_COOKIE) === '1') {
            return false;
        }

        $source = Environment::visitorSchemeSource($request);

        if ($source['scheme'] === 'https') {
            return false;
        }

        if ($source['scheme'] === 'http' && ! $source['guessed']) {
            return true;
        }

        if ($source['scheme'] === null && Environment::isSecureRequest()) {
            return false;
        }

        return $this->tlsEndsHere() || ! $this->cameBack($request);
    }

    /**
     * The request reached this server over TLS: the web server's own HTTPS variable ("on", or "1"; IIS sends "off" for
     * plain http). Not Request::secure(), which also believes an X-Forwarded-Proto header (whoever connects counts as a
     * proxy while the trusted proxies are '*').
     */
    private static function overTlsHere(Request $request): bool
    {
        $https = $request->server->get('HTTPS');
        $https = is_scalar($https) ? strtolower(trim((string) $https)) : '';

        return $https !== '' && $https !== 'off';
    }

    /**
     * An https request for the site's own address (the host of APP_URL, as the client named it) reached this server
     * over TLS: remembered for TLS_SECONDS (see TLS_CACHE_KEY). One cache read per such request, a write at most every
     * TLS_REFRESH seconds.
     */
    private function rememberTls(Request $request): void
    {
        $site = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $host = strtolower((string) preg_replace('/:\d+\z/', '', trim((string) $request->server->get('HTTP_HOST', ''))));

        if ($site === '' || $host !== $site) {
            return;
        }

        try {
            $store = Cache::store();
            $now = now()->getTimestamp();
            $seen = $store->get(self::TLS_CACHE_KEY);

            if (! is_int($seen) || $seen > $now || $seen <= $now - self::TLS_REFRESH) {
                $store->put(self::TLS_CACHE_KEY, $now, self::TLS_SECONDS);
            }
        } catch (Throwable) {
            // Nothing remembered: the guard stays in use (no loop either way).
        }
    }

    /** Do https requests for the site's address reach this server as https (one in the last TLS_SECONDS)? */
    private function tlsEndsHere(): bool
    {
        try {
            $seen = Cache::store()->get(self::TLS_CACHE_KEY);
        } catch (Throwable) {
            return false;
        }

        return is_int($seen) && $seen > now()->getTimestamp() - self::TLS_SECONDS;
    }

    /**
     * True when this client gets the page instead of a redirect that rests on a guess: it was sent from this http
     * address to https less than HOP_WINDOW seconds ago and asks for it over http again (it came back over https through
     * a proxy that hides it). Otherwise the redirect about to be answered is remembered (see HOP_WINDOW), and false.
     * Anything the cache cannot do: false (the redirect as before; a browser still has the hop cookie).
     */
    private function cameBack(Request $request): bool
    {
        try {
            $store = Cache::store();
            $connection = (string) $request->server->get('REMOTE_ADDR', '')."\n".trim(explode(',', (string) $request->headers->get('X-Forwarded-For', ''))[0]);
            $bucket = self::HOP_CACHE_PREFIX.(hexdec(substr(hash('sha256', $connection), 0, 6)) % self::HOP_BUCKETS);
            // This client and the address it asks for. "h": never a number, so the keys stay strings in the array.
            $hop = 'h'.substr(hash('sha256', implode("\n", [
                $connection,
                (string) $request->userAgent(),
                (string) $request->headers->get('Accept-Language', ''),
                strtoupper($request->getMethod()).' '.strtolower($request->getHttpHost()).$request->getRequestUri(),
            ])), 0, 32);
            // The cache's own clock (the times kept here and its expiry agree).
            $now = now()->getTimestamp();
            $kept = $store->get($bucket);
            $seen = array_filter(is_array($kept) ? $kept : [], fn (mixed $until, mixed $key): bool => is_string($key) && is_int($until) && $until > $now, ARRAY_FILTER_USE_BOTH);

            if (isset($seen[$hop])) {
                return true;
            }

            $seen[$hop] = $now + self::HOP_WINDOW;

            if (count($seen) > self::HOP_PER_BUCKET) {
                // The oldest redirects go first (equal times keep their order).
                asort($seen);
                $seen = array_slice($seen, -self::HOP_PER_BUCKET, null, true);
            }

            $store->put($bucket, $seen, self::HOP_WINDOW);
        } catch (Throwable) {
            // Nothing could be remembered: no guard for this request.
        }

        return false;
    }

    /**
     * Once per browser (see HOST_COOKIES_MARK): when the cookies are host-only (session.domain null) and the site's
     * address is a name, a request that brings the session, XSRF-TOKEN or a remember-me cookie without the mark gets an
     * expired "Domain=<host>" cookie for each of those names, its remember-me cookie again host-only (the same value),
     * and the mark. The session and XSRF-TOKEN cookies themselves are set host-only by StartSession and
     * VerifyCsrfToken, after these lines (they run around this middleware).
     */
    private function forgetDomainCookies(Request $request, mixed $response): void
    {
        if (! $response instanceof \Symfony\Component\HttpFoundation\Response
            || config('session.domain') !== null
            || $request->cookies->has(self::HOST_COOKIES_MARK)) {
            return;
        }

        $host = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST), '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $session = (string) config('session.cookie');
        $names = array_values(array_filter(
            array_map('strval', array_keys($request->cookies->all())),
            static fn (string $name): bool => $name === $session || $name === 'XSRF-TOKEN' || str_starts_with($name, 'remember_web_')
        ));

        if ($names === []) {
            return;
        }

        $secure = (bool) (config('session.secure') ?? $request->secure());
        $sameSite = config('session.same_site');
        $sameSite = is_string($sameSite) && $sameSite !== '' ? $sameSite : null;

        foreach ($names as $name) {
            $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie($name, null, 1, '/', $host, $secure, true, false, $sameSite));
        }

        foreach ($names as $name) {
            $value = $request->cookies->get($name);

            if (str_starts_with($name, 'remember_web_') && is_string($value) && $value !== '') {
                $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie($name, $value, now()->addMinutes(self::LONG_COOKIE_MINUTES), '/', null, $secure, true, false, $sameSite));
            }
        }

        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie(self::HOST_COOKIES_MARK, '1', now()->addMinutes(self::LONG_COOKIE_MINUTES), '/', null, $secure, true, false, $sameSite));
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

        // APP_URL's port stays with its scheme (an intranet site on :8080); another scheme goes to its own default port.
        $canonicalPort = parse_url($appUrl, PHP_URL_PORT);
        $port = is_int($canonicalPort) && $targetScheme === strtolower((string) $canonicalScheme) ? ':' . $canonicalPort : '';

        // The same page on the canonical host, as the visitor's browser has it: its language, and /index.php where the
        // request came in through it or the site's links carry it (public/index.php took both off the request's address).
        return $targetScheme . '://' . $canonicalHost . $port . $this->browserAddress($request);
    }

    /**
     * This request's own address as the visitor's browser has it (the path and the query), for a redirect that changes
     * only the scheme or the host: the folder of a site installed in one, /index.php where the request came in through it
     * or the site's links carry it, the language and the page ("/site/index.php/fr/trend?x=1"). public/index.php took the
     * language and /index.php off the address Laravel reads (App\Support\SiteAddress): http://www.example.com/fr/trend
     * went to https://example.com/trend, in the site's default language (round 3 review SB-RV-04). Always one leading
     * slash, never "//" or "/\" (another host).
     */
    private function browserAddress(Request $request): string
    {
        $page = site_path('/' . ltrim((string) $request->getRequestUri(), '/\\'));
        $language = (string) ($request->server('LOCALE_PREFIX') ?? $_SERVER['LOCALE_PREFIX'] ?? '');

        if (preg_match('#^/[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$#', $language) === 1) {
            // "/" -> "/fr", "/?x=1" -> "/fr?x=1", "/trend" -> "/fr/trend"
            $page = preg_match('#^/(?=$|[?\#])#', $page) === 1 ? $language . substr($page, 1) : $language . $page;
        }

        return '/' . ltrim(\App\Support\CleanUrls::requestForm(site_browser_path($page)), '/\\');
    }
}

