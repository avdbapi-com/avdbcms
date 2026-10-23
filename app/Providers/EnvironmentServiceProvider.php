<?php

namespace App\Providers;

use App\Support\AdminIpLock;
use App\Support\CorsConfig;
use App\Support\Environment;
use App\Support\EnvironmentRepairer;
use App\Support\RuntimeEnv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
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
        // nothing to bind; the provider only mutates runtime config
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

    private function applyTrustedProxies(): void
    {
        $raw = (string) (
            config('site.trusted_proxies')
            ?: RuntimeEnv::get('SITE_TRUSTED_PROXIES', RuntimeEnv::get('TRUSTED_PROXIES', ''))
        );

        $headers = SymfonyRequest::HEADER_X_FORWARDED_FOR
            | SymfonyRequest::HEADER_X_FORWARDED_HOST
            | SymfonyRequest::HEADER_X_FORWARDED_PORT
            | SymfonyRequest::HEADER_X_FORWARDED_PROTO
            | SymfonyRequest::HEADER_X_FORWARDED_PREFIX
            | SymfonyRequest::HEADER_X_FORWARDED_AWS_ELB;

        // Panels + reverse proxies we know run behind the app.
        $panel = Environment::panel();
        $autoPanelProxy = in_array($panel, [
            Environment::PANEL_CLOUDPANEL,
            Environment::PANEL_RUNCLOUD,
            Environment::PANEL_FORGE,
            Environment::PANEL_PLOI,
            Environment::PANEL_SPINUPWP,
        ], true);

        // Cloudflare fronts every proxied request (CF-Ray / CF-Connecting-IP). Auto-trust the
        // immediate upstream so X-Forwarded-Proto is honoured out of the box; otherwise a fresh
        // install behind Cloudflare Flexible SSL can 301-loop or serve mixed-content assets
        // until the admin manually fills in the Trusted Proxies setting. Anyone can send a CF-Ray
        // header, so it only counts when the upstream really is Cloudflare (its published ranges)
        // or a local proxy (loopback / private network) passing Cloudflare traffic on.
        $upstream = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $behindCloudflare = AdminIpLock::isCloudflare($upstream)
            || ((isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP'])) && AdminIpLock::isLocalProxy($upstream));

        $trusted = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: [])));

        if ($trusted === [] && ($autoPanelProxy || $behindCloudflare)) {
            $trusted = ['*'];
        }

        if ($trusted === []) {
            return;
        }

        if (in_array('*', $trusted, true) || in_array('**', $trusted, true)) {
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

        Request::setTrustedProxies($trusted, $headers);

        $this->adjustments[] = [
            'scope' => 'trusted_proxies',
            'message' => 'Applied trusted proxy list: ' . implode(', ', $trusted),
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

        $sessionDomain = (string) config('session.domain', '');
        if ($sessionDomain === '' || $sessionDomain === 'localhost' || strtolower($sessionDomain) === 'localhost.localdomain') {
            if ($host !== 'localhost' && $host !== '127.0.0.1') {
                Config::set('session.domain', $host);
                $this->adjustments[] = [
                    'scope' => 'session.domain',
                    'message' => "Auto-set session.domain from APP_URL host: {$host}",
                    'severity' => 'info',
                ];
            }
        }

        // URL generator should match the request scheme / port.
        if ($scheme === 'https') {
            URL::forceScheme('https');
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

    private function applySanctumStatefulFallback(): void
    {
        $domains = (array) config('sanctum.stateful', []);
        $domains = array_values(array_filter(array_map(static fn ($d) => trim((string) $d), $domains)));

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if (! $appHost) {
            return;
        }

        $appHostWithPort = $appHost;
        $appPort = parse_url((string) config('app.url', ''), PHP_URL_PORT);
        if ($appPort && ! in_array((int) $appPort, [80, 443], true)) {
            $appHostWithPort = $appHost . ':' . $appPort;
        }

        $needsAdd = ! in_array($appHost, $domains, true) && ! in_array($appHostWithPort, $domains, true);
        if (! $needsAdd) {
            return;
        }

        $domains[] = $appHostWithPort;
        Config::set('sanctum.stateful', array_values(array_unique($domains)));

        $this->adjustments[] = [
            'scope' => 'sanctum.stateful',
            'message' => "Appended APP_URL host to sanctum.stateful: {$appHostWithPort}",
            'severity' => 'info',
        ];
    }

    private function applyWebServerStreamingDefault(): void
    {
        if (config('site.stream_accelerator') !== null && config('site.stream_accelerator') !== '') {
            return;
        }

        $default = match (Environment::webServer()) {
            Environment::WEB_NGINX => 'nginx',
            Environment::WEB_APACHE => 'apache',
            Environment::WEB_LITESPEED, Environment::WEB_OPENLITESPEED => 'litespeed',
            default => 'auto',
        };

        Config::set('site.stream_accelerator', RuntimeEnv::get('STREAM_ACCELERATOR', $default));

        if ($default !== 'auto') {
            $this->adjustments[] = [
                'scope' => 'streaming',
                'message' => "Selected '{$default}' stream accelerator based on detected web server.",
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

    private function applyCleanUrlFallback(): void
    {
        $explicitClean = config('app.clean_urls');
        if ($explicitClean === true) {
            return;
        }

        $needsIndexPhp = false;

        if ($explicitClean === false) {
            $needsIndexPhp = true;
        } elseif (! empty($_SERVER['AVDBCLIP_USED_INDEX_PHP'])) {
            $needsIndexPhp = true;
        } elseif (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/index.php')) {
            $needsIndexPhp = true;
        } else {
            $serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
            $isApacheOrLiteSpeed = str_contains($serverSoftware, 'apache')
                || str_contains($serverSoftware, 'litespeed')
                || in_array(Environment::webServer(), [
                    Environment::WEB_APACHE,
                    Environment::WEB_LITESPEED,
                    Environment::WEB_OPENLITESPEED,
                ], true);

            $panel = Environment::panel();
            $hasBuiltInNginxRewrite = in_array($panel, [
                Environment::PANEL_CLOUDPANEL,
                Environment::PANEL_FORGE,
                Environment::PANEL_PLOI,
                Environment::PANEL_SPINUPWP,
            ], true);

            $isRootFallback = ! empty($_SERVER['AVDBCLIP_ROOT_FALLBACK']);

            if ($isRootFallback && ! $isApacheOrLiteSpeed) {
                // Running from root index.php on Nginx/OpenResty without URL rewrite
                $needsIndexPhp = true;
            } elseif (! $isApacheOrLiteSpeed && ! $hasBuiltInNginxRewrite && (
                str_contains($serverSoftware, 'nginx')
                || str_contains($serverSoftware, 'openresty')
                || Environment::webServer() === Environment::WEB_NGINX
            )) {
                $needsIndexPhp = true;
            }
        }

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

            $this->adjustments[] = [
                'scope' => 'clean_urls',
                'message' => 'Active web server does not have URL rewrite rules enabled; auto-routed endpoints through /index.php to prevent 404s.',
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
        $requestRoot = $this->currentRequestRoot();

        if ($requestRoot === null) {
            return $appUrl;
        }

        $host = parse_url($appUrl, PHP_URL_HOST);
        $looksLocal = $appUrl === ''
            || in_array(strtolower((string) $host), ['localhost', '127.0.0.1', '::1', 'localhost.localdomain'], true);

        if (! $looksLocal) {
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

            return rtrim($request->getSchemeAndHttpHost(), '/');
        } catch (Throwable) {
            return null;
        }
    }
}
