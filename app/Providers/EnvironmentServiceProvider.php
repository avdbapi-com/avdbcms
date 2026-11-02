<?php

namespace App\Providers;

use App\Http\Middleware\TrustProxies;
use App\Support\CleanUrls;
use App\Support\CorsConfig;
use App\Support\Environment;
use App\Support\EnvironmentRepairer;
use App\Support\RuntimeEnv;
use App\Support\SendFile;
use App\Support\ServerChecks;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Auto-configures the runtime for the environment it is actually running on.
 *
 * Executes on every boot (not cached) so swapping between a panel install
 * and a container keeps working without a deploy step. Individual decisions
 * are bounded to safe, reversible changes:
 *
 *   - Expose detected web server + panel through `config('site.web_server')`
 *     and `config('site.panel')`.
 *   - Derive `app.url` host/scheme → Sanctum stateful domains + CORS.
 *   - Force `session.secure` + `session.same_site=lax` on HTTPS.
 *   - Populate `session.domain` from `APP_URL` when it was left as
 *     `localhost` by a stale `.env.prod` copy.
 *   - Register trusted proxies from `SITE_TRUSTED_PROXIES` / the admin
 *     setting so `$request->secure()` / `$request->ip()` work behind
 *     Cloudflare, panel reverse proxies, LiteSpeed, etc.
 *   - Fall back cache/session/queue drivers from redis → file/database
 *     when Redis is unreachable, so a missing Redis on a shared host
 *     degrades gracefully instead of white-screening.
 *   - Force queue driver to `sync` when the CLI asks for `avdb:import`
 *     inline (useful for debugging on hosts without a queue worker).
 *   - Automatic Clean URL fallback: when running on Nginx without URL
 *     rewriting configured, route URLs automatically maintain /index.php
 *     routing so newbie users never encounter 404s.
 *
 * Every change is logged once via `config('site.environment_adjustments')`
 * so the preflight page can surface exactly what changed and why.
 */
class EnvironmentServiceProvider extends ServiceProvider
{
    /** @var array<int, array{scope:string,message:string,severity:string}> */
    private array $adjustments = [];

    public function register(): void
    {
        // The web group's session middleware remembers each page for back() in a form that reaches the site
        // (App\Http\Middleware\StartSessionInSiteForm); everything else of Laravel's StartSession stays as it is.
        $this->app->singleton(StartSession::class, static function ($app): StartSession {
            return new \App\Http\Middleware\StartSessionInSiteForm($app->make(SessionManager::class), static function () use ($app) {
                return $app->make(\Illuminate\Contracts\Cache\Factory::class);
            });
        });

        // Laravel's URL generator, made as the framework makes it (its own extension of 'url' still adds the session and
        // key resolvers), with relative route addresses that keep the folder of a site installed in one
        // (App\Support\SiteUrlGenerator; round 3 fix pass, R2R-05). Identical at a domain's root.
        $this->app->singleton('url', static function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            return new \App\Support\SiteUrlGenerator($routes, $app->rebinding('request', static function ($app, $request) {
                $app['url']->setRequest($request);
            }), $app['config']['app.asset_url']);
        });
    }

    public function boot(): void
    {
        try {
            $this->exposeEnvironment();
            $this->applyTrustedProxies();
            $this->applyFilesystemSelfHealing();
            $this->applyAppUrlDerivedDefaults();
            $this->applySecureCookieDefaults();
            $this->applyCorsOriginFallback();
            $this->applySanctumStatefulFallback();
            $this->applyWebServerStreamingDefault();
            $this->applyRedisFallback();
            $this->applyCleanUrlFallback();
        } catch (Throwable $e) {
            // Never break the request because of environment auto-tuning.
            $this->adjustments[] = [
                'scope' => 'environment',
                'message' => 'Auto-configuration failed: ' . Str::limit($e->getMessage(), 200, ''),
                'severity' => 'warn',
            ];
        }

        Config::set('site.environment_adjustments', $this->adjustments);
    }

    private function exposeEnvironment(): void
    {
        Config::set('site.os_family', Environment::osFamily());
        Config::set('site.web_server', Environment::webServer());
        Config::set('site.web_server_label', Environment::webServerLabel());
        Config::set('site.panel', Environment::panel());
        Config::set('site.panel_label', Environment::panelLabel());
        Config::set('site.sapi', Environment::sapi());
        Config::set('site.can_run_processes', Environment::canRunProcesses());
        Config::set('site.can_run_background_popen', Environment::canRunBackgroundPopen());
    }

    /**
     * The same trusted proxies and headers as App\Http\Middleware\TrustProxies (which Laravel applies again at the
     * start of each request), for the code that runs before that middleware. Without a list of the owner's: this
     * machine, private networks and Cloudflare, so Cloudflare (Flexible SSL too) and panel reverse proxies work out of
     * the box, while a visitor who sends X-Forwarded-* headers is not believed. X-Forwarded-Host, -Prefix and -Port are
     * never trusted: links are built from APP_URL (normalizeAppUrl()).
     */
    private function applyTrustedProxies(): void
    {
        $headers = TrustProxies::TRUSTED_HEADERS;
        $proxies = TrustProxies::trustedProxies();

        if ($proxies === '*') {
            // The owner trusts whoever connects (a '*' in the setting or in .env).
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            if (! is_string($remoteAddr) || $remoteAddr === '') {
                return;
            }

            Request::setTrustedProxies([$remoteAddr], $headers);

            $this->adjustments[] = [
                'scope' => 'trusted_proxies',
                'message' => "Trusted the current proxy IP from REMOTE_ADDR: {$remoteAddr}",
                'severity' => 'info',
            ];

            return;
        }

        Request::setTrustedProxies($proxies, $headers);

        $this->adjustments[] = [
            'scope' => 'trusted_proxies',
            'message' => TrustProxies::usesDefaultList()
                ? 'Trusted proxies: this server, private networks and Cloudflare (no list set).'
                : 'Applied trusted proxy list: :proxies',
            'replace' => ['proxies' => implode(', ', $proxies)],
            'severity' => 'info',
        ];
    }

    private function applyFilesystemSelfHealing(): void
    {
        foreach (EnvironmentRepairer::repairRuntimeFilesystem() as $repair) {
            $this->adjustments[] = $repair;
        }
    }

    private function applyAppUrlDerivedDefaults(): void
    {
        $appUrl = $this->normalizeAppUrl();
        if ($appUrl === '') {
            return;
        }

        $host = parse_url($appUrl, PHP_URL_HOST);
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $port = parse_url($appUrl, PHP_URL_PORT);

        if (! $host) {
            return;
        }

        // Cookies (session, XSRF-TOKEN, remember me) only for the site's own host: a cookie with Domain=<host> reaches
        // every subdomain too (another app, a forgotten CNAME). Empty, localhost or the APP_URL host itself (what the
        // installer wrote up to 1.2.2) become host-only; any other value is the owner's choice (".example.com") and stays.
        $sessionDomain = strtolower(trim((string) config('session.domain', '')));
        if (in_array($sessionDomain, ['', 'localhost', 'localhost.localdomain', strtolower((string) $host)], true)) {
            if (config('session.domain') !== null) {
                Config::set('session.domain', null);
                $this->adjustments[] = [
                    'scope' => 'session.domain',
                    'message' => 'Cookies are kept for this host only (session.domain was ":domain").',
                    'replace' => ['domain' => $sessionDomain],
                    'severity' => 'info',
                ];
            }
        }

        // https links wherever the site's address or the request says https. The second part matters where the
        // origin rewrites REMOTE_ADDR to the visitor (mod_remoteip, OpenLiteSpeed "use client IP"): Cloudflare's
        // X-Forwarded-Proto is then not from a trusted address, yet the visitor is on https (Flexible SSL with an
        // http APP_URL). A claim of https only ever turns links to https, as before 1.2.3.
        if ($scheme === 'https' || Environment::isSecureRequest()) {
            URL::forceScheme('https');
        }

        // A site installed in a folder of its domain (https://example.com/site): its session cookie (and the XSRF-TOKEN
        // cookie, which follows it) goes to that folder only, never to another site or application of the same domain
        // (round 3 fix pass, R2R-05). A path the owner set in config/session.php stays.
        $folder = function_exists('site_folder') ? site_folder() : '';
        if ($folder !== '' && config('session.path', '/') === '/') {
            Config::set('session.path', $folder);
        }

        if ($port && ! in_array((int) $port, [80, 443], true)) {
            // No official Laravel API to force a port, but setting APP_URL
            // already propagates through url()/route(). Nothing else to do.
        }
    }

    private function applySecureCookieDefaults(): void
    {
        if (! Environment::isSecureRequest()) {
            return;
        }

        if (config('session.secure') !== true) {
            Config::set('session.secure', true);
            $this->adjustments[] = [
                'scope' => 'session.secure',
                'message' => 'Forced session.secure=true because the request / APP_URL is HTTPS.',
                'severity' => 'info',
            ];
        }

        if (! config('session.same_site')) {
            Config::set('session.same_site', 'lax');
        }
    }

    private function applyCorsOriginFallback(): void
    {
        $origins = (array) config('cors.allowed_origins', []);
        $origins = array_values(array_filter(array_map('strval', $origins)));

        $supportsCredentials = (bool) config('cors.supports_credentials', true);
        $appUrl = trim((string) config('app.url', ''));
        $derived = self::originFromUrl($appUrl);

        $hasWildcard = in_array('*', $origins, true);

        if ($hasWildcard && $supportsCredentials) {
            // CORS spec forbids `*` + credentials; replace wildcard with APP_URL.
            $replacement = $derived ?: CorsConfig::defaultCredentialedOrigins();
            if ($replacement !== []) {
                Config::set('cors.allowed_origins', is_array($replacement) ? $replacement : [$replacement]);
                $this->adjustments[] = [
                    'scope' => 'cors.allowed_origins',
                    'message' => 'Replaced wildcard CORS origin with APP_URL to satisfy credentialed-CORS rules.',
                    'severity' => 'warn',
                ];
            }
        }

        if ($origins === [] && $derived) {
            Config::set('cors.allowed_origins', [$derived]);
        }
    }

    /**
     * The site's own address is always a "stateful" one for Sanctum, so the site's pages call its API with the member's
     * session. Sanctum compares the Referer / Origin of a page with each entry literally ("host" or "host:port"): on an
     * address with a port (the shipped docker-compose 8080:80, an IP with a port, a panel preview) only "host:port"
     * matches. The installer up to 1.2.3 RC1 wrote the bare host into SANCTUM_STATEFUL_DOMAINS, and this fallback added
     * nothing when the bare host was listed, so every signed-in member's API call (like, comment, subscribe, Save, the
     * bell, history) answered 401 on such a site. So the address of APP_URL (with its port when it has one, in lower
     * case as browsers send it) is added whatever .env lists; the entries already there stay as they are. Existing
     * sites are fixed without a change to their .env.
     */
    private function applySanctumStatefulFallback(): void
    {
        $domains = (array) config('sanctum.stateful', []);
        $domains = array_values(array_filter(array_map(static fn ($d) => trim((string) $d), $domains)));

        $address = self::statefulAddress((string) config('app.url', ''));
        if ($address === null || in_array($address, $domains, true)) {
            return;
        }

        $domains[] = $address;
        Config::set('sanctum.stateful', array_values(array_unique($domains)));

        $this->adjustments[] = [
            'scope' => 'sanctum.stateful',
            'message' => "Appended APP_URL host to sanctum.stateful: {$address}",
            'severity' => 'info',
        ];
    }

    /**
     * The entry Sanctum needs for pages served at $url: "host:port" when the address has a port other than 80 and 443,
     * else "host" (lower case); null without a host. The installer writes the same value (App\Support\Installer).
     */
    public static function statefulAddress(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        $port = parse_url(trim($url), PHP_URL_PORT);

        return $port && ! in_array((int) $port, [80, 443], true) ? $host.':'.$port : $host;
    }

    /**
     * "auto" unless .env pins a mode (STREAM_ACCELERATOR). Up to RC1 a site without that line was pinned to its server's
     * accelerator here, so nginx got every video as X-Accel-Redirect even without the internal location (HTTP 500,
     * R1B-003): App\Support\SendFile decides per server now (nginx only after its internal location was checked).
     */
    private function applyWebServerStreamingDefault(): void
    {
        if (config('site.stream_accelerator') !== null && config('site.stream_accelerator') !== '') {
            return;
        }

        Config::set('site.stream_accelerator', RuntimeEnv::get('STREAM_ACCELERATOR', SendFile::MODE_AUTO));

        $accelerator = SendFile::configuredMode() === SendFile::MODE_AUTO ? SendFile::effectiveMode() : SendFile::MODE_NONE;

        if ($accelerator !== SendFile::MODE_NONE && $accelerator !== SendFile::MODE_AUTO) {
            $this->adjustments[] = [
                'scope' => 'streaming',
                'message' => "Selected '{$accelerator}' stream accelerator based on detected web server.",
                'severity' => 'info',
            ];
        }
    }

    private function applyRedisFallback(): void
    {
        $uses = [
            'cache.default' => config('cache.default'),
            'cache.limiter' => config('cache.limiter'),
            'session.driver' => config('session.driver'),
            'queue.default' => config('queue.default'),
        ];

        $wantsRedis = in_array('redis', $uses, true);
        if (! $wantsRedis) {
            return;
        }

        if (! extension_loaded('redis') && (string) RuntimeEnv::get('REDIS_CLIENT', config('database.redis.client')) === 'phpredis') {
            Config::set('database.redis.client', 'predis');
        }

        if (Environment::redisReachable()) {
            return;
        }

        foreach ($uses as $key => $value) {
            if ($value !== 'redis') {
                continue;
            }

            match ($key) {
                'cache.default' => Config::set('cache.default', 'file'),
                'cache.limiter' => Config::set('cache.limiter', 'file'),
                'session.driver' => Config::set('session.driver', 'file'),
                'queue.default' => Config::set('queue.default', 'database'),
                default => null,
            };
        }

        $this->adjustments[] = [
            'scope' => 'redis_fallback',
            'message' => 'Redis is not reachable. Temporarily switched redis-backed services to file/database drivers. Configure Redis or change these drivers to silence this warning.',
            'severity' => 'warn',
        ];
    }

    /**
     * Links with /index.php/... or clean ones (App\Support\CleanUrls decides; APP_CLEAN_URLS overrides). On nginx the
     * site checks once in a while whether a clean address reaches it (App\Support\ServerChecks), after this request's
     * answer was sent; until a check said yes, the links keep /index.php/..., so none ends on nginx's 404 page.
     */
    private function applyCleanUrlFallback(): void
    {
        [$needsIndexPhp, $reason] = CleanUrls::decide();
        Config::set('app.clean_url_reason', $reason);

        if (CleanUrls::setting() === CleanUrls::AUTO && CleanUrls::onNginx()) {
            ServerChecks::runSoon(ServerChecks::CLEAN_URLS, CleanUrls::arrivedClean(), CleanUrls::requestUsedIndexPhp());
        }

        // Pagination links are built from the request's own address, which public/index.php took /index.php off: they
        // get it back where the site needs it (a server without rewrite rules answered "page 2" with its 404 page).
        Paginator::currentPathResolver(static fn (): string => CleanUrls::requestForm(request()->url()));

        if ($needsIndexPhp) {
            Config::set('app.clean_url_fallback', true);

            URL::formatPathUsing(function ($path) {
                if ($path === '' || $path === '/') {
                    return '/index.php';
                }
                if (str_starts_with($path, '/index.php')) {
                    return $path;
                }
                return '/index.php' . (str_starts_with($path, '/') ? $path : '/' . $path);
            });

            $checked = ServerChecks::entry(ServerChecks::CLEAN_URLS);

            $this->adjustments[] = [
                'scope' => 'clean_urls',
                'message' => CleanUrls::REASONS[$reason] ?? CleanUrls::REASONS['not_checked'],
                // :date is filled in by the Health check in the admin's language and time zone (PreflightController).
                'replace' => ['date' => $checked !== null ? (string) $checked['checked_at'] : ''],
                'severity' => 'info',
            ];
        }
    }

    private static function originFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (! $scheme || ! $host) {
            return null;
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function normalizeAppUrl(): string
    {
        $appUrl = trim((string) config('app.url'));
        $host = parse_url($appUrl, PHP_URL_HOST);
        $looksLocal = $appUrl === ''
            || in_array(strtolower((string) $host), ['localhost', '127.0.0.1', '::1', 'localhost.localdomain'], true);

        if (! $looksLocal) {
            // The site's real address: every link the site builds (password reset and e-mail verification links, other
            // mail links, route(), url(), asset(), redirects) starts with it, never with the Host header a request
            // brings (a forged Host on a default or catch-all virtual host could otherwise put another site into a
            // reset link). The /index.php prefix of applyCleanUrlFallback() composes with it; the scheme still
            // follows the request (applyAppUrlDerivedDefaults()).
            if (is_string($host) && $host !== '' && preg_match('#^https?://#i', $appUrl) === 1) {
                URL::forceRootUrl(rtrim($appUrl, '/'));
            }

            return $appUrl;
        }

        // A fresh copy with the example address (http://localhost): use the address the browser asked for, so the
        // installer works on any domain.
        $requestRoot = $this->currentRequestRoot();

        if ($requestRoot === null) {
            return $appUrl;
        }

        Config::set('app.url', $requestRoot);
        $this->adjustments[] = [
            'scope' => 'app.url',
            'message' => "Auto-set app.url from the current HTTP request: {$requestRoot}",
            'severity' => 'info',
        ];

        return $requestRoot;
    }

    private function currentRequestRoot(): ?string
    {
        if ($this->app->runningInConsole()) {
            return null;
        }

        try {
            $request = request();
            $host = strtolower($request->getHost());

            if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', 'localhost.localdomain'], true)) {
                return null;
            }

            // With the folder of a site installed in one (https://example.com/site): the installer writes this address
            // as APP_URL (round 3 fix pass, R2R-05).
            return rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
        } catch (Throwable) {
            return null;
        }
    }
}
