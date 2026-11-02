<?php

namespace App\Http\Controllers\Admin;

use App\Support\CleanUrls;
use App\Support\Environment;
use App\Support\EnvironmentRepairer;
use App\Support\RuntimeEnv;
use App\Support\SendFile;
use App\Support\ServerChecks;
use App\Support\ShellRunner;
use App\Support\StorageExposureProbe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Runtime diagnostics page for operators: environment snapshot, detected
 * web server / panel / OS, PHP ini vs recommended values, queue / cache
 * / redis reachability, streaming accelerator status, and the last set
 * of auto-adjustments applied by the {@see \App\Providers\EnvironmentServiceProvider}.
 */
class PreflightController
{
    /** The headings of the checks shown in the admin's language ("PHP" is a name and stays as it is). */
    public const CATEGORIES = ['PHP limits', 'Storage', 'Binaries', 'Runtime', 'Streaming', 'HTTP'];

    /**
     * The auto-configuration lines of EnvironmentServiceProvider that are whole sentences (with :name values given in
     * the adjustment's 'replace'): Tools > Health check shows them in the admin's language. scripts/i18n/sources.json
     * lists this constant, so the translation tools keep these keys; tests/Feature/AdminRound1TextsTest checks that
     * each one is still the provider's text.
     */
    public const AUTO_CONFIGURATION_MESSAGES = [
        'Trusted proxies: this server, private networks and Cloudflare (no list set).',
        'Applied trusted proxy list: :proxies',
        'Cookies are kept for this host only (session.domain was ":domain").',
        'Forced session.secure=true because the request / APP_URL is HTTPS.',
        'Replaced wildcard CORS origin with APP_URL to satisfy credentialed-CORS rules.',
        'Redis is not reachable. Temporarily switched redis-backed services to file/database drivers. Configure Redis or change these drivers to silence this warning.',
    ];

    /**
     * The results of App\Support\EnvironmentRepairer (Tools > Health check, "Auto-fix", and the folders it creates on
     * any request) that are whole sentences. They are stored in English (the session, the console of app:doctor) and
     * shown in the admin's language. scripts/i18n/sources.json lists this constant; tests/Feature/HealthCheckRepairLinesTest
     * checks that each one is still the repairer's text.
     */
    public const REPAIR_MESSAGES = [
        'storage/ or bootstrap/cache/ is not writable and exec() is disabled. Run the safe runtime-directory permission command shown by the installer.',
        'Set 777 permissions on runtime directories as fallback.',
        'Could not fix runtime directory ownership. Run the safe runtime-directory permission command shown by the installer.',
        'Core directory and file permissions normalized.',
        'APP_KEY is empty and .env could not be created from .env.example.',
        'APP_KEY is empty and no .env file exists.',
        'APP_KEY is empty but .env is not readable and writable.',
        'Generated missing APP_KEY in .env.',
        'Cleared stale cached config after generating APP_KEY.',
        'Wrote open_basedir=none to .user.ini to override aaPanel restriction. For a permanent fix, disable "Anti-XSS attack" in Website > Directory.',
    ];

    /**
     * The repairer's results that put a folder, a file or another value into their English text: recognised by their
     * words like AUTO_CONFIGURATION_LINES_WITH_A_VALUE, shown in the admin's language with each value isolated (a folder
     * reads left to right on an Arabic page). The file modes are values too, so a changed mode keeps the line translated.
     */
    public const REPAIR_LINES_WITH_A_VALUE = [
        'Fixed runtime directory ownership to :owner.',
        'Created missing directory: :folder',
        'Could not create directory: :folder',
        'Directory is not writable: :folder',
        'Standard file permissions applied (files: :files, directories: :directories, :script: :artisan, :envfile: :env).',
        'Created missing storage target: :folder',
        'Could not create storage target: :folder',
        'Cannot create :link because that path already exists and points somewhere else.',
        'Could not create parent directory for :link.',
        'Created storage link: :link -> :target',
        'Could not create :path. The host may block symlink creation; run `php artisan storage:link` with the web user.',
        'Could not generate APP_KEY: :error',
        'Wrote the nginx rules to :file and reloaded nginx.',
        'Wrote the nginx rules to :file; they apply after nginx is reloaded.',
        'nginx refused the rules, so the rewrite file was put back as it was: :file',
        'Cannot write to Nginx rewrite file: :file',
        'Cannot read the Nginx rewrite file: :file',
        'open_basedir is restricting PHP. Disable "Anti-XSS attack (open_basedir)" in aaPanel: Website > :site > Directory > Anti-XSS attack → OFF.',
    ];

    /**
     * The older auto-configuration lines that put a value into their English text (EnvironmentServiceProvider writes
     * them as they are, before the language is known): the Health check recognises each one by its wording, :name
     * standing for the value, and shows it in the admin's language with the value filled in
     * (autoConfigurationLine()). A line whose wording changed is shown as the provider wrote it. scripts/i18n/
     * sources.json lists this constant too; tests/Feature/AdminRound1TextsTest checks each against the provider's code.
     */
    public const AUTO_CONFIGURATION_LINES_WITH_A_VALUE = [
        'Trusted the current proxy IP from REMOTE_ADDR: :address',
        'Appended APP_URL host to sanctum.stateful: :host',
        "Selected ':accelerator' stream accelerator based on detected web server.",
        'Auto-set app.url from the current HTTP request: :url',
        'Auto-configuration failed: :error',
    ];

    /**
     * The step names above the lines of the Auto-fix and auto-configuration cards (EnvironmentRepairer and
     * EnvironmentServiceProvider name their steps with identifiers): those that are words, in the admin's language; the
     * name of a setting or a command (session.domain, storage.link, app.key, open_basedir ...) stays as it is, read left
     * to right (scopeHtml()). scripts/i18n/sources.json lists this constant.
     */
    public const SCOPE_LABELS = [
        'ownership' => 'Ownership',
        'permissions' => 'Permissions',
        'filesystem' => 'Filesystem',
        'runtime' => 'Runtime',
        'environment' => 'Environment',
        'trusted_proxies' => 'Trusted proxies',
        'streaming' => 'Streaming',
        'redis_fallback' => 'Redis fallback',
        'clean_urls' => 'Clean addresses',
    ];

    /**
     * One auto-configuration line (an entry of config('site.environment_adjustments')) in the admin's language with its
     * values filled in: a key with its 'replace' values, a whole sentence, or an older line with its value in the text.
     *
     * @param  array<string, mixed>  $adjustment
     */
    public static function autoConfigurationLine(array $adjustment): string
    {
        $resolved = self::keyAndValues($adjustment);

        return $resolved === null ? (string) ($adjustment['message'] ?? '') : __($resolved[0], $resolved[1]);
    }

    /**
     * A repairer line as Tools > Logs > Admin actions shows it (R3-019: Auto-fix's row): its translation key and the
     * values it carries, or null for a line no template knows (shown as it was written).
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function repairLine(string $message): ?array
    {
        return self::keyAndValues(['message' => $message]);
    }

    /** The step name above a line (SCOPE_LABELS): a word in the admin's language, an identifier as it is, left to right. */
    public static function scopeHtml(string $scope): HtmlString
    {
        return isset(self::SCOPE_LABELS[$scope])
            ? new HtmlString(e(__(self::SCOPE_LABELS[$scope])))
            : new HtmlString('<bdi dir="ltr">'.e($scope).'</bdi>');
    }

    /**
     * The same line as HTML for the Health check page: in the admin's language, each value isolated in <bdi> (a folder,
     * a file, an address or a mode left to right, so an Arabic sentence keeps its order; a date in its own direction).
     * A line nobody wrote a template for is shown as it was written.
     *
     * @param  array<string, mixed>  $line  an auto-configuration line or a repair result (scope, message, replace?)
     */
    public static function lineHtml(array $line): HtmlString
    {
        $resolved = self::keyAndValues($line);

        if ($resolved === null) {
            return new HtmlString(e((string) ($line['message'] ?? '')));
        }

        [$key, $values] = $resolved;
        $tokens = [];
        $index = 0;

        foreach (array_keys($values) as $name) {
            // A private-use mark the translator's :name, :Name and :NAME forms leave as they are.
            $tokens[$name] = "\u{E000}".($index++)."\u{E001}";
        }

        $html = e(__($key, $tokens));
        $index = 0;

        foreach ($values as $name => $value) {
            $isolated = $name === 'date' ? '<bdi>'.e($value).'</bdi>' : '<bdi dir="ltr">'.e($value).'</bdi>';
            $html = str_replace(e("\u{E000}".($index++)."\u{E001}"), $isolated, $html);
        }

        return new HtmlString($html);
    }

    /**
     * The English key of a line and its values: a key with its 'replace' values, a whole sentence, or a line with its
     * values in the text (AUTO_CONFIGURATION_LINES_WITH_A_VALUE, REPAIR_LINES_WITH_A_VALUE); null for any other text.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: string, 1: array<string, string>}|null
     */
    private static function keyAndValues(array $line): ?array
    {
        $message = (string) ($line['message'] ?? '');

        if (isset($line['replace']) || in_array($message, self::AUTO_CONFIGURATION_MESSAGES, true) || in_array($message, self::REPAIR_MESSAGES, true)) {
            $values = [];

            foreach ((array) ($line['replace'] ?? []) as $name => $value) {
                $value = is_scalar($value) ? (string) $value : '';
                // A check's time (CleanUrls, ServerChecks) is stored as a unix time: shown in the site's time zone.
                $values[(string) $name] = $name === 'date' && $value !== '' && ctype_digit($value) ? self::date((int) $value) : $value;
            }

            return [$message, $values];
        }

        foreach ([...self::AUTO_CONFIGURATION_LINES_WITH_A_VALUE, ...self::REPAIR_LINES_WITH_A_VALUE] as $template) {
            // 'Trusted ... REMOTE_ADDR: :address' -> the words as they are, :address -> (?<address>.+?)
            $pattern = '';
            foreach (preg_split('/(?<![A-Za-z0-9_]):([a-z_]+)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
                $pattern .= $i % 2 === 1 ? '(?<'.$part.'>.+?)' : preg_quote($part, '/');
            }

            if (preg_match('/^'.$pattern.'$/su', $message, $match) === 1) {
                return [$template, array_map('strval', array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY))];
            }
        }

        return null;
    }

    /** A check's time in the site's time zone and the admin's language ("Oct 27, 2026 7:10 PM"). */
    public static function date(int $timestamp): string
    {
        try {
            return Carbon::createFromTimestamp($timestamp)
                ->setTimezone((string) config('app.timezone', 'UTC'))
                ->locale(app()->getLocale())
                ->isoFormat('lll');
        } catch (Throwable) {
            return gmdate('Y-m-d H:i', $timestamp).' UTC';
        }
    }

    public function index(): View
    {
        $this->runServerChecks(false);

        return view('admin.preflight.index', [
            'report' => $this->buildReport(),
            // The same lines as HTML: the admin's language, each value isolated (lineHtml()).
            'adjustmentLines' => array_map(
                static fn ($adjustment): array => ['scope' => (string) (((array) $adjustment)['scope'] ?? ''), 'severity' => (string) (((array) $adjustment)['severity'] ?? 'info'), 'html' => self::lineHtml((array) $adjustment)],
                array_values((array) config('site.environment_adjustments', []))
            ),
        ]);
    }

    public function json(): JsonResponse
    {
        return response()->json($this->buildReport());
    }

    public function refresh(): \Illuminate\Http\RedirectResponse
    {
        $this->clearCachedProbes();
        Environment::reset();
        // "Re-probe" checks the web server again now (each try waits ServerChecks::TIMEOUT seconds at most), so the page
        // it leads to tells what links and videos really do.
        $this->runServerChecks(true);

        // This request's links were decided before the check: the way back takes the form this request came in by
        // (it reached the site), so a check that just found the rewrite rules gone never lands on nginx's 404 page.
        $path = '/'.ltrim((string) preg_replace('#^/index\.php(?=/|$)#', '', site_path((string) route('admin.preflight.index', [], false))), '/');
        $viaIndexPhp = CleanUrls::requestUsedIndexPhp();

        return redirect()->to(($viaIndexPhp ? '/index.php' : '').$path)
            ->withSuccess(__('Environment detection refreshed.'));
    }

    /**
     * On nginx: with $now, both checks of the web server run in this request (Re-probe); otherwise a check without an
     * answer runs after the page was sent (the page itself stays fast; the next visit shows the answer).
     */
    private function runServerChecks(bool $now): void
    {
        if (app()->runningInConsole() || Environment::webServer() !== Environment::WEB_NGINX) {
            return;
        }

        if (! $now) {
            foreach (ServerChecks::CHECKS as $check) {
                if (ServerChecks::entry($check) === null) {
                    ServerChecks::runSoon($check);
                }
            }

            return;
        }

        $bases = [(string) config('app.url')];

        try {
            $bases[] = request()->root();
        } catch (Throwable) {
        }

        foreach (ServerChecks::CHECKS as $check) {
            try {
                // An answer this try could not get (the site cannot reach itself just now) keeps the earlier one.
                ServerChecks::run($check, $bases);
            } catch (Throwable) {
            }
        }
    }

    public function fix(): \Illuminate\Http\RedirectResponse
    {
        $repairs = EnvironmentRepairer::repairInstallation();
        // A repair can change what the web server serves (the aaPanel rules): check the private files again.
        StorageExposureProbe::forget();
        $this->clearCachedProbes();
        Environment::reset();

        $fixed = count(array_filter($repairs, static fn ($repair) => ($repair['severity'] ?? null) === 'success'));
        $warnings = count(array_filter($repairs, static fn ($repair) => ($repair['severity'] ?? null) === 'warn'));

        // Tools > Logs > Admin actions (R3-019): what Auto-fix changed, one repairer line each, in English like the
        // session keeps them (App\Support\AuditDetails shows them in the reader's language: repairLine()). Lines that
        // only warn changed nothing; nothing fixed, nothing written.
        $done = array_values(array_filter(array_map(
            static fn ($repair): string => ($repair['severity'] ?? null) === 'success' ? trim((string) ($repair['message'] ?? '')) : '',
            $repairs
        )));

        if ($done !== []) {
            AuditLogController::log('tools.health.fixed', implode("\n", $done));
        }

        return redirect()->route('admin.preflight.index')
            ->with('preflight_repairs', $repairs)
            ->withSuccess(__('Auto-fix completed. Fixed: :fixed. Warnings: :warnings.', [
                'fixed' => $fixed,
                'warnings' => $warnings,
            ]));
    }

    private function clearCachedProbes(): void
    {
        try {
            Cache::deleteMultiple([
                'env.probe.redis.reachable',
                'env.probe.bin.ffmpeg',
                'env.probe.bin.ffprobe',
                'env.probe.bin.mysqldump',
            ]);
        } catch (Throwable) {
            // continue; Environment::reset() will still force in-process re-probes
        }

        // the PHP binary the imports and the scheduler start, kept since R3-077: looked for again
        \App\Support\PhpBinary::forget();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(): array
    {
        $snapshot = Environment::snapshot();

        $checks = $this->runChecks($snapshot);

        return [
            'snapshot' => $snapshot,
            'checks' => $checks,
            // Each line in the admin's language with its values filled in (EnvironmentServiceProvider runs before the
            // language is known: autoConfigurationLine()).
            'adjustments' => array_map(static function ($adjustment): array {
                $adjustment = (array) $adjustment;
                $adjustment['message'] = self::autoConfigurationLine($adjustment);
                unset($adjustment['replace']);

                return $adjustment;
            }, array_values((array) config('site.environment_adjustments', []))),
            'streaming' => [
                'mode' => SendFile::effectiveMode(),
                'prefix' => SendFile::internalPrefix(),
                // The card explains the accelerator of this web server (nginx, Apache, LiteSpeed or none) (R3-041).
                'server' => Environment::webServer(),
            ],
            'queue' => [
                'connection' => (string) config('queue.default', 'sync'),
                'avdb_mode' => (string) (RuntimeEnv::get('AVDB_IMPORT_MODE') ?: 'auto'),
            ],
            'counts' => [
                'ok' => count(array_filter($checks, static fn ($c) => $c['severity'] === 'ok')),
                'warn' => count(array_filter($checks, static fn ($c) => $c['severity'] === 'warn')),
                'danger' => count(array_filter($checks, static fn ($c) => $c['severity'] === 'danger')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{category:string,label:string,severity:string,detail:string}>
     */
    private function runChecks(array $snapshot): array
    {
        $checks = [];

        // PHP version
        $checks[] = [
            'category' => 'PHP',
            'label' => __('PHP version'),
            'severity' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'danger',
            'detail' => PHP_VERSION,
        ];

        // Shell exec
        $canRun = ShellRunner::available();
        $checks[] = [
            'category' => 'PHP',
            'label' => __('Subprocess support (proc_open)'),
            'severity' => $canRun ? 'ok' : 'warn',
            'detail' => $canRun
                ? __('Enabled — backups, ffmpeg, and AVDB inline spawn are all usable.')
                : __('Disabled — disable_functions blocks proc_open. AVDB imports will run through the queue; backups + ffmpeg will need an alternative.'),
        ];

        // open_basedir
        $basedir = Environment::openBasedirPaths();
        $checks[] = [
            'category' => 'PHP',
            'label' => 'open_basedir',
            'severity' => $basedir === [] ? 'ok' : 'warn',
            'detail' => $basedir === []
                ? __('Unrestricted.')
                : __('Restricted to: :paths', ['paths' => implode(', ', $basedir)]),
        ];

        // PHP limits
        foreach (Environment::phpLimitsReport() as $limit) {
            $checks[] = [
                'category' => 'PHP limits',
                'label' => $limit['key'],
                'severity' => $limit['severity'],
                'detail' => __('Current :current · Recommended :recommended', [
                    'current' => $limit['current'],
                    'recommended' => $limit['recommended'],
                ]),
            ];
        }

        // Storage writability
        foreach ((array) ($snapshot['runtime']['writable'] ?? []) as $path => $writable) {
            $checks[] = [
                'category' => 'Storage',
                'label' => $path,
                'severity' => $writable ? 'ok' : 'danger',
                'detail' => $writable ? __('Writable.') : __('NOT writable. The web server / FPM user must own this directory.'),
            ];
        }

        // FFmpeg
        $ffmpeg = (string) ($snapshot['runtime']['ffmpeg'] ?? '');
        $checks[] = [
            'category' => 'Binaries',
            'label' => 'ffmpeg',
            'severity' => $ffmpeg !== '' ? 'ok' : 'warn',
            'detail' => $ffmpeg !== ''
                ? $ffmpeg
                : __('Not found. Install ffmpeg via your panel / apt / yum, or set FFMPEG_BINARY.'),
        ];

        $ffprobe = (string) ($snapshot['runtime']['ffprobe'] ?? '');
        $checks[] = [
            'category' => 'Binaries',
            'label' => 'ffprobe',
            'severity' => $ffprobe !== '' ? 'ok' : 'warn',
            'detail' => $ffprobe !== ''
                ? $ffprobe
                : __('Not found. Thumbnail generation will fall back to remote posters.'),
        ];

        $mysqldump = (string) ($snapshot['runtime']['mysqldump'] ?? '');
        $checks[] = [
            'category' => 'Binaries',
            'label' => 'mysqldump / mariadb-dump',
            'severity' => $mysqldump !== '' ? 'ok' : 'warn',
            'detail' => $mysqldump !== ''
                ? $mysqldump
                : __('Not found. Database backups will be unavailable until this is installed or MYSQLDUMP_PATH is set.'),
        ];

        // Redis reachability
        $redisReachable = (bool) ($snapshot['runtime']['redis_reachable'] ?? false);
        $redisUsed = in_array('redis', [
            config('cache.default'),
            config('session.driver'),
            config('queue.default'),
        ], true);
        $checks[] = [
            'category' => 'Runtime',
            'label' => 'Redis',
            'severity' => $redisUsed ? ($redisReachable ? 'ok' : 'warn') : 'ok',
            'detail' => $redisUsed
                ? ($redisReachable ? __('Connected.') : __('Not reachable. Drivers were temporarily rerouted to file/database.'))
                : __('Not in use.'),
        ];

        // Database
        try {
            DB::connection()->getPdo();
            $checks[] = [
                'category' => 'Runtime',
                'label' => __('Database'),
                'severity' => 'ok',
                'detail' => sprintf(
                    '%s://%s:%s/%s',
                    (string) config('database.default'),
                    (string) config('database.connections.' . config('database.default') . '.host'),
                    (string) config('database.connections.' . config('database.default') . '.port'),
                    (string) config('database.connections.' . config('database.default') . '.database'),
                ),
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'category' => 'Runtime',
                'label' => __('Database'),
                'severity' => 'danger',
                'detail' => __('Connection failed: :err', ['err' => $e->getMessage()]),
            ];
        }

        // Streaming: who sends the video files stored on this server, as the site really does it (SendFile).
        $checks[] = ['category' => 'Streaming', 'label' => __('Accelerator')] + $this->streamingCheck();

        // Links: clean addresses or /index.php/... (App\Support\CleanUrls), and why.
        $checks[] = ['category' => 'HTTP', 'label' => __('Clean addresses')] + $this->cleanUrlsCheck();

        // HTTPS
        $checks[] = [
            'category' => 'HTTP',
            'label' => __('HTTPS'),
            'severity' => Environment::isSecureRequest() ? 'ok' : 'warn',
            'detail' => Environment::isSecureRequest()
                ? __('Secure — cookies hardened automatically.')
                : __('The request appears to be over HTTP. Enable HTTPS at the panel / web server.'),
        ];

        // Private files under storage/ (security plan S03): the answer of the daily check (App\Support\StorageExposureProbe).
        // When the site cannot reach itself the answer is unknown and nothing is listed.
        $exposure = StorageExposureProbe::cached();
        if ($exposure !== StorageExposureProbe::UNKNOWN) {
            $checks[] = [
                'category' => 'HTTP',
                'label' => __('Private files'),
                'severity' => $exposure === StorageExposureProbe::EXPOSED ? 'danger' : 'ok',
                'detail' => $exposure === StorageExposureProbe::EXPOSED
                    ? __('The web server hands out files from storage/ (logs with password-reset links, backups, invoices).').' '.StorageExposureProbe::fix()
                    : __('Private files are not reachable from the web'),
            ];
        }

        return $checks;
    }

    /**
     * The Streaming line: what really sends the video files stored on this server. nginx only after the site checked
     * its internal location (ServerChecks::NGINX_STREAM); a site without it streams with PHP and says how to add it.
     *
     * @return array{severity: string, detail: string}
     */
    private function streamingCheck(): array
    {
        $configured = SendFile::configuredMode();
        $mode = SendFile::effectiveMode();
        $guide = 'docs/server-configs/README.md';

        if ($configured === SendFile::MODE_NONE) {
            return ['severity' => 'ok', 'detail' => __('PHP streams the video files (STREAM_ACCELERATOR is none).')];
        }

        if (Environment::webServer() === Environment::WEB_NGINX && in_array($configured, [SendFile::MODE_AUTO, SendFile::MODE_NGINX], true)) {
            $entry = ServerChecks::entry(ServerChecks::NGINX_STREAM);
            $date = $entry !== null ? self::date($entry['checked_at']) : '';

            if ($mode === SendFile::MODE_NGINX && $entry !== null && $entry['answer'] === ServerChecks::YES) {
                return ['severity' => 'ok', 'detail' => __('nginx sends the video files (its internal location :prefix works; checked :date).', ['prefix' => SendFile::internalPrefix(), 'date' => $date])];
            }

            if ($mode === SendFile::MODE_NGINX) {
                // An explicit nginx that nobody could check yet.
                return ['severity' => 'warn', 'detail' => __('STREAM_ACCELERATOR is nginx, but the site could not check yet that nginx has the internal location :prefix. Without it no video stored on this server plays.', ['prefix' => SendFile::internalPrefix()])];
            }

            if ($entry !== null && $entry['answer'] === ServerChecks::NO) {
                return ['severity' => 'warn', 'detail' => __('PHP streams the video files: nginx has no internal location :prefix for them (checked :date). The location in :guide lets nginx send them, which is faster.', ['prefix' => SendFile::internalPrefix(), 'date' => $date, 'guide' => $guide])];
            }

            return ['severity' => 'warn', 'detail' => __('PHP streams the video files until the site has checked that nginx has the internal location :prefix.', ['prefix' => SendFile::internalPrefix()])];
        }

        return match ($mode) {
            SendFile::MODE_APACHE => ['severity' => 'ok', 'detail' => __('Apache sends the video files (X-Sendfile).')],
            SendFile::MODE_LITESPEED => ['severity' => 'ok', 'detail' => __('LiteSpeed sends the video files.')],
            SendFile::MODE_NGINX => ['severity' => 'ok', 'detail' => __('nginx sends the video files (STREAM_ACCELERATOR is nginx).')],
            default => ['severity' => 'ok', 'detail' => __('PHP streams the video files (this web server has no accelerator the site can use).')],
        };
    }

    /**
     * The Clean addresses line: what the site's links look like and why (App\Support\CleanUrls::REASONS), with the time
     * of the site's own check where one decided.
     *
     * @return array{severity: string, detail: string}
     */
    private function cleanUrlsCheck(): array
    {
        // Decided again: a check that ran since the request started (Re-probe) counts already. What the site's pages get,
        // not this page (the admin area opened through /index.php keeps that form whatever the check says).
        $reason = CleanUrls::decide(false)[1];
        $entry = ServerChecks::entry(ServerChecks::CLEAN_URLS);
        $text = CleanUrls::REASONS[$reason] ?? CleanUrls::REASONS['not_checked'];

        // Links with /index.php/... work everywhere (only an unknown answer is worth a warning).
        return [
            'severity' => $reason === 'not_checked' ? 'warn' : 'ok',
            'detail' => __($text, ['date' => $entry !== null ? self::date($entry['checked_at']) : '']),
        ];
    }
}
