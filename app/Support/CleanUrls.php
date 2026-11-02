<?php

namespace App\Support;

/**
 * Clean addresses (/trend) or /index.php/trend in every link the site makes (canonical, og:url, language alternates,
 * sitemaps, menus, forms, redirects). EnvironmentServiceProvider applies the decision to Laravel's URL generator and
 * locale_url() / locale_switch_url() follow it.
 *
 * APP_CLEAN_URLS (config app.clean_urls) is the owner's override: true (always clean), false (always /index.php), auto
 * (the default). With auto the site never gives a link that would end on the web server's 404 page:
 *  - nginx (and OpenResty, also behind a panel): clean only once the site has checked that a clean address reaches it
 *    with status 200 (App\Support\ServerChecks::CLEAN_URLS); /index.php/... when the check found they do not (a server
 *    without rewrite rules: the fleet's nodes 2, 4 and 7, which serve every page as /index.php/...). Before any check, as
 *    up to 1.2.2: /index.php/..., except on CloudPanel, Forge, Ploi and SpinupWP, whose nginx sites come with the rule.
 *  - every other server, as up to 1.2.2: clean (Apache and LiteSpeed with the shipped .htaccess, IIS with web.config,
 *    PHP's own server), and /index.php/... for a request that came in through /index.php, or through the root index.php
 *    on a server that does not read .htaccess.
 * A request that came in at a clean address while the answer is "no" makes the site check again soon (the owner may just
 * have added the rules); one that came in through /index.php while it is "yes" makes it check again too (the rules may be
 * gone since). On nginx a page of the admin area or the sign-in page opened through /index.php keeps /index.php in its
 * links and forms whatever the answer says (pages no search engine indexes): an administrator always has a way back
 * through /index.php/admin, Re-probe included, also while a "yes" is out of date (round 1b review BWR-01).
 *
 * Laravel builds some addresses from the request itself (request()->url(), fullUrl(), getRequestUri(): pagination
 * links, the page remembered for back(), the empty-parameter redirect, signed links), and public/index.php took /index.php
 * off before Laravel saw the request: requestForm() puts it back where the site needs it.
 */
final class CleanUrls
{
    public const AUTO = 'auto';

    public const ON = 'on';

    public const OFF = 'off';

    /**
     * Why the links are what they are, in plain words (Tools > Health check; the "auto-configuration" lines). :date is the
     * time of the site's check. scripts/i18n/sources.json lists this constant.
     */
    public const REASONS = [
        'setting_on' => 'Clean addresses: APP_CLEAN_URLS is true.',
        'setting_off' => 'Addresses with /index.php: APP_CLEAN_URLS is false.',
        'checked_yes' => 'Clean addresses: the site checked on :date that they reach it.',
        'checked_no' => 'Addresses with /index.php: when the site checked on :date, clean addresses did not reach it on this nginx server. The rewrite rules of docs/server-configs/ make them work.',
        'not_checked' => 'Addresses with /index.php until the site has checked that clean addresses reach it on this nginx server.',
        'via_index_php' => 'Addresses with /index.php: this request came in through /index.php.',
        'root_fallback' => 'Addresses with /index.php: the requests come in through the index.php of the project folder on a web server that reads no .htaccess.',
        'server_rewrites' => 'Clean addresses: this web server sends them to the site.',
    ];

    /** APP_CLEAN_URLS as one of AUTO, ON, OFF (true/false/1/0/on/off/yes/no, anything else: auto). */
    public static function setting(): string
    {
        $value = config('app.clean_urls', self::AUTO);

        if (is_bool($value)) {
            return $value ? self::ON : self::OFF;
        }

        $value = strtolower(trim((string) $value));

        return match (true) {
            in_array($value, ['1', 'true', 'on', 'yes', '(true)'], true) => self::ON,
            in_array($value, ['0', 'false', 'off', 'no', '(false)'], true) => self::OFF,
            default => self::AUTO,
        };
    }

    /** Whether this server is nginx (or OpenResty) and not Apache / LiteSpeed in front of PHP. */
    public static function onNginx(): bool
    {
        $software = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        if (self::apacheLike($software)) {
            return false;
        }

        return str_contains($software, 'nginx')
            || str_contains($software, 'openresty')
            || Environment::webServer() === Environment::WEB_NGINX;
    }

    /**
     * Whether this request's links use /index.php/..., and why (a key of REASONS). With $thisRequest false: what the
     * site's pages get in general, without the rules about the address this request came in by (Tools > Health check
     * describes the site, also when the administrator opened it through /index.php).
     *
     * @return array{0: bool, 1: string}
     */
    public static function decide(bool $thisRequest = true): array
    {
        $setting = self::setting();

        if ($setting === self::ON) {
            return [false, 'setting_on'];
        }

        if ($setting === self::OFF) {
            return [true, 'setting_off'];
        }

        if (self::consoleProcess()) {
            // The queue worker and the scheduler's commands (mails with links) have no web server: they use the web
            // server's answer for this site, so a member's mail never links an address that is nginx's 404 page. Without
            // one, clean as up to 1.2.2.
            return match (ServerChecks::webAnswerForThisSite(ServerChecks::CLEAN_URLS)) {
                ServerChecks::NO => [true, 'checked_no'],
                ServerChecks::YES => [false, 'checked_yes'],
                default => [false, 'server_rewrites'],
            };
        }

        $nginx = self::onNginx();

        if ($nginx) {
            if ($thisRequest && self::requestUsedIndexPhp() && self::keepsItsForm()) {
                // The admin area and the sign-in page keep the form their request came by: /index.php always reaches
                // the site, a clean address only while the rewrite rules are there.
                return [true, 'via_index_php'];
            }

            $answer = ServerChecks::answer(ServerChecks::CLEAN_URLS);

            if ($answer === ServerChecks::YES) {
                return [false, 'checked_yes'];
            }

            if ($answer === ServerChecks::NO) {
                return [true, 'checked_no'];
            }
        }

        // Not checked (yet), or not nginx: as up to 1.2.2.
        if ($thisRequest && self::cameThroughIndexPhp()) {
            return [true, 'via_index_php'];
        }

        $rootFallback = ! empty($_SERVER['AVDBCLIP_ROOT_FALLBACK']);

        if ($nginx && ($rootFallback || ! self::panelWritesTheRule())) {
            return [true, 'not_checked'];
        }

        if ($rootFallback && ! self::apacheLike(strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')))) {
            // The project folder's index.php on a server that reads no .htaccess (IIS without its web.config rules ...).
            return [true, 'root_fallback'];
        }

        return [false, 'server_rewrites'];
    }

    /** A console process: no web server in front of it (a web request always brings SERVER_SOFTWARE, also php -S). */
    public static function consoleProcess(): bool
    {
        return app()->runningInConsole() && trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')) === '';
    }

    /** Whether this request came in through /index.php (the root index.php's PATH_INFO, or an address it kept). */
    public static function cameThroughIndexPhp(): bool
    {
        return ! empty($_SERVER['AVDBCLIP_USED_INDEX_PHP'])
            || str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/index.php');
    }

    /**
     * Whether this request came in through /index.php in any layout: the root index.php's PATH_INFO
     * (AVDBCLIP_USED_INDEX_PHP), public/index.php that took /index.php off the address (AVDBCLIP_INDEX_PHP_PREFIX), or
     * an address that still starts with it. (cameThroughIndexPhp() is the narrower test of the 1.2.2 rules.)
     */
    public static function requestUsedIndexPhp(): bool
    {
        return self::cameThroughIndexPhp() || ! empty($_SERVER['AVDBCLIP_INDEX_PHP_PREFIX']);
    }

    /**
     * The pages that keep the form their request came by on nginx: the admin area and the sign-in page (search engines
     * index neither). The address public/index.php left (without /index.php and the language), inside the folder of a
     * site installed in one.
     */
    public static function keepsItsForm(): bool
    {
        $path = self::requestPath();
        $first = strtolower(explode('/', trim($path, '/'))[0] ?? '');

        return in_array($first, ['admin', 'login', 'logout'], true);
    }

    /**
     * An address of this site that Laravel built from the request itself (request()->url(), fullUrl(),
     * getRequestUri(), with or without the scheme and host), in a form that reaches the site: /index.php goes in after the
     * host when the site's links carry it or this request came in through it (public/index.php took it off). An address
     * that already has it, of another host, or on a site and request without it comes back unchanged.
     */
    public static function requestForm(string $url): string
    {
        if ($url === '' || ! ((function_exists('index_php_links') && index_php_links()) || self::requestUsedIndexPhp())) {
            return $url;
        }

        // A site in a sub folder (APP_URL https://example.com/site): /index.php goes in after that folder (the folder the
        // site's addresses start with: site_folder(), also while APP_URL is still a local one).
        $root = function_exists('site_folder') ? site_folder() : '';
        $root = $root !== '' ? $root : rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
                return $url;
            }

            return self::inSiteFolder($url, $root);
        }

        if (preg_match('#^(https?://[^/?\#]+)(.*)$#i', $url, $match) !== 1) {
            return $url;
        }

        $host = strtolower((string) parse_url($match[1], PHP_URL_HOST));
        $own = [strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))];

        try {
            $own[] = strtolower((string) request()->getHost());
        } catch (\Throwable) {
        }

        if ($host === '' || ! in_array($host, $own, true)) {
            return $url;
        }

        $rest = $match[2];

        if ($rest === '' || $rest[0] === '?' || $rest[0] === '#') {
            $rest = '/'.$rest;
        }

        return $match[1].self::inSiteFolder($rest, $root);
    }

    /** A path of this site (with its query) with /index.php after the site's folder ($root, '' at the domain's root). */
    private static function inSiteFolder(string $path, string $root): string
    {
        $inner = $path;

        if ($root !== '') {
            if (preg_match('#^'.preg_quote($root, '#').'(?=$|[/?\#])#', $path) !== 1) {
                // Not an address of the site's folder: as it is.
                return $path;
            }

            $inner = (string) substr($path, strlen($root));

            if ($inner === '' || $inner[0] !== '/') {
                $inner = '/'.$inner;
            }
        }

        return $root.(self::hasIndexPhp($inner) ? $inner : self::withIndexPhp($inner));
    }

    /** Whether a path (with a query or not) starts with /index.php as its own segment. */
    private static function hasIndexPhp(string $path): bool
    {
        return preg_match('#^/index\.php(?=$|[/?\#])#i', $path) === 1;
    }

    /** "/index.php" in front of a path that starts with "/"; the home page "/" (with a query or not) becomes "/index.php". */
    private static function withIndexPhp(string $path): string
    {
        return '/index.php'.(preg_match('#^/(?=$|[?\#])#', $path) === 1 ? substr($path, 1) : $path);
    }

    /**
     * Whether this request reached the site at a clean address that needs the server's rewrite rule: neither through
     * /index.php nor the bare home page (which reaches index.php on any server), and not one of the site's own checks.
     */
    public static function arrivedClean(): bool
    {
        if (self::cameThroughIndexPhp() || ! empty($_SERVER['AVDBCLIP_INDEX_PHP_PREFIX']) || ServerChecks::isCheckRequest()) {
            return false;
        }

        $path = self::requestPath();

        return ($path !== '' && $path !== '/') || ! empty($_SERVER['LOCALE_PREFIX']);
    }

    /**
     * The path of the address public/index.php handed over, without the folder of a site installed in one (R2R-05: the
     * home page of https://example.com/site is "/", its admin area "/admin/...").
     */
    private static function requestPath(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return function_exists('site_path') ? site_path($path) : $path;
    }

    private static function apacheLike(string $software): bool
    {
        return str_contains($software, 'apache')
            || str_contains($software, 'litespeed')
            || in_array(Environment::webServer(), [
                Environment::WEB_APACHE,
                Environment::WEB_LITESPEED,
                Environment::WEB_OPENLITESPEED,
            ], true);
    }

    /** The panels whose nginx sites come with the front controller's try_files rule. */
    private static function panelWritesTheRule(): bool
    {
        return in_array(Environment::panel(), [
            Environment::PANEL_CLOUDPANEL,
            Environment::PANEL_FORGE,
            Environment::PANEL_PLOI,
            Environment::PANEL_SPINUPWP,
        ], true);
    }
}
