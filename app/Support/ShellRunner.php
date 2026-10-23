<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Thin wrapper around Symfony Process that degrades gracefully when
 * shell exec primitives are disabled by the host (`disable_functions`).
 *
 * All consumers should prefer this class over `exec()` / `shell_exec()` /
 * `popen()`. It surfaces a typed outcome and never shells a string —
 * commands are always argv arrays.
 */
final class ShellRunner
{
    public static function available(): bool
    {
        return Environment::canRunProcesses();
    }

    /**
     * Run a command synchronously.
     *
     * @param  array<int, string>  $command  argv-style command
     * @param  array<string, string|null>|null  $env
     */
    public static function run(
        array $command,
        ?string $cwd = null,
        ?array $env = null,
        int $timeout = 60,
        ?string $stdin = null,
    ): ShellResult {
        if (! self::available()) {
            return ShellResult::unavailable(
                'PHP proc_open/proc_close is disabled on this host. '
                .'The operation requires subprocess support.'
            );
        }

        try {
            $process = new Process($command, $cwd, $env, $stdin, max(1, $timeout));
            $process->run();

            return new ShellResult(
                exitCode: $process->getExitCode() ?? 1,
                stdout: (string) $process->getOutput(),
                stderr: (string) $process->getErrorOutput(),
                unavailable: false,
                reason: null,
            );
        } catch (ProcessException|Throwable $e) {
            return ShellResult::unavailable($e->getMessage());
        }
    }

    /**
     * Run a command in the background (fire and forget).
     *
     * @param  array<int, string>  $command
     */
    public static function runBackground(array $command, ?string $logFile = null, ?string $cwd = null): bool
    {
        if ($command === []) {
            return false;
        }

        if (! Environment::canRunProcesses()) {
            return false;
        }

        try {
            if (Environment::isWindows()) {
                return self::runWindowsBackground($command, $logFile, $cwd);
            }

            if ($logFile) {
                @is_dir(dirname($logFile)) || @mkdir(dirname($logFile), 0775, true);
                @touch($logFile);
                @chmod($logFile, 0666);
            }

            // POSIX-safe detached launch for Linux/macOS/BSD cron and web
            // requests. Detach into an independent process session (setsid)
            // so web servers (LiteSpeed, PHP-FPM, Apache mod_fcgid) do not
            // kill background workers when the HTTP request finishes or the
            // browser closes.
            $escapedCommand = implode(' ', array_map('escapeshellarg', $command));
            $log = $logFile ? escapeshellarg($logFile) : '/dev/null';
            // POSIX sh only guarantees single-digit descriptor redirects.
            $closeInheritedDescriptors = 'fd=3; while [ "$fd" -le 9 ]; do eval "exec ${fd}>&-" 2>/dev/null; fd=$((fd + 1)); done';
            $script = sprintf(
                '%s; if command -v setsid >/dev/null 2>&1; then setsid nohup %s </dev/null >%s 2>&1 & else ( nohup %s </dev/null >%s 2>&1 & ); fi',
                $closeInheritedDescriptors,
                $escapedCommand,
                $log,
                $escapedCommand,
                $log
            );

            $wrapper = new Process(['sh', '-c', $script], $cwd);
            $wrapper->setTimeout(10);
            $wrapper->run();

            return $wrapper->getExitCode() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function runWindowsBackground(array $command, ?string $logFile = null, ?string $cwd = null): bool
    {
        $cwd ??= getcwd() ?: null;
        $logFile = $logFile ? str_replace('/', '\\', $logFile) : null;
        if ($logFile) {
            @is_dir(dirname($logFile)) || @mkdir(dirname($logFile), 0775, true);
        }

        $args = array_slice($command, 1);
        $argumentList = self::powershellQuote(implode(' ', array_map(self::windowsCommandLineQuote(...), $args)));
        $script = '$ErrorActionPreference = "Stop"; Start-Process'
            .' -FilePath '.self::powershellQuote($command[0])
            .' -ArgumentList '.$argumentList
            .($cwd ? ' -WorkingDirectory '.self::powershellQuote($cwd) : '')
            .' -WindowStyle Hidden';

        if ($logFile) {
            $script .= ' -RedirectStandardOutput '.self::powershellQuote($logFile)
                .' -RedirectStandardError '.self::powershellQuote($logFile.'.err');
        }

        try {
            $powershell = new Process([
                'powershell.exe',
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                $script,
            ], $cwd);
            $powershell->setTimeout(10);
            $powershell->run();

            if ($powershell->getExitCode() === 0) {
                return true;
            }
        } catch (Throwable) {
            // Fall back to cmd.exe below.
        }

        $redirect = $logFile
            ? ' > '.self::cmdQuote($logFile).' 2> '.self::cmdQuote($logFile.'.err')
            : ' >NUL 2>&1';

        $cmd = 'start "" /B '
            .implode(' ', array_map(self::cmdQuote(...), $command))
            .$redirect;

        try {
            $wrapper = new Process(['cmd.exe', '/D', '/C', $cmd], $cwd);
            $wrapper->setTimeout(10);
            $wrapper->run();

            return $wrapper->getExitCode() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private static function cmdQuote(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }

    private static function windowsCommandLineQuote(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (! preg_match('/[\s"]/', $value)) {
            return $value;
        }

        $value = preg_replace('/(\\\\*)"/', '$1$1\\"', $value) ?? $value;
        $value = preg_replace('/(\\\\+)$/', '$1$1', $value) ?? $value;

        return '"'.$value.'"';
    }

    /**
     * Require shell exec; throws when the host forbids subprocesses.
     */
    public static function requireAvailable(): void
    {
        if (! self::available()) {
            throw new RuntimeException(
                'Shell execution primitives (proc_open / proc_close) are disabled on this host. '
                .'Enable them in php.ini or remove them from disable_functions.'
            );
        }
    }
}
