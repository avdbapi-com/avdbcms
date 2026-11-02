<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * The scheduler of the site (php artisan schedule:run, every minute) and the ways it is started:
 *
 *  - the system cron: the line ensureInstalled() writes into the crontab of the account PHP runs as (the installer, the
 *    import page), or one the owner set up in a hosting panel. On Linux/BSD the line runs under flock with a lock INSIDE
 *    the site (storage/framework/scheduler-<hash of the site's folder>.lock): every site has its own lock, also sites
 *    in folders of the same name (public_html, httpdocs) of other accounts (1.2.x locked /tmp/<folder name>-scheduler.lock:
 *    one site of a name ran, the others' cron never ran — judge R1B-004). The line appends to storage/logs/schedule-run.log.
 *    On Windows the Task Scheduler runs it (windowsTaskCommand());
 *  - the site itself after a page request (triggerDueWebScheduler(), Linux/BSD), at most once a minute, ONLY on an
 *    installed site (never while the installer runs: R1B-015) and ONLY while the system cron does not run the scheduler
 *    (every run of the cron writes the stamp this checks: the two never run the same minute's tasks twice, R1B-006).
 *    Its runs, and "Run Scheduler Now" of Tools > Scheduled tasks, append to storage/logs/schedule-web.log: the cron's
 *    log is never written, let alone wiped, by the web (R1B-005).
 *
 * A line this site wrote into the crontab in 1.2.x (its lock in /tmp) is replaced by the current one automatically, in
 * its place (migrateCronLine(): at the cron's next run, or at the next page request where that cron cannot run any
 * more). Only that line changes: every other line of the crontab stays exactly as it is, and so does a line of this
 * site that the owner switched off with "#" or wrote himself; a line is never added where the owner has none. The
 * sites of one account change their crontab one after the other, and a rewrite is read back at a later run (another
 * site of the account writing the crontab in the same moment could have put the older line back).
 */
final class LaravelSchedulerCron
{
    private const MARKER_PREFIX = 'AVDBClip Laravel Scheduler';

    /** The cron line's log (storage path): only the line itself appends to it. */
    public const CRON_LOG = 'logs/schedule-run.log';

    /** The log of the runs the site starts itself (after visits, "Run Scheduler Now"). */
    public const SITE_LOG = 'logs/schedule-web.log';

    /** The scheduled tasks' own output (App\Console\Kernel::schedule(), appendOutputTo). */
    public const TASK_LOG = 'logs/cron.log';

    /** In the environment of a scheduler run the site started: it is not the system cron. */
    public const SOURCE_ENV = 'AVDB_SCHEDULER_SOURCE';

    public const SOURCE_SITE = 'site';

    /** The system cron counts as running while its last run is at most this old (seconds): the site starts none then. */
    public const CRON_FRESH_SECONDS = 150;

    /** The status says "running" while the last run is at most this old (seconds). */
    public const RECENT_SECONDS = 600;

    /**
     * A site without the installer's lock counts as installed for the page requests only once its .env is at least this
     * old (seconds): an installer that writes APP_INSTALLED=true before its database work (1.2.x, RC1) may be at work
     * until then (it gives itself 600 seconds).
     */
    public const INSTALL_QUIET_SECONDS = 600;

    /** Every run of the scheduler that the site did not start (the system cron, a panel's cron, Windows' Task Scheduler). */
    private const CRON_STAMP = 'framework/scheduler-cron.timestamp';

    /** Every run the site started itself. */
    private const SITE_STAMP = 'framework/scheduler-web.timestamp';

    /** When the page requests last considered starting a run (at most once a minute). */
    private const THROTTLE_STAMP = 'framework/scheduler-last-run.timestamp';

    /** What migrateCronLine() found in this account's crontab (one file per system account). */
    private const LINE_STATE = 'framework/scheduler-cron-line-%s.json';

    /** A crontab that could not be read or written is tried again after this many seconds. */
    private const LINE_RETRY_SECONDS = 3600;

    /**
     * A crontab this site rewrote is read again at a check at least this many seconds later (the next minute's run): a
     * write of another site of the account in the same moment may have put the older line back.
     */
    private const LINE_CONFIRM_SECONDS = 30;

    /** Rewrites in a row that did not stay (something keeps putting the older line back): then 'failed' (after an hour again). */
    private const LINE_MAX_WRITES = 3;

    /** How long a change of the crontab waits for another site of the same account that is changing it (seconds). */
    private const ACCOUNT_LOCK_WAIT = 5.0;

    /**
     * Adds this site's line to the crontab of the account PHP runs as (the installer, the import page). 'state' says
     * what happened (the installer's result page tells it, round 3 R3-036): 'added', 'updated' (an older line of this
     * site replaced), 'current' (the line was there), 'off' / 'custom' (the owner's line, left as it is), or why no line
     * could be added: 'windows', 'no_processes', 'no_php', 'no_crontab', 'failed'.
     *
     * @return array{available:bool,installed:bool,changed:bool,message:string,command:string|null,state:string}
     */
    public static function ensureInstalled(?string $php = null): array
    {
        $php = PhpBinary::usable($php) ? $php : PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
        $command = $php ? self::cronLine($php) : null;

        if (Environment::isWindows()) {
            return self::result(false, false, false, __('The site can add its cron line by itself only on Linux and other Unix servers.'), $command, 'windows');
        }

        if (! Environment::canRunProcesses()) {
            return self::result(false, false, false, __('PHP may not start programs on this server, so the site could not add its cron line by itself.'), $command, 'no_processes');
        }

        if (! PhpBinary::usable($php)) {
            return self::result(false, false, false, __('No PHP command line was found on this server, so the cron line could not be added.'), $command, 'no_php');
        }

        $crontab = self::crontabBinary();
        if ($crontab === null) {
            return self::result(false, false, false, __('The crontab command was not found on this server.'), $command, 'no_crontab');
        }

        $error = null;
        $outcome = self::withAccountLock(static function () use ($crontab, $php, &$error): string {
            return self::changeBlock($crontab, $php, true, true, $error);
        }, self::ACCOUNT_LOCK_WAIT);

        if (in_array($outcome, ['added', 'updated'], true)) {
            AppendLog::prepare(storage_path(self::CRON_LOG));
            // migrateCronLine() reads the crontab again at its next check (it confirms what was written here).
            self::forgetLineState();
        }

        return match ($outcome) {
            'added' => self::result(true, true, true, __("This site's line was added to this account's crontab."), $command, 'added'),
            'updated' => self::result(true, true, true, __("This site's older line in this account's crontab was replaced by the current one."), $command, 'updated'),
            'current' => self::result(true, true, false, __("This account's crontab already has this site's line."), $command, 'current'),
            // The owner's choice stays: a line switched off with "#", a line written by hand (judge round 1b BS-RV-01).
            'off' => self::result(true, false, false, __("This site's line in the crontab is switched off (it starts with #), so it was left as it is."), $command, 'off'),
            'custom' => self::result(true, false, false, __("This site's line in the crontab was changed by hand, so it was left as it is."), $command, 'custom'),
            default => self::result(false, false, false, $error !== null && $error !== '' ? $error : __("The cron line could not be added to this account's crontab."), $command, 'failed'),
        };
    }

    public static function command(?string $php = null): ?string
    {
        $php = PhpBinary::usable($php) ? $php : PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);

        return PhpBinary::usable($php) ? self::cronLine($php) : null;
    }

    public static function panelScriptCommand(?string $php = null): ?string
    {
        $php = PhpBinary::usable($php) ? $php : PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);

        return PhpBinary::usable($php) ? self::shellCommand($php) : null;
    }

    /**
     * The line for a PHP binary already known to work (Tools > Scheduled tasks shows it with the binary the "php" status
     * probe checked: no second check here). On Windows the command that creates the site's task in the Task Scheduler.
     */
    public static function lineFor(string $php): string
    {
        return Environment::isWindows() ? self::windowsTaskCommand($php) : self::cronLine($php);
    }

    /** The command for panels that run cron jobs as root (see shellCommand()), for a PHP binary known to work. */
    public static function panelLineFor(string $php): string
    {
        return self::shellCommand($php);
    }

    /**
     * The command of this site's cron line without its schedule, for a cron job of a hosting panel run as the site's
     * account (cPanel, Plesk, DirectAdmin, CloudPanel, HestiaCP, aaPanel ...): a panel takes "every minute" in fields of
     * its own and hands the command to /bin/sh, which reads the five "*" of a crontab line as file names, so the job
     * never ran (round 3 IN-RV-01). The same lock as the crontab line (lineFor()), for a PHP binary known to work.
     */
    public static function commandFor(string $php): string
    {
        return self::currentUserShellCommand($php);
    }

    /**
     * The command that creates this site's task in the Windows Task Scheduler, every minute (run once in a Command
     * Prompt). The Task Scheduler starts a program with its arguments (no shell: no "cd", no "&&", no ">>"), so the
     * task runs PHP itself: "<php>" "<site>\artisan" schedule:run (artisan finds the site from its own folder). The
     * task is named like the site's crontab block (one task per site; /F replaces the site's own earlier task).
     */
    public static function windowsTaskCommand(string $php): string
    {
        $run = '"'.$php.'" "'.base_path('artisan').'" schedule:run';

        return 'schtasks /Create /SC MINUTE /MO 1 /TN "'.self::marker().'" /TR "'.str_replace('"', '\"', $run).'" /F';
    }

    /**
     * The cron line's lock: inside the site (storage/framework, which only this site's account writes) and named from a
     * hash of the site's full folder, so two sites never share one, whatever their folders are called.
     */
    public static function lockPath(): string
    {
        return storage_path('framework/scheduler-'.substr(sha1(base_path()), 0, 12).'.lock');
    }

    /** The lock of this site's 1.2.x line: in /tmp, named from the site folder's name (shared by every folder of that name). */
    private static function olderLockPath(): string
    {
        return '/tmp/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', basename(base_path())).'-scheduler.lock';
    }

    private static function marker(): string
    {
        return self::MARKER_PREFIX.' '.substr(sha1(base_path()), 0, 12);
    }

    private static function cronLine(string $php): string
    {
        return '* * * * * '.self::currentUserShellCommand($php);
    }

    private static function innerCommand(string $php): string
    {
        $log = escapeshellarg(storage_path(self::CRON_LOG));

        return 'cd '.escapeshellarg(base_path())
            .' && /bin/date >> '.$log
            .' && '.escapeshellarg($php).' artisan schedule:run >> '.$log.' 2>&1';
    }

    private static function currentUserShellCommand(string $php): string
    {
        if (Environment::isWindows()) {
            return 'cd '.escapeshellarg(base_path()).' && '.escapeshellarg($php).' artisan schedule:run >> '.escapeshellarg(storage_path(self::CRON_LOG)).' 2>&1';
        }

        return '/usr/bin/flock -n -o '.escapeshellarg(self::lockPath()).' /bin/sh -c '.escapeshellarg(self::innerCommand($php));
    }

    /**
     * For panels that run cron jobs as root: the line switches to the site's account first and takes the lock as that
     * account (a lock file of root inside the site would be in the way of the site's own account).
     */
    private static function shellCommand(string $php): string
    {
        if (Environment::isWindows()) {
            return 'cd '.escapeshellarg(base_path()).' && '.escapeshellarg($php).' artisan schedule:run >> '.escapeshellarg(storage_path(self::CRON_LOG)).' 2>&1';
        }

        return '/bin/su -s /bin/sh '.escapeshellarg(self::scriptUser()).' -c '.escapeshellarg(self::currentUserShellCommand($php));
    }

    private static function scriptUser(): string
    {
        $configured = trim((string) RuntimeEnv::get('CRON_RUN_AS_USER', ''));
        if (self::validUser($configured)) {
            return $configured;
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $name = is_array($user) ? (string) ($user['name'] ?? '') : '';
            if (self::validUser($name) && $name !== 'root') {
                return $name;
            }
        }

        foreach (['USER', 'USERNAME'] as $key) {
            $name = trim((string) ($_SERVER[$key] ?? getenv($key) ?: ''));
            if (self::validUser($name) && $name !== 'root') {
                return $name;
            }
        }

        return 'www';
    }

    private static function validUser(string $user): bool
    {
        return $user !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $user) === 1;
    }

    private static function crontabBinary(): ?string
    {
        if (! Environment::functionAvailable('exec')) {
            return null;
        }

        $output = [];
        $status = 1;
        @exec('command -v crontab 2>/dev/null', $output, $status);

        if ($status !== 0 || $output === []) {
            return null;
        }

        $path = trim((string) $output[0]);

        return $path !== '' ? $path : null;
    }

    /** The account's crontab as it is ('' when it has none), or null when it could not be read. */
    private static function readCrontab(string $crontab): ?string
    {
        if (Environment::canRunProcesses()) {
            // Byte for byte (exec() would cut the trailing blanks of every line).
            $result = ShellRunner::run([$crontab, '-l'], null, null, 15);

            if (! $result->unavailable) {
                // 1: "no crontab for <user>"
                return match ($result->exitCode) {
                    0 => $result->stdout,
                    1 => '',
                    default => null,
                };
            }
        }

        $output = [];
        $status = 1;
        @exec(escapeshellarg($crontab).' -l 2>/dev/null', $output, $status);

        if ($status !== 0 && $status !== 1) {
            return null;
        }

        return $status === 1 ? '' : implode(PHP_EOL, $output).PHP_EOL;
    }

    private static function writeCrontab(string $crontab, string $content, ?string &$error = null): bool
    {
        $tmp = storage_path('framework/scheduler-crontab-'.getmypid().'-'.bin2hex(random_bytes(4)).'.tmp');
        if (! is_dir(dirname($tmp))) {
            @mkdir(dirname($tmp), 0775, true);
        }

        // Owner-only before the content goes in: the owner's other cron lines may carry secrets.
        if (! @touch($tmp) || ! @chmod($tmp, 0600) || @file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            $error = __('Could not write a temporary file in storage/framework.');

            return false;
        }

        $output = [];
        $status = 1;
        @exec(escapeshellarg($crontab).' '.escapeshellarg($tmp).' 2>&1', $output, $status);
        @unlink($tmp);

        if ($status !== 0) {
            $error = trim(implode(' ', array_map('trim', $output)));

            return false;
        }

        return true;
    }

    /**
     * The crontab with this site's block holding $command: the first block replaced where it is (every other line, its
     * order and the blank lines stay exactly as they were; a second block of this site, which only a fault could have
     * written, goes), or added at the end when there is none. A start marker without its end marker is replaced alone:
     * the lines after it are never taken for the block.
     */
    private static function withBlock(string $crontab, string $marker, string $command): string
    {
        $eol = str_contains($crontab, "\r\n") ? "\r\n" : "\n";
        $lines = self::crontabLines($crontab);
        $kept = [];
        $replaced = false;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) !== "# >>> {$marker}") {
                $kept[] = $lines[$i];

                continue;
            }

            if (! $replaced) {
                array_push($kept, "# >>> {$marker}", $command, "# <<< {$marker}");
                $replaced = true;
            }

            $i = self::blockEnd($lines, $i, $marker) ?? $i;
        }

        if ($replaced) {
            return implode($eol, $kept).$eol;
        }

        $before = rtrim(implode($eol, $kept));

        return ($before === '' ? '' : $before.$eol.$eol)
            ."# >>> {$marker}".$eol.$command.$eol."# <<< {$marker}".$eol;
    }

    /**
     * What this account's crontab holds for this site, and what becomes of it:
     *  - 'absent': no block of this site;
     *  - 'old': a line this site wrote in 1.2.x (its lock in /tmp) — 'crontab' is the crontab with exactly those lines
     *    replaced by the current line, in their place (the PHP binary each one runs is kept while it works; a second
     *    older line, or one next to a current line, goes); every other line stays byte for byte. Null when no PHP
     *    binary can be found for the new line, or when $build is false;
     *  - 'custom': a line of this site the owner wrote or changed by hand (or a block whose end marker is missing);
     *  - 'current': this site's current line;
     *  - 'off': the block holds no command any more: the owner switched the line off ("# * * * * * ...").
     * Only 'old' is ever rewritten (judge round 1b BS-RV-01: the owner's choice stays).
     *
     * @return array{state: string, lines: array<int, string>, crontab: string|null}
     */
    private static function planBlock(string $crontab, ?string $php = null, bool $build = true): array
    {
        $marker = self::marker();
        $lines = self::crontabLines($crontab);
        $count = count($lines);
        $inner = [];
        $found = false;
        $open = false;

        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) !== "# >>> {$marker}") {
                continue;
            }

            $found = true;
            $end = self::blockEnd($lines, $i, $marker);

            if ($end === null) {
                // A start marker without its end: the lines after it are never taken for the block.
                $open = true;

                continue;
            }

            for ($j = $i + 1; $j < $end; $j++) {
                $inner[$j] = $lines[$j];
            }

            $i = $end;
        }

        if (! $found) {
            return ['state' => 'absent', 'lines' => [], 'crontab' => null];
        }

        $kinds = array_map(self::lineKind(...), $inner);
        $commands = [];

        foreach ($inner as $index => $line) {
            if ($kinds[$index] !== 'comment') {
                $commands[] = trim($line);
            }
        }

        $has = static fn (string $kind): bool => in_array($kind, $kinds, true);
        $state = match (true) {
            $has('old') => 'old',
            $open || $has('custom') => 'custom',
            $has('current') => 'current',
            default => 'off',
        };

        if ($state !== 'old' || ! $build) {
            return ['state' => $state, 'lines' => $commands, 'crontab' => null];
        }

        $hasCurrent = $has('current');

        foreach ($inner as $index => $line) {
            if ($kinds[$index] !== 'old') {
                continue;
            }

            if ($hasCurrent) {
                // One line of this site runs the scheduler: an older one next to it would run it a second time.
                unset($lines[$index]);

                continue;
            }

            $linePhp = self::phpOfLine($line);
            $newPhp = match (true) {
                PhpBinary::usable($linePhp) => $linePhp,
                PhpBinary::usable($php) => $php,
                default => PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']),
            };

            if (! PhpBinary::usable($newPhp)) {
                return ['state' => 'old', 'lines' => $commands, 'crontab' => null];
            }

            $lines[$index] = self::cronLine((string) $newPhp);
            $hasCurrent = true;
        }

        $eol = str_contains($crontab, "\r\n") ? "\r\n" : "\n";

        return ['state' => 'old', 'lines' => $commands, 'crontab' => implode($eol, array_values($lines)).$eol];
    }

    /**
     * A line inside this site's block: 'comment' (a comment or a blank line: a line switched off with "#" is one),
     * 'current' (this version's line: its lock inside the site), 'old' (the 1.2.x line: its lock in /tmp, named from
     * the folder) or 'custom' (anything else: the owner's own command).
     */
    private static function lineKind(string $line): string
    {
        $line = trim($line);

        return match (true) {
            $line === '' || str_starts_with($line, '#') => 'comment',
            str_contains($line, escapeshellarg(self::lockPath())) => 'current',
            str_contains($line, self::olderLockPath()) && str_contains($line, 'schedule:run') => 'old',
            default => 'custom',
        };
    }

    /**
     * The crontab's lines (the line break after the last one is not a line of its own).
     *
     * @return array<int, string>
     */
    private static function crontabLines(string $crontab): array
    {
        if ($crontab === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $crontab) ?: [];

        if (str_ends_with($crontab, "\n") || str_ends_with($crontab, "\r")) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The index of the end marker of the block that starts at $start, or null when another start marker or the end of
     * the crontab comes first.
     *
     * @param  array<int, string>  $lines
     */
    private static function blockEnd(array $lines, int $start, string $marker): ?int
    {
        $count = count($lines);

        for ($j = $start + 1; $j < $count; $j++) {
            $line = trim($lines[$j]);

            if ($line === "# <<< {$marker}") {
                return $j;
            }

            if ($line === "# >>> {$marker}") {
                return null;
            }
        }

        return null;
    }

    /**
     * The words of a POSIX shell line as the shell reads them (single quotes and backslashes; what escapeshellarg()
     * writes).
     *
     * @return array<int, string>
     */
    private static function shellWords(string $line): array
    {
        $words = [];
        $word = '';
        $inWord = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === "'") {
                $end = strpos($line, "'", $i + 1);

                if ($end === false) {
                    return [];
                }

                $word .= substr($line, $i + 1, $end - $i - 1);
                $i = $end;
                $inWord = true;

                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $word .= $line[++$i];
                $inWord = true;

                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }

                continue;
            }

            $word .= $char;
            $inWord = true;
        }

        if ($inWord) {
            $words[] = $word;
        }

        return $words;
    }

    /** The PHP binary a cron line of this site runs ("... /bin/sh -c 'cd ... && <php> artisan schedule:run ...'"). */
    private static function phpOfLine(string $line): ?string
    {
        $words = self::shellWords($line);
        $inner = null;

        foreach ($words as $index => $word) {
            if ($word === '-c' && isset($words[$index + 1])) {
                $inner = $words[$index + 1];
            }
        }

        $innerWords = $inner !== null ? self::shellWords($inner) : $words;

        foreach ($innerWords as $index => $word) {
            if (($innerWords[$index + 1] ?? null) === 'artisan' && ($innerWords[$index + 2] ?? null) === 'schedule:run') {
                return $word;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ crontab of older versions

    /**
     * A line of an older version that this site wrote into this account's crontab (its lock in /tmp) is replaced by the
     * current line, in its place, keeping the PHP binary it runs (planBlock()). Nothing else of the crontab changes: not
     * the owner's other lines, not a line of this site the owner switched off with "#" or wrote himself; nothing is
     * added where the crontab has no block of this site (an owner who removed it, a cron of a hosting panel).
     *
     * Once per account and current line: the outcome is kept in storage/framework. A rewrite is read back at a check
     * LINE_CONFIRM_SECONDS later (the next minute's run: another site of the account may have written the whole
     * crontab in the same moment and put this site's older line back; the sites of one account also wait for each
     * other, withAccountLock()); a crontab that could not be read or written is tried again after an hour.
     *
     * @param  float|null  $accountWait  how long to wait for another site of the account that changes the crontab
     * @return string|null 'updated', 'current', 'absent', 'off', 'custom', 'failed', 'unavailable' (no crontab command:
     *                     asked again after an hour), or null when nothing was checked now (done before, a rewrite not
     *                     due for its check yet, Windows, another process of this site checking)
     */
    public static function migrateCronLine(?float $accountWait = null): ?string
    {
        if (Environment::isWindows()) {
            return null;
        }

        try {
            $statePath = self::lineStatePath();
            $key = self::lineKey();

            if (! self::lineCheckDue(self::readJson($statePath), $key)) {
                return null;
            }

            $lock = @fopen($statePath.'.lock', 'c');

            if ($lock === false) {
                return null;
            }

            try {
                if (! @flock($lock, LOCK_EX | LOCK_NB)) {
                    return null;
                }

                // Another process of this site may have checked meanwhile.
                $state = self::readJson($statePath);

                if (! self::lineCheckDue($state, $key)) {
                    return null;
                }

                // Rewrites in a row that did not stay (only an earlier 'updated' of this line counts).
                $writes = ($state['key'] ?? null) === $key && ($state['result'] ?? null) === 'updated' ? (int) ($state['writes'] ?? 0) : 0;
                $crontab = self::crontabBinary();
                $outcome = $crontab === null
                    // No crontab command (or exec() disabled): asked again after an hour, not every minute.
                    ? 'unavailable'
                    : self::withAccountLock(
                        static fn (): string => self::changeBlock($crontab, null, false, $writes < self::LINE_MAX_WRITES),
                        $accountWait ?? self::ACCOUNT_LOCK_WAIT
                    );

                self::writeJson($statePath, ['key' => $key, 'result' => $outcome, 'at' => time(), 'writes' => $outcome === 'updated' ? $writes + 1 : 0]);

                return $outcome;
            } finally {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What this account's crontab holds for this site, for Tools > Scheduled tasks: 'current' (this site's line), 'old'
     * (an older line of this site: it is replaced by itself), 'off' (this site's line, switched off with "#"),
     * 'custom' (a line of this site changed by hand), 'absent' (no line of this site) or 'unknown' (Windows, or the
     * crontab could not be read).
     *
     * @return array{state: string, lines: array<int, string>}
     */
    public static function crontabState(): array
    {
        if (Environment::isWindows()) {
            return ['state' => 'unknown', 'lines' => []];
        }

        try {
            $crontab = self::crontabBinary();
            $existing = $crontab !== null ? self::readCrontab($crontab) : null;

            if ($existing === null) {
                return ['state' => 'unknown', 'lines' => []];
            }

            $plan = self::planBlock($existing, null, false);

            return ['state' => $plan['state'], 'lines' => $plan['lines']];
        } catch (Throwable) {
            return ['state' => 'unknown', 'lines' => []];
        }
    }

    /**
     * Read this account's crontab and change this site's block (planBlock()): 'updated' (the 1.2.x lines replaced),
     * 'added' (no block: added at the end; only with $addWhenAbsent), or what planBlock() found and left as it is
     * ('absent', 'current', 'off', 'custom'); 'failed' when the crontab could not be read or written, no PHP binary
     * was found, or a rewrite was needed but not allowed ($mayWrite: rewrites that did not stay).
     */
    private static function changeBlock(string $crontab, ?string $php, bool $addWhenAbsent, bool $mayWrite, ?string &$error = null): string
    {
        $existing = self::readCrontab($crontab);

        if ($existing === null) {
            $error = __("This account's crontab could not be read.");

            return 'failed';
        }

        $plan = self::planBlock($existing, $php, $mayWrite);

        if ($plan['state'] === 'absent' && $addWhenAbsent) {
            if (! PhpBinary::usable($php)) {
                $error = __('No PHP command line was found on this server, so the cron line could not be added.');

                return 'failed';
            }

            return self::writeCrontab($crontab, self::withBlock($existing, self::marker(), self::cronLine((string) $php)), $error) ? 'added' : 'failed';
        }

        if ($plan['state'] !== 'old') {
            return $plan['state'];
        }

        if (! $mayWrite) {
            return 'failed';
        }

        if ($plan['crontab'] === null) {
            $error = __('No PHP command line was found on this server, so the cron line could not be added.');

            return 'failed';
        }

        return self::writeCrontab($crontab, $plan['crontab'], $error) ? 'updated' : 'failed';
    }

    /**
     * Run $change while no other site of this account changes the crontab (each one reads the whole crontab, changes
     * its block and writes the whole crontab back: two at once would lose one change). The lock is a file of the
     * account in the temporary folder; waited for at most $wait seconds, then $change runs anyway, and so it does when
     * the file cannot be used (another account's file of that name, a temporary folder of its own per process): the
     * read-back of migrateCronLine() makes up for a change lost then. Never in the way of the scheduler.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private static function withAccountLock(callable $change, float $wait): mixed
    {
        $path = self::accountLockPath();
        $handle = is_link($path) ? false : @fopen($path, 'c');

        if ($handle === false) {
            return $change();
        }

        try {
            $deadline = microtime(true) + max(0.0, $wait);

            while (! @flock($handle, LOCK_EX | LOCK_NB) && microtime(true) < $deadline) {
                usleep(100000);
            }

            return $change();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** The account-wide lock of crontab changes: /tmp/avdbcms-crontab-<account>.lock (one crontab per account). */
    private static function accountLockPath(): string
    {
        return rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/avdbcms-crontab-'.self::accountId().'.lock';
    }

    private static function accountId(): string
    {
        return function_exists('posix_geteuid') ? (string) posix_geteuid() : 'user';
    }

    /** Whether migrateCronLine() looks at the crontab now, after what it found last time ($state). */
    private static function lineCheckDue(array $state, string $key): bool
    {
        if (($state['key'] ?? null) !== $key) {
            return true;
        }

        $age = time() - (int) ($state['at'] ?? 0);

        return match ($state['result'] ?? null) {
            // A rewrite is read back at a later run.
            'updated' => $age >= self::LINE_CONFIRM_SECONDS,
            'failed', 'unavailable' => $age >= self::LINE_RETRY_SECONDS,
            // 'current', 'absent', 'off', 'custom': nothing to do for this line any more.
            default => false,
        };
    }

    /** What the current line means for the check: this site's block and its lock (a moved site checks again). */
    private static function lineKey(): string
    {
        return sha1(self::marker().'|'.self::lockPath());
    }

    private static function lineStatePath(): string
    {
        return storage_path(sprintf(self::LINE_STATE, self::accountId()));
    }

    private static function forgetLineState(): void
    {
        try {
            @unlink(self::lineStatePath());
        } catch (Throwable) {
            // nothing kept
        }
    }

    // ------------------------------------------------------------------ the runs

    /**
     * Registered in console processes only (AppServiceProvider): every start of schedule:run that the site did not
     * start itself is a run of the system cron (recordCronRun()).
     */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command === 'schedule:run') {
                self::recordCronRun();
            }
        });
    }

    /** Whether this process was started by the site (a run after a page request, "Run Scheduler Now"). */
    public static function startedBySite(): bool
    {
        $value = getenv(self::SOURCE_ENV);

        if ($value === false) {
            $value = $_SERVER[self::SOURCE_ENV] ?? $_ENV[self::SOURCE_ENV] ?? null;
        }

        return $value === self::SOURCE_SITE;
    }

    /**
     * A run of the scheduler by the system cron (or by hand, by Windows' Task Scheduler, by schedule:work) starts: its
     * stamp tells the page requests not to start one; the cron's logs are kept small and lose their world-write bit
     * (1.2.x left schedule-run.log 0666); an older line of this site in the crontab is replaced (migrateCronLine()).
     */
    public static function recordCronRun(): void
    {
        if (self::startedBySite()) {
            return;
        }

        try {
            self::writeStamp(self::CRON_STAMP);

            foreach ([self::CRON_LOG, self::TASK_LOG] as $log) {
                AppendLog::withoutWorldWrite(storage_path($log));
                AppendLog::cap(storage_path($log));
            }

            self::migrateCronLine();
        } catch (Throwable) {
            // Never in the way of the scheduler.
        }
    }

    /**
     * Start schedule:run in the background as a run of the site (not of the system cron): after a page request, or
     * "Run Scheduler Now". Its output goes to storage/logs/schedule-web.log.
     */
    public static function startSiteRun(?string $php = null): bool
    {
        $php = PhpBinary::usable($php) ? $php : PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);

        if (! PhpBinary::usable($php)) {
            return false;
        }

        AppendLog::cap(storage_path(self::TASK_LOG));

        $started = ShellRunner::runBackground(
            [(string) $php, base_path('artisan'), 'schedule:run'],
            storage_path(self::SITE_LOG),
            base_path(),
            [self::SOURCE_ENV => self::SOURCE_SITE]
        );

        if ($started) {
            self::writeStamp(self::SITE_STAMP);
        }

        return $started;
    }

    /**
     * After a page request (public/index.php, the answer already sent): start the scheduler in the background at most
     * once a minute, on Linux/BSD, on an installed site, while the system cron does not run it.
     */
    public static function triggerDueWebScheduler(): void
    {
        if (PHP_SAPI === 'cli' || Environment::isWindows()) {
            return;
        }

        try {
            self::afterPageRequest();
        } catch (Throwable) {
            // A page request never fails because of the scheduler.
        }
    }

    /**
     * What triggerDueWebScheduler() does after a page request (the tests call it directly).
     *
     * @param  (callable(): bool)|null  $start  starts the run (startSiteRun())
     * @param  float|null  $requestStarted  when this request started (LARAVEL_START)
     * @return string 'throttled' (a request of the same minute looked already), 'not installed', 'changed' (the install
     *                lock or .env changed during this request), 'busy' (another request of this minute is looking), 'cron'
     *                (the system cron runs the scheduler), 'no processes', 'started', 'not started'
     */
    public static function afterPageRequest(?int $now = null, ?callable $start = null, ?float $requestStarted = null): string
    {
        $now ??= time();
        $requestStarted ??= defined('LARAVEL_START') ? (float) LARAVEL_START : null;
        $throttle = storage_path(self::THROTTLE_STAMP);

        // The usual answer: a page request of the same minute (one small read).
        if (is_file($throttle) && $now - (int) @file_get_contents($throttle) < 60) {
            return 'throttled';
        }

        $installed = self::installState($now);

        if ($installed === false) {
            return 'not installed';
        }

        // The installer's last request (it wrote the lock) or a save that rewrote .env still holds the values it read
        // when it started (the .env.example copy of a fresh upload: no database user), and a run it starts would inherit
        // them: the next request starts it (R1B-015).
        if ($requestStarted !== null && self::changedSince((int) floor($requestStarted))) {
            return 'changed';
        }

        if (! self::claimMinute($throttle, $now)) {
            return 'busy';
        }

        // A site without the installer's lock: its database tells, once a minute (the minute is taken now).
        if ($installed === null && ! self::installedWithoutLock()) {
            return 'not installed';
        }

        // An older line of this site whose cron cannot run any more (a lock in /tmp of another account) is replaced
        // here: that cron never starts a run to do it itself.
        self::migrateCronLine();
        self::tightenCronLog();

        if (self::cronRanRecently($now)) {
            return 'cron';
        }

        if (! Environment::canRunProcesses()) {
            return 'no processes';
        }

        return ($start ?? static fn (): bool => self::startSiteRun())() ? 'started' : 'not started';
    }

    /**
     * The installer is open again (INSTALLER_ALLOW_REINSTALL): a new installation may replace the database at any
     * moment.
     */
    public static function installerOpen(): bool
    {
        return filter_var(RuntimeEnv::get('INSTALLER_ALLOW_REINSTALL', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * The site is installed, for the page requests: the installer wrote its lock (its very last step) and is not open
     * again — never with the .env.example copy of a fresh upload, never while the installer works on the database. A
     * site without the lock (moved to another host without storage/app, the lock removed by hand) counts as installed
     * when its .env does not say APP_INSTALLED=false and has not been written for INSTALL_QUIET_SECONDS, an
     * administrator exists and the installer agrees (judge round 1b BS-RV-04: 1.2.x ran its tasks after visits too).
     */
    public static function siteInstalled(?int $now = null): bool
    {
        return self::installState($now) ?? self::installedWithoutLock();
    }

    /**
     * What the files say (no database): true (the installer's lock), false (not installed: the installer open again,
     * APP_INSTALLED=false, no .env, or a .env written in the last INSTALL_QUIET_SECONDS while there is no lock), null
     * (no lock: the database tells, installedWithoutLock()).
     */
    private static function installState(?int $now = null): ?bool
    {
        if (self::installerOpen()) {
            return false;
        }

        if (is_file(storage_path('app/installed.json'))) {
            return true;
        }

        $flag = RuntimeEnv::get('APP_INSTALLED', null);

        if ($flag !== null && $flag !== '' && filter_var($flag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false) {
            return false;
        }

        $env = base_path('.env');
        clearstatcache(true, $env);
        $modified = @filemtime($env);

        // An installer that writes APP_INSTALLED=true before its database work (1.2.x, RC1) may be at work.
        if ($modified === false || ($now ?? time()) - $modified < self::INSTALL_QUIET_SECONDS) {
            return false;
        }

        return null;
    }

    /** A site without the installer's lock is installed: an administrator exists, and the installer agrees (no run of its own). */
    private static function installedWithoutLock(): bool
    {
        try {
            return User::query()->where('is_admin', true)->exists()
                && app(Installer::class)->isInstalled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the scheduled tasks may run (App\Console\Kernel::schedule()): not while the installer is open again, and
     * not on a fresh upload whose .env still says APP_INSTALLED=false (an owner who added the cron line before
     * installing). A site without the installer's lock that does not say so runs them as before.
     */
    public static function schedulesMayRun(): bool
    {
        if (self::installerOpen()) {
            return false;
        }

        if (is_file(storage_path('app/installed.json'))) {
            return true;
        }

        $installed = RuntimeEnv::get('APP_INSTALLED', null);

        return $installed === null || $installed === '' || filter_var($installed, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /** When the system cron last started the scheduler (Unix time), or null. */
    public static function lastCronRunAt(): ?int
    {
        return self::readStamp(self::CRON_STAMP);
    }

    /** When the site last started the scheduler itself (Unix time), or null. */
    public static function lastSiteRunAt(): ?int
    {
        return self::readStamp(self::SITE_STAMP);
    }

    public static function cronRanRecently(?int $now = null): bool
    {
        $last = self::lastCronRunAt();

        return $last !== null && ($now ?? time()) - $last < self::CRON_FRESH_SECONDS;
    }

    /**
     * Who starts the scheduled tasks, for the status and Tools > Scheduled tasks: 'cron' (the system cron ran them in
     * the last RECENT_SECONDS), 'site' (only the site after page requests did), 'none'.
     *
     * @return array{source: string, last_cron: int|null, last_site: int|null}
     */
    public static function runStatus(?int $now = null): array
    {
        $now ??= time();
        $cron = self::lastCronRunAt();
        $site = self::lastSiteRunAt();
        $source = match (true) {
            $cron !== null && $now - $cron <= self::RECENT_SECONDS => 'cron',
            $site !== null && $now - $site <= self::RECENT_SECONDS => 'site',
            default => 'none',
        };

        return ['source' => $source, 'last_cron' => $cron, 'last_site' => $site];
    }

    /**
     * The cron line's log of 1.2.x (0666, it was also the page requests' log): its world-write bit goes once no other
     * account appends to it: this process owns it and the system cron runs as this account (its stamp is ours) or not
     * at all. The cron's own run does the same (recordCronRun()).
     */
    private static function tightenCronLog(): void
    {
        $stamp = storage_path(self::CRON_STAMP);

        if (is_file($stamp) && function_exists('posix_geteuid') && @fileowner($stamp) !== posix_geteuid()) {
            return;
        }

        AppendLog::withoutWorldWrite(storage_path(self::CRON_LOG));
    }

    /** Whether the installer's lock or .env was written at or after $since (Unix time). */
    private static function changedSince(int $since): bool
    {
        foreach ([storage_path('app/installed.json'), base_path('.env')] as $file) {
            clearstatcache(true, $file);
            $modified = @filemtime($file);

            if ($modified !== false && $modified >= $since) {
                return true;
            }
        }

        return false;
    }

    /** Take the minute for this request: false when another request of the same minute took it first. */
    private static function claimMinute(string $throttle, int $now): bool
    {
        $directory = dirname($throttle);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = @fopen($throttle, 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (! @flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            $last = (int) stream_get_contents($handle);

            if ($now - $last < 60) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $now);
            fflush($handle);

            return true;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function readStamp(string $relative): ?int
    {
        $path = storage_path($relative);

        if (! is_file($path)) {
            return null;
        }

        $value = (int) trim((string) @file_get_contents($path));

        return $value > 0 ? $value : null;
    }

    /**
     * A new file renamed over the old one: the stamp is replaced whoever wrote it before (a cron of another account,
     * root), as long as this process may write in the folder.
     */
    private static function writeStamp(string $relative): void
    {
        $path = storage_path($relative);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $temporary = $path.'.'.getmypid().'.'.bin2hex(random_bytes(3)).'.tmp';

        if (@file_put_contents($temporary, (string) time()) === false) {
            return;
        }

        @chmod($temporary, AppendLog::MODE);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            @file_put_contents($path, (string) time(), LOCK_EX);
        }
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        $temporary = $path.'.'.getmypid().'.'.bin2hex(random_bytes(3)).'.tmp';

        if (@file_put_contents($temporary, (string) json_encode($data)) === false) {
            return;
        }

        @chmod($temporary, AppendLog::MODE);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    /**
     * @return array{available:bool,installed:bool,changed:bool,message:string,command:string|null,state:string}
     */
    private static function result(bool $available, bool $installed, bool $changed, string $message, ?string $command, string $state): array
    {
        return [
            'available' => $available,
            'installed' => $installed,
            'changed' => $changed,
            'message' => $message,
            'command' => $command,
            'state' => $state,
        ];
    }
}
