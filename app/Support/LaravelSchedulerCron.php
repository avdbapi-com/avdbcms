<?php

namespace App\Support;

final class LaravelSchedulerCron
{
    private const MARKER_PREFIX = 'AVDBClip Laravel Scheduler';

    /**
     * @return array{available:bool,installed:bool,changed:bool,message:string,command:string|null}
     */
    public static function ensureInstalled(?string $php = null): array
    {
        $php = PhpBinary::usable($php) ? $php : PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
        $command = $php ? self::cronLine($php) : null;

        if (Environment::isWindows()) {
            return self::result(false, false, false, 'Automatic crontab installation is only available on Unix-like servers.', $command);
        }

        if (! Environment::canRunProcesses()) {
            return self::result(false, false, false, 'PHP process control is disabled, so the scheduler cron could not be installed automatically.', $command);
        }

        if (! PhpBinary::usable($php)) {
            return self::result(false, false, false, 'No usable PHP CLI binary was found for the Laravel scheduler.', $command);
        }

        $crontab = self::crontabBinary();
        if ($crontab === null) {
            return self::result(false, false, false, 'The crontab command was not found on this server.', $command);
        }

        $existing = self::readCrontab($crontab);
        if ($existing === null) {
            return self::result(false, false, false, 'Could not read the current user crontab.', $command);
        }

        $marker = self::marker();
        if (str_contains($existing, "# >>> {$marker}") && str_contains($existing, $command)) {
            return self::result(true, true, false, 'Laravel scheduler cron is already installed.', $command);
        }

        $cleaned = self::removeExistingBlock($existing, $marker);
        $block = implode(PHP_EOL, [
            "# >>> {$marker}",
            $command,
            "# <<< {$marker}",
        ]);

        $next = rtrim($cleaned);
        $next = ($next === '' ? '' : $next.PHP_EOL.PHP_EOL).$block.PHP_EOL;

        $tmp = storage_path('framework/scheduler-crontab-'.getmypid().'-'.bin2hex(random_bytes(4)).'.tmp');
        if (! is_dir(dirname($tmp))) {
            @mkdir(dirname($tmp), 0775, true);
        }

        file_put_contents($tmp, $next, LOCK_EX);

        $output = [];
        $status = 1;
        @exec(escapeshellarg($crontab).' '.escapeshellarg($tmp).' 2>&1', $output, $status);
        @unlink($tmp);

        if ($status !== 0) {
            $message = trim(implode(' ', array_map('trim', $output)));

            return self::result(false, false, false, $message !== '' ? $message : 'Could not install the scheduler crontab entry.', $command);
        }

        @touch(storage_path('logs/schedule-run.log'));

        return self::result(true, true, true, 'Laravel scheduler cron was installed automatically.', $command);
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

    private static function marker(): string
    {
        return self::MARKER_PREFIX.' '.substr(sha1(base_path()), 0, 12);
    }

    private static function cronLine(string $php): string
    {
        return '* * * * * '.self::currentUserShellCommand($php);
    }

    private static function currentUserShellCommand(string $php): string
    {
        $lock = '/tmp/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', basename(base_path())).'-scheduler.lock';
        $inner = 'cd '.escapeshellarg(base_path())
            .' && /bin/date >> '.escapeshellarg(storage_path('logs/schedule-run.log'))
            .' && '.escapeshellarg($php).' artisan schedule:run >> '.escapeshellarg(storage_path('logs/schedule-run.log')).' 2>&1';

        if (Environment::isWindows()) {
            return 'cd '.escapeshellarg(base_path()).' && '.escapeshellarg($php).' artisan schedule:run >> '.escapeshellarg(storage_path('logs/schedule-run.log')).' 2>&1';
        }

        return '/usr/bin/flock -n -o '.escapeshellarg($lock).' /bin/sh -c '.escapeshellarg($inner);
    }

    private static function shellCommand(string $php): string
    {
        $lock = '/tmp/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', basename(base_path())).'-scheduler.lock';
        $inner = 'cd '.escapeshellarg(base_path())
            .' && /bin/date >> '.escapeshellarg(storage_path('logs/schedule-run.log'))
            .' && '.escapeshellarg($php).' artisan schedule:run >> '.escapeshellarg(storage_path('logs/schedule-run.log')).' 2>&1';

        if (Environment::isWindows()) {
            return 'cd '.escapeshellarg(base_path()).' && '.escapeshellarg($php).' artisan schedule:run >> '.escapeshellarg(storage_path('logs/schedule-run.log')).' 2>&1';
        }

        return '/usr/bin/flock -n -o '.escapeshellarg($lock)
            .' /bin/su -s /bin/sh '.escapeshellarg(self::scriptUser()).' -c '.escapeshellarg($inner);
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

    private static function readCrontab(string $crontab): ?string
    {
        $output = [];
        $status = 1;
        @exec(escapeshellarg($crontab).' -l 2>/dev/null', $output, $status);

        if ($status !== 0 && $status !== 1) {
            return null;
        }

        return implode(PHP_EOL, $output);
    }

    private static function removeExistingBlock(string $crontab, string $marker): string
    {
        $lines = preg_split('/\R/', $crontab) ?: [];
        $kept = [];
        $inside = false;

        foreach ($lines as $line) {
            if (trim($line) === "# >>> {$marker}") {
                $inside = true;
                continue;
            }

            if (trim($line) === "# <<< {$marker}") {
                $inside = false;
                continue;
            }

            if ($inside) {
                continue;
            }

            $kept[] = $line;
        }

        return implode(PHP_EOL, $kept);
    }

    public static function triggerDueWebScheduler(): void
    {
        if (PHP_SAPI === 'cli' || Environment::isWindows() || ! file_exists(base_path('.env'))) {
            return;
        }

        $stampFile = storage_path('framework/scheduler-last-run.timestamp');
        $now = time();
        if (file_exists($stampFile)) {
            $lastRun = (int) @file_get_contents($stampFile);
            if ($now - $lastRun < 60) {
                return;
            }
        }

        @file_put_contents($stampFile, (string) $now, LOCK_EX);

        if (! Environment::canRunProcesses()) {
            return;
        }

        $php = PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']);
        if (! PhpBinary::usable($php)) {
            return;
        }

        ShellRunner::runBackground(
            [$php, base_path('artisan'), 'schedule:run'],
            storage_path('logs/schedule-run.log'),
            base_path()
        );
    }

    /**
     * @return array{available:bool,installed:bool,changed:bool,message:string,command:string|null}
     */
    private static function result(bool $available, bool $installed, bool $changed, string $message, ?string $command): array
    {
        return [
            'available' => $available,
            'installed' => $installed,
            'changed' => $changed,
            'message' => $message,
            'command' => $command,
        ];
    }
}
