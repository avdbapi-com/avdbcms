<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Can anybody download the files under storage/? On nginx with the site's web root at the project folder and only a
 * try_files rule (aaPanel's laravel5 preset, a 1Panel / CloudPanel / Hestia site without the include), nginx hands out
 * every existing file before PHP runs: mail and error logs, database backups, exports, invoices, copies of .env.
 * Apache and LiteSpeed (the root .htaccess) and IIS (web.config) never serve storage/, and neither does a web root at
 * public/.
 *
 * The probe puts a file with a random name and a random body in storage/logs, asks this site for
 * /storage/logs/<that name> over HTTP (the site's address, and the same address connected to this machine, each within
 * TIMEOUT seconds), compares what comes back and deletes the file. EXPOSED when any answer is a 200 with exactly that
 * body (only a server that reads this site's storage/ can send it). SAFE only when an answer without it came from THIS
 * site: after the file's requests, the same connection fetches a second random file through the site itself (its server
 * check address, App\Support\ServerChecks / ServerCheckController) and gets exactly its body back, within
 * PROOF_SECONDS. An answer of another server - a
 * panel's default site or another vhost at the installer's placeholder APP_URL http://localhost, a CDN's challenge page,
 * the default host of this machine - proves nothing (round 3 R3-008: such a 404 showed "Protected" on a server that hands
 * storage/ out). When the site cannot reach itself the answer is UNKNOWN, and nothing is shown then.
 */
final class StorageExposureProbe
{
    public const EXPOSED = 'exposed';

    public const SAFE = 'safe';

    public const UNKNOWN = 'unknown';

    public const TIMEOUT = 3;

    /**
     * The proof that an answer came from this site starts no request later than this many seconds after the requests
     * for the file (each request then lasts TIMEOUT at most): a server that answers the file's address at once but not
     * the site's own (a busy PHP pool) costs about two timeouts more, never one per address and connection.
     */
    public const PROOF_SECONDS = 4;

    /** The nginx include that closes it (docs/server-configs/, with the place for each panel inside). */
    public const NGINX_INCLUDE = 'docs/server-configs/nginx-project-root.conf';

    /** The last answer, for every language (Home, Health check): a day, an unknown one an hour. */
    public const CACHE_KEY = 'avdbcms.storage-exposure';

    public const KEEP_SECONDS = 86400;

    public const KEEP_UNKNOWN_SECONDS = 3600;

    /**
     * @param  (\Closure(): float)|null  $clock  seconds now (a test's clock; microtime(true) else)
     */
    public function __construct(private readonly ?\Closure $clock = null)
    {
    }

    /** Forget the last answer (after a repair): the next page that needs it checks again. */
    public static function forget(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
            \App\Http\Controllers\Admin\StatusProbeController::forget('storage');
        } catch (Throwable) {
        }
    }

    /** The last answer, or a new one when there is none (or $fresh). Never throws. */
    public static function cached(bool $fresh = false): string
    {
        $store = null;

        try {
            $store = \Illuminate\Support\Facades\Cache::store();
            $known = $fresh ? null : $store->get(self::CACHE_KEY);

            if (in_array($known, [self::EXPOSED, self::SAFE, self::UNKNOWN], true)) {
                return $known;
            }
        } catch (Throwable) {
            // No cache: ask now, keep nothing.
        }

        $bases = [(string) config('app.url')];

        if (! app()->runningInConsole()) {
            try {
                $bases[] = request()->root();
            } catch (Throwable) {
            }

            // Once a day, from a page: files an older release left under storage/ (or wrote again after an Undo) get
            // their hidden names first. Never for a view-only account (its database writes are rolled back).
            try {
                if (! \App\Http\Middleware\ViewOnlyDemo::isViewOnlyUser(auth()->user())) {
                    StorageHider::run();
                }
            } catch (Throwable) {
            }
        }

        $answer = (new self())->probe($bases);

        try {
            $store?->put(self::CACHE_KEY, $answer, $answer === self::UNKNOWN ? self::KEEP_UNKNOWN_SECONDS : self::KEEP_SECONDS);
        } catch (Throwable) {
        }

        return $answer;
    }

    /**
     * @param  list<string>  $bases  site roots to ask (e.g. APP_URL and the current request's root); empty: APP_URL
     */
    public function probe(array $bases = []): string
    {
        $bases = array_values(array_unique(array_filter(array_map(
            static fn (mixed $base): string => rtrim(trim((string) $base), '/'),
            $bases === [] ? [(string) config('app.url')] : $bases
        ), static fn (string $base): bool => preg_match('#^https?://[^/\s]+#i', $base) === 1)));

        if ($bases === []) {
            return self::UNKNOWN;
        }

        try {
            $name = 'exposure-check-'.bin2hex(random_bytes(12)).'.txt';
            $body = bin2hex(random_bytes(24));
        } catch (Throwable) {
            return self::UNKNOWN;
        }

        $file = storage_path('logs'.DIRECTORY_SEPARATOR.$name);

        if (@file_put_contents($file, $body) === false) {
            return self::UNKNOWN;
        }

        // The proof that an answer came from this site: a second random file that only this site's own server check
        // address hands out (made here, deleted at the end; none when it cannot be written: then nothing is SAFE).
        $control = $this->writeControlFile();

        // Every address is asked (APP_URL may name another server than the one serving this page), each over the
        // internet and on this machine: a CDN or firewall in front may answer the first itself (a "checking your
        // browser" page, a redirect) while the server behind it hands the file out. One answer with the file is enough
        // for EXPOSED. SAFE needs an answer without it on a connection that reached this very site: proven after all the
        // file's requests (so the proof never delays them) and within PROOF_SECONDS; else UNKNOWN.
        try {
            $answered = [];

            foreach ($bases as $base) {
                foreach ([false, true] as $loopback) {
                    $answer = $this->fetch($base.'/storage/logs/'.$name, $loopback);

                    if ($answer === null) {
                        continue;
                    }

                    if ($answer['status'] === 200 && trim($answer['body']) === $body) {
                        return self::EXPOSED;
                    }

                    $answered[] = [$base, $loopback];
                }
            }

            if ($control === null) {
                return self::UNKNOWN;
            }

            $deadline = $this->now() + self::PROOF_SECONDS;

            foreach ($answered as [$base, $loopback]) {
                if ($this->now() >= $deadline) {
                    break;
                }

                if ($this->reachesThisSite($base, $loopback, $control, $deadline)) {
                    return self::SAFE;
                }
            }

            return self::UNKNOWN;
        } finally {
            @unlink($file);

            if ($control !== null) {
                @unlink($control['file']);
            }
        }
    }

    /**
     * A file for the site's own server check address (/_avdbcms/check/clean-urls/<32 hex>, answered by
     * App\Http\Controllers\ServerCheckController with the file's body): ['name', 'body', 'file'], or null when it cannot
     * be written.
     *
     * @return array{name: string, body: string, file: string}|null
     */
    private function writeControlFile(): ?array
    {
        try {
            $name = bin2hex(random_bytes(16));
            $body = 'avdbcms-storage-check-'.bin2hex(random_bytes(24));
            $file = ServerChecks::file($name);

            if ($file === null || (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true)) || @file_put_contents($file, $body) === false) {
                return null;
            }

            return ['name' => $name, 'body' => $body, 'file' => $file];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the connection to $base (on this machine when $loopback) reaches this very site: its server check address
     * answers with the control file's body - the clean address, else the one with /index.php (a server without rewrite
     * rules answers the clean one with its own 404). No answer at all to the clean address ends it (the same server
     * would not answer the other one either), and so does $deadline.
     *
     * @param  array{name: string, body: string, file: string}  $control
     */
    private function reachesThisSite(string $base, bool $loopback, array $control, float $deadline): bool
    {
        foreach (['', '/index.php'] as $prefix) {
            if ($prefix !== '' && $this->now() >= $deadline) {
                return false;
            }

            $answer = $this->fetch($base.$prefix.'/'.ServerChecks::PATH.'/'.ServerChecks::CLEAN_URLS.'/'.$control['name'], $loopback);

            if ($answer === null) {
                return false;
            }

            if ($answer['status'] === 200 && trim($answer['body']) === $control['body']) {
                return true;
            }
        }

        return false;
    }

    /** Seconds now (the test's clock, else the system's). */
    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    /**
     * One GET without redirects: ['status', 'body'], or null without an answer. $loopback connects to this machine
     * (SERVER_ADDR, else 127.0.0.1) with the site's host name, for a server that cannot reach its own public address.
     *
     * @return array{status: int, body: string}|null
     */
    private function fetch(string $url, bool $loopback): ?array
    {
        $options = ['allow_redirects' => false, 'verify' => false];

        if ($loopback) {
            $host = (string) parse_url($url, PHP_URL_HOST);

            if ($host === '' || ! defined('CURLOPT_RESOLVE')) {
                return null;
            }

            $port = (int) (parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80));
            $server = (string) (request()->server('SERVER_ADDR') ?: '127.0.0.1');
            $server = filter_var($server, FILTER_VALIDATE_IP) !== false ? $server : '127.0.0.1';
            $options['curl'] = [CURLOPT_RESOLVE => [trim($host, '[]').':'.$port.':'.(str_contains($server, ':') ? '['.$server.']' : $server)]];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'AVDBCMS storage check', 'Accept' => '*/*', 'Cache-Control' => 'no-cache'])
                ->withOptions($options)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        return ['status' => $response->status(), 'body' => (string) $response->body()];
    }

    /**
     * What to change, for this panel: the web root at <project>/public, or the nginx include. One plain sentence or
     * two, for the installer, Preflight and Home.
     */
    public static function fix(): string
    {
        $public = str_replace('\\', '/', base_path('public'));

        $panel = match (Environment::panel()) {
            Environment::PANEL_AAPANEL => __('aaPanel: Website > the site > Conf > Site directory > Running directory: /public.'),
            Environment::PANEL_CLOUDPANEL => __('CloudPanel: Sites > the site > Settings > Root Directory: add /public.'),
            Environment::PANEL_HESTIACP => __('HestiaCP: Web > edit the site > Advanced Options > Custom document root: the public folder.'),
            Environment::PANEL_ONEPANEL => __('1Panel: Websites > the site > Site directory > Running directory: /public.'),
            default => null,
        };

        return trim(__('Set the website folder to :public in your hosting panel, or add the rules of :include to the site\'s nginx settings (the file says where for each panel).', [
            'public' => $public,
            'include' => self::NGINX_INCLUDE,
        ]).($panel !== null ? ' '.$panel : ''));
    }
}
