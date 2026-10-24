<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Central environment detector.
 *
 * Detects: operating system, web server (Apache / Nginx / LiteSpeed / OLS /
 * Caddy / FrankenPHP / RoadRunner / Octane / IIS / built-in / cli),
 * hosting panel (cPanel / Plesk / DirectAdmin / aaPanel / CyberPanel /
 * ISPConfig / Runcloud / Webmin / Virtualmin / Webuzo / HestiaCP /
 * VestaCP / CloudPanel / none), available shell-exec primitives, the
 * active `disable_functions` and `open_basedir`, Redis reachability,
 * ffmpeg / ffprobe / mysqldump resolution, storage writability and PHP
 * ini vs recommended limits.
 *
 * Results are cheap and memoized per request. Heavy probes (Redis
 * reachability, mysqldump resolution) are cached for a short TTL so
 * the preflight page and the `app:doctor` command can render fast
 * without repeating network or subprocess work.
 */
final class Environment
{
    public const WEB_APACHE = 'apache';
    public const WEB_NGINX = 'nginx';
    public const WEB_LITESPEED = 'litespeed';
    public const WEB_OPENLITESPEED = 'openlitespeed';
    public const WEB_CADDY = 'caddy';
    public const WEB_FRANKENPHP = 'frankenphp';
    public const WEB_ROADRUNNER = 'roadrunner';
    public const WEB_OCTANE_SWOOLE = 'octane-swoole';
    public const WEB_IIS = 'iis';
    public const WEB_CLI_SERVER = 'cli-server';
    public const WEB_CLI = 'cli';
    public const WEB_UNKNOWN = 'unknown';

    public const PANEL_CPANEL = 'cpanel';
    public const PANEL_PLESK = 'plesk';
    public const PANEL_DIRECTADMIN = 'directadmin';
    public const PANEL_AAPANEL = 'aapanel';
    public const PANEL_CYBERPANEL = 'cyberpanel';
    public const PANEL_ISPCONFIG = 'ispconfig';
    public const PANEL_RUNCLOUD = 'runcloud';
    public const PANEL_WEBMIN = 'webmin';
    public const PANEL_VIRTUALMIN = 'virtualmin';
    public const PANEL_WEBUZO = 'webuzo';
    public const PANEL_HESTIACP = 'hestiacp';
    public const PANEL_VESTACP = 'vestacp';
    public const PANEL_CLOUDPANEL = 'cloudpanel';
    public const PANEL_FORGE = 'forge';
    public const PANEL_PLOI = 'ploi';
    public const PANEL_SPINUPWP = 'spinupwp';
    public const PANEL_ONEPANEL = '1panel';
    public const PANEL_NONE = 'none';

    public const PROBE_CACHE_TTL = 60;

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    public static function reset(): void
    {
        self::$memo = null;
    }

    /**
     * Detected web server token (one of the WEB_* constants).
     */
    public static function webServer(): string
    {
        return self::memo('web_server', fn (): string => self::detectWebServer());
    }

    public static function webServerLabel(): string
    {
        return match (self::webServer()) {
            self::WEB_APACHE => 'Apache HTTPD',
            self::WEB_NGINX => 'Nginx',
            self::WEB_LITESPEED => 'LiteSpeed Enterprise',
            self::WEB_OPENLITESPEED => 'OpenLiteSpeed',
            self::WEB_CADDY => 'Caddy',
            self::WEB_FRANKENPHP => 'FrankenPHP',
            self::WEB_ROADRUNNER => 'RoadRunner',
            self::WEB_OCTANE_SWOOLE => 'Laravel Octane (Swoole)',
            self::WEB_IIS => 'Microsoft IIS',
            self::WEB_CLI_SERVER => 'PHP built-in web server',
            self::WEB_CLI => 'CLI / console',
            default => 'Unknown web server',
        };
    }

    /**
     * Detected hosting panel (one of the PANEL_* constants).
     */
    public static function panel(): string
    {
        return self::memo('panel', fn (): string => self::detectPanel());
    }

    public static function panelLabel(): string
    {
        return match (self::panel()) {
            self::PANEL_CPANEL => 'cPanel / WHM',
            self::PANEL_PLESK => 'Plesk',
            self::PANEL_DIRECTADMIN => 'DirectAdmin',
            self::PANEL_AAPANEL => 'aaPanel',
            self::PANEL_CYBERPANEL => 'CyberPanel',
            self::PANEL_ISPCONFIG => 'ISPConfig',
            self::PANEL_RUNCLOUD => 'RunCloud',
            self::PANEL_WEBMIN => 'Webmin',
            self::PANEL_VIRTUALMIN => 'Virtualmin',
            self::PANEL_WEBUZO => 'Webuzo',
            self::PANEL_HESTIACP => 'HestiaCP',
            self::PANEL_VESTACP => 'VestaCP',
            self::PANEL_CLOUDPANEL => 'CloudPanel',
            self::PANEL_FORGE => 'Laravel Forge',
            self::PANEL_PLOI => 'Ploi',
            self::PANEL_SPINUPWP => 'SpinupWP',
            self::PANEL_ONEPANEL => '1Panel',
            default => 'None / generic host',
        };
    }

    public static function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function isMac(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public static function isBsd(): bool
    {
        return PHP_OS_FAMILY === 'BSD';
    }

    public static function usesFpm(): bool
    {
        return str_contains(strtolower((string) php_sapi_name()), 'fpm');
    }

    public static function usesLsapi(): bool
    {
        return (string) php_sapi_name() === 'litespeed';
    }

    /**
     * Lowercased SAPI name (fpm-fcgi, apache2handler, cli, litespeed, etc).
     */
    public static function sapi(): string
    {
        return strtolower((string) php_sapi_name());
    }

    /**
     * Functions from `disable_functions` ini as a lowercase-trimmed set.
     *
     * @return array<string, true>
     */
    public static function disabledFunctions(): array
    {
        return self::memo('disabled_functions', function (): array {
            $disabled = (string) ini_get('disable_functions');
            $items = array_filter(array_map(
                static fn (string $fn): string => strtolower(trim($fn)),
                preg_split('/[,\s]+/', $disabled) ?: []
            ));

            return array_fill_keys(array_values($items), true);
        });
    }

    public static function functionAvailable(string $name): bool
    {
        $disabled = self::disabledFunctions();

        return function_exists($name) && ! isset($disabled[strtolower($name)]);
    }

    /**
     * True when the environment can launch subprocesses (Symfony Process works).
     */
    public static function canRunProcesses(): bool
    {
        foreach (['proc_open', 'proc_get_status', 'proc_close'] as $fn) {
            if (! self::functionAvailable($fn)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when background `popen(…, 'r')` fire-and-forget spawning is possible.
     * This is restrictive: many shared hosts disable `popen` even when
     * `proc_open` is available.
     */
    public static function canRunBackgroundPopen(): bool
    {
        return self::functionAvailable('popen') && self::functionAvailable('pclose');
    }

    /**
     * Parsed `open_basedir` entries (absolute, normalised, unique).
     *
     * @return array<int, string>
     */
    public static function openBasedirPaths(): array
    {
        return self::memo('open_basedir', function (): array {
            $raw = (string) ini_get('open_basedir');
            if ($raw === '') {
                return [];
            }

            $sep = self::isWindows() ? ';' : PATH_SEPARATOR;
            $parts = explode($sep, $raw);

            return array_values(array_unique(array_filter(array_map(static function (string $p): string {
                $p = trim($p);

                return $p === '' ? '' : rtrim(str_replace('\\', '/', $p), '/');
            }, $parts))));
        });
    }

    public static function hasOpenBasedir(): bool
    {
        return self::openBasedirPaths() !== [];
    }

    /**
     * Redis reachable on the configured cache/queue/session connection.
     */
    public static function redisReachable(): bool
    {
        return self::cachedProbe('redis.reachable', function (): bool {
            try {
                \Illuminate\Support\Facades\Redis::connection(
                    (string) config('cache.stores.redis.connection', 'default')
                )->ping();

                return true;
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * Resolved ffmpeg binary (empty string when unresolved).
     */
    public static function ffmpegBinary(): string
    {
        return self::cachedProbe('bin.ffmpeg', static fn (): string => self::resolveBinary('ffmpeg'));
    }

    public static function ffprobeBinary(): string
    {
        return self::cachedProbe('bin.ffprobe', static fn (): string => self::resolveBinary('ffprobe'));
    }

    public static function mysqldumpBinary(): string
    {
        return self::cachedProbe('bin.mysqldump', static function (): string {
            $explicit = trim((string) RuntimeEnv::get('MYSQLDUMP_PATH', ''));

            if ($explicit !== '' && self::candidateExistsOrCannotBeInspected($explicit)) {
                return $explicit;
            }

            $candidates = self::isWindows()
                ? ['mysqldump.exe', 'mariadb-dump.exe']
                : ['mysqldump', 'mariadb-dump'];

            foreach ($candidates as $name) {
                $found = self::whichBinary($name);
                if ($found !== '') {
                    return $found;
                }
            }

            if (self::isWindows()) {
                $windowsExtras = [
                    'D:\\OSPanel\\modules\\database\\MariaDB-11.4-Win10\\bin\\mysqldump.exe',
                    'C:\\Program Files\\MariaDB\\bin\\mysqldump.exe',
                    'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
                    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                    'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
                ];

                foreach ($windowsExtras as $candidate) {
                    if (self::candidateExistsOrCannotBeInspected($candidate)) {
                        return $candidate;
                    }
                }
            }

            return '';
        });
    }

    /**
     * Storage directories expected to be writable.
     *
     * @return array<string, bool>
     */
    public static function writabilityMap(): array
    {
        return self::memo('writability', static function (): array {
            $paths = [
                'storage' => storage_path(),
                'storage/framework' => storage_path('framework'),
                'storage/framework/cache' => storage_path('framework/cache'),
                'storage/framework/sessions' => storage_path('framework/sessions'),
                'storage/framework/views' => storage_path('framework/views'),
                'storage/logs' => storage_path('logs'),
                'bootstrap/cache' => base_path('bootstrap/cache'),
                'public' => public_path(),
            ];

            $out = [];
            foreach ($paths as $label => $path) {
                $out[$label] = is_dir($path) && is_writable($path);
            }

            return $out;
        });
    }

    /**
     * PHP ini vs recommended values.
     *
     * @return array<int, array{key: string, current: string, recommended: string|int, severity: string}>
     */
    public static function phpLimitsReport(): array
    {
        $currentMemoryMb = self::iniBytes('memory_limit') / (1024 * 1024);
        $currentUploadMb = self::iniBytes('upload_max_filesize') / (1024 * 1024);
        $currentPostMb = self::iniBytes('post_max_size') / (1024 * 1024);
        $maxExec = (int) ini_get('max_execution_time');
        $maxInput = (int) ini_get('max_input_time');

        $entries = [
            ['memory_limit', (string) ini_get('memory_limit'), '512M', $currentMemoryMb >= 512 ? 'ok' : ($currentMemoryMb >= 256 ? 'warn' : 'danger')],
            ['upload_max_filesize', (string) ini_get('upload_max_filesize'), '256M', $currentUploadMb >= 256 ? 'ok' : ($currentUploadMb >= 128 ? 'warn' : 'danger')],
            ['post_max_size', (string) ini_get('post_max_size'), '260M', $currentPostMb >= 260 ? 'ok' : ($currentPostMb >= 130 ? 'warn' : 'danger')],
            ['max_execution_time', (string) $maxExec, 600, ($maxExec === 0 || $maxExec >= 600) ? 'ok' : ($maxExec >= 120 ? 'warn' : 'danger')],
            ['max_input_time', (string) $maxInput, 300, ($maxInput === -1 || $maxInput >= 300) ? 'ok' : ($maxInput >= 60 ? 'warn' : 'danger')],
        ];

        return array_map(static function (array $row): array {
            return [
                'key' => $row[0],
                'current' => $row[1],
                'recommended' => $row[2],
                'severity' => $row[3],
            ];
        }, $entries);
    }

    /**
     * True when the incoming request looks like it was issued over HTTPS,
     * even when the scheme comes from a trusted reverse proxy header.
     */
    public static function isSecureRequest(): bool
    {
        if (app()->runningInConsole()) {
            return str_starts_with((string) config('app.url', ''), 'https://');
        }

        try {
            $request = request();
            if ($request && $request->secure()) {
                return true;
            }

            if ($request) {
                foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME', 'HTTP_X_URL_SCHEME'] as $header) {
                    $value = strtolower((string) $request->server($header, ''));
                    if ($value === 'https') {
                        return true;
                    }
                }

                if ((int) $request->server('HTTP_X_FORWARDED_PORT', 0) === 443) {
                    return true;
                }
            }
        } catch (Throwable) {
            // fall through
        }

        return str_starts_with((string) config('app.url', ''), 'https://');
    }

    /**
     * Request headers that only a proxy in front of the site sends: a request without any of them, from an address on
     * the internet, came straight from the visitor (see visitorScheme()).
     */
    private const PROXY_HEADERS = [
        'Forwarded', 'Via', 'X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Host', 'X-Forwarded-Port',
        'X-Forwarded-Scheme', 'X-Forwarded-Ssl', 'X-Forwarded-Server', 'X-Original-Forwarded-For', 'X-Url-Scheme',
        'Front-End-Https', 'X-Real-IP', 'X-Client-IP', 'Client-IP', 'True-Client-IP', 'X-Cluster-Client-IP',
        'Fastly-Client-IP', 'Incap-Client-IP', 'X-Sucuri-ClientIP', 'X-ProxyUser-Ip', 'CDN-Loop', 'Akamai-Origin-Hop',
        'X-Amz-Cf-Id', 'CloudFront-Forwarded-Proto', 'CF-Ray', 'CF-Connecting-IP', 'CF-Visitor',
    ];

    /**
     * The scheme the visitor's browser used for this request: 'https', 'http', or null when that cannot be known (a
     * proxy in front that does not say). Unlike isSecureRequest(), an https APP_URL proves nothing here; "Always use the
     * secure address (https)" redirects on 'http' only, so no answer here may ever say 'http' to a visitor on https:
     *
     *  - https: the request is secure (TLS here, or a trusted proxy's X-Forwarded-Proto), or any proxy header says https
     *    (CF-Visitor, X-Forwarded-Proto / -Scheme / -Ssl / -Port 443, X-Url-Scheme, Front-End-Https,
     *    CloudFront-Forwarded-Proto, Forwarded): a claim of https only ever stops a redirect, so even one nobody vouches
     *    for counts;
     *  - http: Cloudflare (its own address, or a local proxy passing Cloudflare's traffic on) says CF-Visitor scheme
     *    http; a proxy the owner listed by its address (isListedProxy()) says X-Forwarded-Proto http; or the request
     *    came straight from the visitor (plain http from an address on the internet, not a listed proxy, and no proxy
     *    header at all);
     *  - null: anything else, e.g. Cloudflare without CF-Visitor, a listed proxy that names no scheme, a loopback or
     *    private address (a proxy on the server), proxy headers from an address nobody listed.
     *
     * Not Request::isFromTrustedProxy(): a site's trusted proxies are '*' (whoever connects) unless the owner set a
     * list, so with it every visitor would count as a proxy, a direct http visit would never be redirected, and a local
     * proxy that passes on "X-Forwarded-Proto: http" behind a CDN that ended TLS would be believed (a loop).
     */
    public static function visitorScheme(?Request $request = null): ?string
    {
        if ($request === null) {
            if (app()->runningInConsole()) {
                return null;
            }

            try {
                $request = request();
            } catch (Throwable) {
                return null;
            }
        }

        if ($request->secure()) {
            return 'https';
        }

        $header = fn (string $name): string => strtolower(trim((string) $request->headers->get($name, '')));
        $first = fn (string $name): string => trim(explode(',', $header($name))[0]);
        $cloudflare = json_decode((string) $request->headers->get('CF-Visitor', ''), true);
        $visitor = is_array($cloudflare) && is_string($cloudflare['scheme'] ?? null) ? strtolower($cloudflare['scheme']) : null;

        if ($visitor === 'https'
            || in_array('https', [$first('X-Forwarded-Proto'), $first('X-Forwarded-Scheme'), $first('X-Url-Scheme'), $first('CloudFront-Forwarded-Proto')], true)
            || in_array('on', [$header('X-Forwarded-Ssl'), $header('Front-End-Https')], true)
            || $first('X-Forwarded-Port') === '443'
            || preg_match('/(?:^|[;,\s])proto\s*=\s*"?https"?(?:$|[;,\s])/', $header('Forwarded')) === 1) {
            return 'https';
        }

        // '' when REMOTE_ADDR is not an address (a socket, nothing): then nobody can tell who connected.
        $remote = AdminIpLock::normalize((string) $request->server->get('REMOTE_ADDR', ''));

        if (AdminIpLock::isCloudflare($remote) || (($request->headers->has('CF-Ray') || $request->headers->has('CF-Connecting-IP')) && AdminIpLock::isLocalProxy($remote))) {
            return $visitor === 'http' ? 'http' : null;
        }

        if (self::isListedProxy($remote)) {
            return $first('X-Forwarded-Proto') === 'http' ? 'http' : null;
        }

        if ($remote === '' || AdminIpLock::isLocalProxy($remote)) {
            return null;
        }

        foreach (self::PROXY_HEADERS as $name) {
            if ($request->headers->has($name)) {
                return null;
            }
        }

        return 'http';
    }

    /**
     * Whether the address is a proxy the owner listed by its address or range: the trusted proxies setting, or
     * SITE_TRUSTED_PROXIES / TRUSTED_PROXIES in .env, read as AdminIpLock reads them (commas or spaces between entries,
     * only valid addresses and ranges). A '*' (trust whoever connects, the value of a site that never set a list)
     * names nobody, so it vouches for no scheme.
     */
    private static function isListedProxy(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $text = static fn (mixed $value): string => is_array($value)
            ? implode(',', array_filter($value, 'is_string'))
            : (is_scalar($value) ? (string) $value : '');
        $raw = implode(',', array_map($text, [
            config('site.trusted_proxies', ''),
            RuntimeEnv::get('SITE_TRUSTED_PROXIES', ''),
            RuntimeEnv::get('TRUSTED_PROXIES', ''),
        ]));
        $listed = array_values(array_filter(AdminIpLock::entries($raw), AdminIpLock::isValidEntry(...)));

        return $listed !== [] && IpUtils::checkIp($ip, $listed);
    }

    /**
     * A full snapshot consumed by the preflight page and doctor command.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        return [
            'os' => [
                'family' => self::osFamily(),
                'is_windows' => self::isWindows(),
                'is_linux' => self::isLinux(),
                'is_mac' => self::isMac(),
                'is_bsd' => self::isBsd(),
                'uname' => function_exists('php_uname') ? php_uname() : 'unknown',
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => self::sapi(),
                'uses_fpm' => self::usesFpm(),
                'uses_lsapi' => self::usesLsapi(),
                'open_basedir' => self::openBasedirPaths(),
                'disabled_functions' => array_keys(self::disabledFunctions()),
                'can_run_processes' => self::canRunProcesses(),
                'can_run_background_popen' => self::canRunBackgroundPopen(),
                'limits' => self::phpLimitsReport(),
            ],
            'web' => [
                'server' => self::webServer(),
                'label' => self::webServerLabel(),
                'panel' => self::panel(),
                'panel_label' => self::panelLabel(),
                'is_https' => self::isSecureRequest(),
                'document_root_public' => self::isDocumentRootPublic(),
            ],
            'runtime' => [
                'ffmpeg' => self::ffmpegBinary(),
                'ffprobe' => self::ffprobeBinary(),
                'mysqldump' => self::mysqldumpBinary(),
                'redis_reachable' => self::redisReachable(),
                'writable' => self::writabilityMap(),
            ],
        ];
    }

    /**
     * True when the web server document root points to the public/ directory.
     *
     * When this returns false, the request is reaching the app through the
     * root fallback index.php — which works but is not optimal.
     */
    public static function isDocumentRootPublic(): bool
    {
        $docRoot = rtrim(str_replace('\\', '/', ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $publicPath = rtrim(str_replace('\\', '/', public_path()), '/');

        if ($docRoot === '' || $publicPath === '') {
            return false;
        }

        return $docRoot === $publicPath;
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function detectWebServer(): string
    {
        $sapi = self::sapi();

        if ($sapi === 'cli-server') {
            return self::WEB_CLI_SERVER;
        }

        if ($sapi === 'litespeed') {
            return self::WEB_LITESPEED;
        }

        $server = self::serverFingerprint();

        if ($server !== '') {
            if (str_contains($server, 'frankenphp')) {
                return self::WEB_FRANKENPHP;
            }
            if (str_contains($server, 'caddy')) {
                return self::WEB_CADDY;
            }
            if (str_contains($server, 'roadrunner')) {
                return self::WEB_ROADRUNNER;
            }
            if (str_contains($server, 'swoole')) {
                return self::WEB_OCTANE_SWOOLE;
            }
            if (str_contains($server, 'openlitespeed')) {
                return self::WEB_OPENLITESPEED;
            }
            if (str_contains($server, 'litespeed')) {
                return str_contains($server, 'openlite') ? self::WEB_OPENLITESPEED : self::WEB_LITESPEED;
            }
            if (str_contains($server, 'nginx')) {
                return self::WEB_NGINX;
            }
            if (str_contains($server, 'apache')) {
                return self::WEB_APACHE;
            }
            if (str_contains($server, 'iis') || str_contains($server, 'microsoft')) {
                return self::WEB_IIS;
            }
        }

        if (self::hasAnyServerKey(['HTTP_X_FRANKENPHP', 'FRANKENPHP_CONFIG', 'FRANKENPHP_WORKER'])) {
            return self::WEB_FRANKENPHP;
        }

        if (self::hasAnyServerKey(['RR_MODE', 'RR_RPC', 'GORIDGE_RPC'])) {
            return self::WEB_ROADRUNNER;
        }

        if (self::hasAnyServerKey(['OCTANE_SERVER', 'LARAVEL_OCTANE', 'SWOOLE_HTTP_HOST', 'SWOOLE_HTTP_PORT'])) {
            return self::WEB_OCTANE_SWOOLE;
        }

        if (self::hasAnyServerKey(['IIS_WasUrlRewritten', 'UNENCODED_URL', 'APPL_MD_PATH', 'APPL_PHYSICAL_PATH'])) {
            return self::WEB_IIS;
        }

        if (self::panel() === self::PANEL_ONEPANEL) {
            return self::WEB_NGINX;
        }

        if (self::panel() === self::PANEL_ONEPANEL) {
            return self::WEB_NGINX;
        }

        if (self::panel() === self::PANEL_ONEPANEL) {
            return self::WEB_NGINX;
        }

        if (function_exists('apache_get_version')) {
            return self::WEB_APACHE;
        }

        if ($sapi === 'cli') {
            return self::WEB_CLI;
        }

        return self::WEB_UNKNOWN;
    }

    private static function detectPanel(): string
    {
        $forced = strtolower(trim((string) RuntimeEnv::get('HOSTING_PANEL', RuntimeEnv::get('PANEL', ''))));
        $known = [
            self::PANEL_CPANEL,
            self::PANEL_PLESK,
            self::PANEL_DIRECTADMIN,
            self::PANEL_AAPANEL,
            self::PANEL_CYBERPANEL,
            self::PANEL_ISPCONFIG,
            self::PANEL_RUNCLOUD,
            self::PANEL_WEBMIN,
            self::PANEL_VIRTUALMIN,
            self::PANEL_WEBUZO,
            self::PANEL_HESTIACP,
            self::PANEL_VESTACP,
            self::PANEL_CLOUDPANEL,
            self::PANEL_FORGE,
            self::PANEL_PLOI,
            self::PANEL_SPINUPWP,
            self::PANEL_NONE,
        ];

        if (in_array($forced, $known, true)) {
            return $forced;
        }

        $checks = [
            self::PANEL_CPANEL => ['/usr/local/cpanel/version', '/etc/cpanel', '/var/cpanel/version'],
            self::PANEL_PLESK => ['/etc/plesk-release', '/usr/local/psa/version', '/opt/psa/version'],
            self::PANEL_DIRECTADMIN => ['/usr/local/directadmin/conf', '/usr/local/directadmin/directadmin'],
            self::PANEL_AAPANEL => ['/www/server/panel/BT-Panel', '/www/server/panel/class/panelSite.py', '/www/server/panel/config/config.json'],
            self::PANEL_CYBERPANEL => ['/usr/local/CyberCP', '/usr/local/lscp', '/etc/cyberpanel'],
            self::PANEL_ISPCONFIG => ['/usr/local/ispconfig/server', '/etc/ispconfig'],
            self::PANEL_RUNCLOUD => ['/etc/runcloud'],
            self::PANEL_WEBMIN => ['/etc/webmin/miniserv.conf'],
            self::PANEL_VIRTUALMIN => ['/etc/webmin/virtual-server/config'],
            self::PANEL_WEBUZO => ['/usr/local/webuzo'],
            self::PANEL_HESTIACP => ['/etc/hestiacp', '/usr/local/hestia'],
            self::PANEL_VESTACP => ['/etc/vesta', '/usr/local/vesta'],
            self::PANEL_CLOUDPANEL => ['/home/clp/htdocs'],
            self::PANEL_FORGE => ['/etc/forge'],
            self::PANEL_PLOI => ['/etc/ploi'],
            self::PANEL_SPINUPWP => ['/opt/spinupwp'],
        ];

        foreach ($checks as $panel => $paths) {
            foreach ($paths as $path) {
                if (@file_exists($path)) {
                    return $panel;
                }
            }
        }

        // Docroot heuristics for shared hosts where filesystem markers are not readable.
        $paths = self::serverPathFingerprint();

        if (str_contains($paths, '/usr/local/cpanel') || preg_match('#/home/[^/]+/public_html(?:/|$)#', $paths)) {
            return self::PANEL_CPANEL;
        }

        if (str_contains($paths, '/var/www/vhosts/') || str_contains($paths, '/plesk/') || str_contains($paths, '/psa/')) {
            return self::PANEL_PLESK;
        }

        if (str_contains($paths, '/usr/local/directadmin') || preg_match('#/home/[^/]+/domains/[^/]+/public_html(?:/|$)#', $paths)) {
            return self::PANEL_DIRECTADMIN;
        }

        if (str_contains($paths, '/www/wwwroot/') || str_contains($paths, '/www/server/panel/')) {
            return self::PANEL_AAPANEL;
        }

        if (str_contains($paths, '/usr/local/cybercp') || str_contains($paths, '/usr/local/lscp') || str_contains($paths, '/cyberpanel')) {
            return self::PANEL_CYBERPANEL;
        }

        if (str_contains($paths, '/usr/local/ispconfig') || str_contains($paths, '/etc/ispconfig')) {
            return self::PANEL_ISPCONFIG;
        }

        if (str_contains($paths, '/home/runcloud/webapps/')) {
            return self::PANEL_RUNCLOUD;
        }

        if (str_contains($paths, '/home/clp/htdocs/') || str_contains($paths, '/etc/cloudpanel')) {
            return self::PANEL_CLOUDPANEL;
        }

        if (str_contains($paths, '/home/forge/')) {
            return self::PANEL_FORGE;
        }

        if (str_contains($paths, '/home/ploi/')) {
            return self::PANEL_PLOI;
        }

        if (preg_match('#/home/[^/]+/web/[^/]+/public_html(?:/|$)#', $paths)) {
            return self::PANEL_HESTIACP;
        }

        if (str_contains($paths, '/opt/spinupwp') || str_contains($paths, '/spinupwp')) {
            return self::PANEL_SPINUPWP;
        }

        return self::PANEL_NONE;
    }

    private static function resolveBinary(string $name): string
    {
        $envKey = strtoupper($name) . '_BINARY';
        $legacyKey = strtoupper($name) . '_PATH';

        $explicit = trim((string) RuntimeEnv::get($envKey, ''));
        if ($explicit === '') {
            $explicit = trim((string) RuntimeEnv::get($legacyKey, ''));
        }

        if ($explicit !== '' && self::candidateExistsOrCannotBeInspected($explicit)) {
            return $explicit;
        }

        $path = self::whichBinary($name);
        if ($path !== '') {
            return $path;
        }

        if ($name === 'ffmpeg' || $name === 'ffprobe') {
            return MediaBinary::{$name}();
        }

        return '';
    }

    private static function whichBinary(string $name): string
    {
        if (! self::functionAvailable('exec')) {
            return '';
        }

        $command = self::isWindows() ? 'where' : 'command -v';
        $escaped = escapeshellarg($name);
        $stderr = self::isWindows() ? ' 2>NUL' : ' 2>/dev/null';
        $output = [];
        $status = 1;

        @exec($command . ' ' . $escaped . $stderr, $output, $status);

        if ($status === 0 && ! empty($output)) {
            foreach ($output as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '' && self::candidateExistsOrCannotBeInspected($trimmed)) {
                    return $trimmed;
                }
            }
        }

        return '';
    }

    private static function candidateExistsOrCannotBeInspected(string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        if (! self::looksLikeFilesystemPath($candidate)) {
            return true;
        }

        if (! self::canInspectFilesystemPath($candidate)) {
            return true;
        }

        return @is_file($candidate);
    }

    private static function canInspectFilesystemPath(string $path): bool
    {
        $openBasedir = self::openBasedirPaths();
        if ($openBasedir === []) {
            return true;
        }

        $normalizedPath = self::normalizeFilesystemPath($path);

        foreach ($openBasedir as $base) {
            $normalizedBase = self::normalizeFilesystemPath($base);
            if ($normalizedBase === '') {
                continue;
            }

            if ($normalizedPath === $normalizedBase || str_starts_with($normalizedPath, rtrim($normalizedBase, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeFilesystemPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($path === '.') {
            $path = getcwd() ?: $path;
        }

        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            $path = $real;
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function looksLikeFilesystemPath(string $candidate): bool
    {
        return str_contains($candidate, '/')
            || str_contains($candidate, '\\')
            || preg_match('/^[A-Za-z]:/', $candidate) === 1;
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private static function memo(string $key, callable $callback): mixed
    {
        if (self::$memo === null) {
            self::$memo = [];
        }

        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $callback();
        }

        return self::$memo[$key];
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private static function cachedProbe(string $key, callable $callback): mixed
    {
        if (self::$memo !== null && array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        try {
            $value = Cache::remember('env.probe.' . $key, self::PROBE_CACHE_TTL, $callback);
        } catch (Throwable) {
            $value = $callback();
        }

        if (self::$memo === null) {
            self::$memo = [];
        }

        self::$memo[$key] = $value;

        return $value;
    }

    private static function serverFingerprint(): string
    {
        $keys = [
            'SERVER_SOFTWARE',
            'X_POWERED_BY',
            'HTTP_X_POWERED_BY',
            'HTTP_SERVER',
            'OCTANE_SERVER',
            'FRANKENPHP_CONFIG',
            'RR_MODE',
            'SERVER_SIGNATURE',
        ];

        $values = [];
        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
            if (is_scalar($value) && (string) $value !== '') {
                $values[] = (string) $value;
            }
        }

        return strtolower(implode(' ', $values));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private static function hasAnyServerKey(array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private static function serverPathFingerprint(): string
    {
        $keys = [
            'DOCUMENT_ROOT',
            'SCRIPT_FILENAME',
            'PATH_TRANSLATED',
            'CONTEXT_DOCUMENT_ROOT',
            'HOME',
            'PWD',
            'USERPROFILE',
        ];

        $values = [];
        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
            if (is_scalar($value) && (string) $value !== '') {
                $values[] = str_replace('\\', '/', (string) $value);
            }
        }

        $values[] = str_replace('\\', '/', base_path());
        $values[] = str_replace('\\', '/', public_path());

        return strtolower(implode(' ', $values));
    }
}
