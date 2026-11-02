<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What this site has checked about its own web server, by asking itself over HTTP:
 *
 *  - CLEAN_URLS: does a clean address (/_avdbcms/check/clean-urls/<name>, without /index.php) reach the application, and
 *    does its answer leave the web server with status 200? Only then does App\Support\CleanUrls give links without
 *    /index.php on nginx: a server without rewrite rules answers such an address with its own 404 page, and one that
 *    sends unknown addresses to PHP through an error page keeps the 404 status (search engines drop the page).
 *  - NGINX_STREAM: does nginx hand out a file named in an X-Accel-Redirect answer (the internal location /_internal/ of
 *    docs/server-configs/)? Only then does App\Support\SendFile let nginx send the video files stored on the server;
 *    otherwise PHP streams them. Without the location nginx sends the address back to PHP until it gives up (HTTP 500)
 *    or answers 404, so every such video failed (round 1b R1B-003).
 *
 * A check writes a file with a random name and a random body into the local disk's server-check/ folder (the folder of
 * the video files' disk, so the nginx location is checked with the same alias), asks the site for
 * /_avdbcms/check/<check>/<name> (App\Http\Controllers\ServerCheckController answers with the body, or with an
 * X-Accel-Redirect to the file), compares and deletes the file. The site's address is asked (APP_URL: never a Host
 * header a visitor chose; Tools > Health check's Re-probe also asks the address its page was opened at), over the
 * internet and, for a server that cannot reach its own public address, on this machine. YES: one answer with exactly the
 * body; NO: answers, none with the body; UNKNOWN: no answer at all.
 *
 * The answers are kept in storage/framework/server-checks.json, so a cache clear never flips the site's links, and they
 * hold for this server only (folder, host name, web server: a copy moved to another server checks again). A YES is
 * checked again after 10 minutes (a rule removed since, a vhost a panel wrote again: the links and the videos follow
 * within minutes, round 1b review BWR-01), a NO or UNKNOWN after an hour. A NO is also checked again when a request
 * reaches the site at a clean address (the owner just added the rules: at most every 5 minutes), a YES when a request
 * comes in through /index.php (somebody needed that form: at most every minute). An UNKNOWN never replaces an earlier
 * YES or NO (a site that cannot reach itself for a while keeps its links). A web request on nginx runs a due check after
 * its answer was sent, never in its way; Tools > Health check runs them when it has no answer and on "Re-probe".
 */
final class ServerChecks
{
    public const CLEAN_URLS = 'clean-urls';

    public const NGINX_STREAM = 'stream';

    public const CHECKS = [self::CLEAN_URLS, self::NGINX_STREAM];

    public const YES = 'yes';

    public const NO = 'no';

    public const UNKNOWN = 'unknown';

    /** The address the checks ask for: /_avdbcms/check/<check>/<name of the check file>. */
    public const PATH = '_avdbcms/check';

    /** The check files: <local disk>/server-check/<32 hex>.txt. */
    public const FOLDER = 'server-check';

    public const TIMEOUT = 3;

    public const KEEP_YES_SECONDS = 600;

    public const KEEP_OTHER_SECONDS = 3600;

    public const RECHECK_ON_CLEAN_ARRIVAL_SECONDS = 300;

    public const RECHECK_ON_INDEX_PHP_ARRIVAL_SECONDS = 60;

    /** Where the answers are kept (tests point it elsewhere); null: storage/framework/server-checks.json. */
    public static ?string $stateFile = null;

    /** The installer's lock (tests point it elsewhere); null: storage/app/installed.json. */
    public static ?string $installLock = null;

    /** Host names of this machine or of an intranet's own server, as an address of a site can name it. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'localhost.localdomain'];

    /** @var array<string, array<string, mixed>>|null the answers file, read once per process */
    private static ?array $state = null;

    /** @var array<string, true> checks this process already queued to run after its answer */
    private static array $queued = [];

    /** Forget what this process read (tests, and after a check wrote a new answer). */
    public static function reset(): void
    {
        self::$state = null;
        self::$queued = [];
    }

    /** The answer of a check for this server (YES, NO or UNKNOWN), null when it has none. */
    public static function answer(string $check): ?string
    {
        $entry = self::entry($check);

        return $entry['answer'] ?? null;
    }

    /**
     * The stored entry of a check for this server: answer, checked_at (unix time) and detail (why, in English, for the
     * logs), null when there is none or it was made on another server.
     *
     * @return array{answer: string, checked_at: int, detail: string}|null
     */
    public static function entry(string $check): ?array
    {
        $entry = self::state()[$check] ?? null;

        if (! is_array($entry) || ($entry['server'] ?? null) !== self::serverId()
            || ! in_array($entry['answer'] ?? null, [self::YES, self::NO, self::UNKNOWN], true)) {
            return null;
        }

        return [
            'answer' => (string) $entry['answer'],
            'checked_at' => (int) ($entry['checked_at'] ?? 0),
            'detail' => (string) ($entry['detail'] ?? ''),
        ];
    }

    /** Whether nginx hands out the video files (NGINX_STREAM): true, false, or null while nobody knows. */
    public static function nginxStreamWorks(): ?bool
    {
        return match (self::answer(self::NGINX_STREAM)) {
            self::YES => true,
            self::NO => false,
            default => null,
        };
    }

    /**
     * Whether the check should run again now (no answer, or an old one). $cleanArrival: this request reached the site at
     * a clean address (a NO may be out of date); $indexPhpArrival: it came in through /index.php (a YES may be).
     */
    public static function due(string $check, bool $cleanArrival = false, bool $indexPhpArrival = false): bool
    {
        $entry = self::entry($check);

        if ($entry === null) {
            return true;
        }

        $age = time() - $entry['checked_at'];

        if ($entry['answer'] === self::YES) {
            return $age >= ($indexPhpArrival ? self::RECHECK_ON_INDEX_PHP_ARRIVAL_SECONDS : self::KEEP_YES_SECONDS);
        }

        if ($cleanArrival && $entry['answer'] === self::NO) {
            return $age >= self::RECHECK_ON_CLEAN_ARRIVAL_SECONDS;
        }

        return $age >= self::KEEP_OTHER_SECONDS;
    }

    /**
     * Run the check after this request's answer was sent (once per process, one process at a time), when it is due.
     * Web requests only: a console process (the scheduler, tests) never starts one by itself.
     */
    public static function runSoon(string $check, bool $cleanArrival = false, bool $indexPhpArrival = false): void
    {
        if (isset(self::$queued[$check]) || ! in_array($check, self::CHECKS, true)) {
            return;
        }

        try {
            $base = self::siteAddress();

            if ($base === null || app()->runningInConsole() || self::isCheckRequest() || ! self::due($check, $cleanArrival, $indexPhpArrival)) {
                return;
            }

            self::$queued[$check] = true;

            app()->terminating(static function () use ($check, $base, $cleanArrival, $indexPhpArrival): void {
                $lock = 'avdbcms.server-check.running.'.$check;

                try {
                    // One process at a time. The lock is given back when the check is done; it only lasts its full
                    // minute (longer than two tries of TIMEOUT) when a process dies in the middle of a check.
                    if (! Cache::add($lock, 1, 60)) {
                        return;
                    }

                    try {
                        // Another process may have checked while this one answered its visitor: read the answers again.
                        self::$state = null;

                        if (self::due($check, $cleanArrival, $indexPhpArrival)) {
                            self::run($check, [$base]);
                        }
                    } finally {
                        Cache::forget($lock);
                    }
                } catch (Throwable) {
                    // A check is a courtesy: the links and the videos keep their safe default.
                }
            });
        } catch (Throwable) {
        }
    }

    /**
     * The site's own address for the checks a visit starts: APP_URL, never the Host header a visitor sends (that would
     * let anybody make the server ask another address). A local address (localhost, 127.0.0.1: a site on this machine
     * or an intranet's server) counts once the site is installed; before that it is the example's address of an
     * install in progress, and the checks start with the first visit after it. Round 3 R3-040: such a site never
     * checked by itself, so on nginx it kept /index.php addresses and PHP streaming for good (only Re-probe ran the
     * checks). Tools > Health check's Re-probe, an administrator's request, also asks the address that page was opened at.
     */
    public static function siteAddress(): ?string
    {
        $url = rtrim(trim((string) config('app.url')), '/');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (preg_match('#^https?://[^/\s]+#i', $url) !== 1 || $host === '' || (in_array($host, self::LOCAL_HOSTS, true) && ! self::installed())) {
            return null;
        }

        return $url;
    }

    /**
     * Whether the site is installed: the installer's lock (written when an installation ends) or APP_INSTALLED=true (a
     * site set up from the command line). Two cheap reads: a request on nginx asks it.
     */
    private static function installed(): bool
    {
        try {
            if (is_file(self::$installLock ?? storage_path('app'.DIRECTORY_SEPARATOR.'installed.json'))) {
                return true;
            }
        } catch (Throwable) {
        }

        return filter_var(RuntimeEnv::get('APP_INSTALLED', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Run a check now and keep its answer (an UNKNOWN keeps an earlier YES or NO, with the time of this try).
     *
     * @param  list<string>  $bases  site roots to ask; empty: APP_URL
     */
    public static function run(string $check, array $bases = []): string
    {
        if (! in_array($check, self::CHECKS, true)) {
            return self::UNKNOWN;
        }

        [$answer, $detail] = self::probe($check, $bases);
        self::record($check, $answer, $detail);

        // The answer kept: an UNKNOWN leaves an earlier YES or NO in place.
        return self::answer($check) ?? $answer;
    }

    /** Keep an answer for this server. An UNKNOWN never replaces an earlier YES or NO (only their time). */
    public static function record(string $check, string $answer, string $detail = ''): void
    {
        if (! in_array($check, self::CHECKS, true) || ! in_array($answer, [self::YES, self::NO, self::UNKNOWN], true)) {
            return;
        }

        $state = self::read();
        $previous = self::entry($check);

        if ($answer === self::UNKNOWN && $previous !== null && $previous['answer'] !== self::UNKNOWN) {
            $answer = $previous['answer'];
            $detail = $previous['detail'].' (the last try got no answer)';
        }

        $state[$check] = [
            'answer' => $answer,
            'checked_at' => time(),
            'detail' => mb_substr($detail, 0, 300),
            'server' => self::serverId(),
            'site' => self::siteId(),
        ];

        self::write($state);
    }

    /** Forget the answers (Tools > Health check, "Re-probe"): the next request or page checks again. */
    public static function forget(?string $check = null): void
    {
        $state = self::read();

        foreach ($check === null ? self::CHECKS : [$check] as $one) {
            unset($state[$one]);
        }

        self::write($state);
    }

    /** The check file of $name (32 hex characters) on the local disk, or null for any other name. */
    public static function file(string $name): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $name) !== 1) {
            return null;
        }

        try {
            return Storage::disk('local')->path(self::FOLDER.'/'.$name.'.txt');
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether this request is one of the checks' own (it never starts another check). */
    public static function isCheckRequest(): bool
    {
        try {
            $path = '/'.ltrim((string) request()->getPathInfo(), '/');
        } catch (Throwable) {
            return false;
        }

        return str_starts_with($path, '/'.self::PATH.'/');
    }

    /**
     * The address a check asks, below a site root: the clean one for CLEAN_URLS (the point of that check), the one with
     * /index.php for NGINX_STREAM (it reaches PHP with or without rewrite rules: only nginx's internal location counts).
     */
    public static function url(string $base, string $check, string $name): string
    {
        return rtrim($base, '/').($check === self::NGINX_STREAM ? '/index.php' : '').'/'.self::PATH.'/'.$check.'/'.$name;
    }

    /**
     * @param  list<string>  $bases
     * @return array{0: string, 1: string} the answer and what was seen
     */
    private static function probe(string $check, array $bases): array
    {
        $bases = array_values(array_unique(array_filter(array_map(
            static fn (mixed $base): string => rtrim(trim((string) $base), '/'),
            $bases === [] ? [(string) config('app.url')] : $bases
        ), static fn (string $base): bool => preg_match('#^https?://[^/\s]+#i', $base) === 1)));

        if ($bases === []) {
            return [self::UNKNOWN, 'no site address to ask'];
        }

        self::sweep();

        try {
            $name = bin2hex(random_bytes(16));
            $body = 'avdbcms-check-'.bin2hex(random_bytes(24));
        } catch (Throwable) {
            return [self::UNKNOWN, 'no random numbers'];
        }

        $file = self::file($name);

        if ($file === null || (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true)) || @file_put_contents($file, $body) === false) {
            return [self::UNKNOWN, 'the check file could not be written'];
        }

        $seen = [];
        $answered = false;

        try {
            foreach ($bases as $base) {
                foreach ([false, true] as $loopback) {
                    [$status, $matches] = self::ask(self::url($base, $check, $name), $body, $loopback);

                    if ($matches) {
                        return [self::YES, $base.($loopback ? ' (this machine)' : '').': '.$status];
                    }

                    if ($status !== null) {
                        $answered = true;
                        $seen[] = $base.($loopback ? ' (this machine)' : '').': HTTP '.$status;
                    }
                }
            }
        } finally {
            @unlink($file);
        }

        return $answered ? [self::NO, implode('; ', $seen)] : [self::UNKNOWN, 'the site did not answer itself'];
    }

    /**
     * One request: [HTTP status or null without an answer, whether the body is exactly the check's]. $loopback connects
     * to this machine (SERVER_ADDR, else 127.0.0.1) with the site's host name.
     *
     * @return array{0: int|null, 1: bool}
     */
    private static function ask(string $url, string $body, bool $loopback): array
    {
        $options = ['allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']], 'verify' => false];

        if ($loopback) {
            $host = (string) parse_url($url, PHP_URL_HOST);

            if ($host === '' || ! defined('CURLOPT_RESOLVE')) {
                return [null, false];
            }

            $port = (int) (parse_url($url, PHP_URL_PORT) ?: (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80));
            $server = '127.0.0.1';

            try {
                $server = (string) (request()->server('SERVER_ADDR') ?: '127.0.0.1');
            } catch (Throwable) {
            }

            $server = filter_var($server, FILTER_VALIDATE_IP) !== false ? $server : '127.0.0.1';
            $options['curl'] = [CURLOPT_RESOLVE => [trim($host, '[]').':'.$port.':'.(str_contains($server, ':') ? '['.$server.']' : $server)]];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => 'AVDBCMS server check', 'Accept' => '*/*', 'Cache-Control' => 'no-cache'])
                ->withOptions($options)
                ->get($url);
        } catch (Throwable) {
            return [null, false];
        }

        // A server error (maintenance mode, an overloaded PHP) says nothing about the rewrite rules or the location.
        if ($response->status() >= 500) {
            return [null, false];
        }

        return [$response->status(), $response->status() === 200 && trim($response->body()) === $body];
    }

    /** Check files a killed check left behind (older than 10 minutes) go. */
    private static function sweep(): void
    {
        try {
            $folder = Storage::disk('local')->path(self::FOLDER);

            foreach (glob($folder.'/*.txt') ?: [] as $old) {
                if (is_file($old) && time() - (int) @filemtime($old) > 600) {
                    @unlink($old);
                }
            }
        } catch (Throwable) {
        }
    }

    /** This server: the project folder, the machine's name and the web server (another one checks again). */
    private static function serverId(): string
    {
        return substr(hash('sha256', self::siteId().'|'.Environment::webServer()), 0, 16);
    }

    /** This site's folder on this machine, whatever process asks (a web request or a console process). */
    private static function siteId(): string
    {
        $host = '';

        try {
            $host = function_exists('gethostname') ? (string) @gethostname() : '';
        } catch (Throwable) {
        }

        return substr(hash('sha256', str_replace('\\', '/', base_path()).'|'.$host), 0, 16);
    }

    /**
     * The web server's YES or NO for this site, for a console process (the queue worker, the scheduler's commands): it
     * has no web server of its own, and the links it puts into mails must be the ones the web pages use. Null without
     * such an answer (an UNKNOWN counts for nothing either).
     */
    public static function webAnswerForThisSite(string $check): ?string
    {
        $entry = self::state()[$check] ?? null;

        if (! is_array($entry) || ($entry['site'] ?? null) !== self::siteId() || ! in_array($entry['answer'] ?? null, [self::YES, self::NO], true)) {
            return null;
        }

        return (string) $entry['answer'];
    }

    private static function path(): string
    {
        if (self::$stateFile !== null) {
            return self::$stateFile;
        }

        // The test suite never reads or writes a real site's answers.
        return app()->runningUnitTests()
            ? storage_path('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'server-checks.json')
            : storage_path('framework'.DIRECTORY_SEPARATOR.'server-checks.json');
    }

    /** @return array<string, mixed> */
    private static function state(): array
    {
        return self::$state ??= self::read();
    }

    /** @return array<string, mixed> */
    private static function read(): array
    {
        try {
            $raw = @file_get_contents(self::path());
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $state */
    private static function write(array $state): void
    {
        $path = self::path();

        try {
            $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

            if (@file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") !== false) {
                if (! @rename($temporary, $path)) {
                    // Windows cannot rename over an open file: write it in place instead.
                    @unlink($temporary);
                    @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
                }
            }
        } catch (Throwable) {
        }

        self::$state = $state;
    }
}
