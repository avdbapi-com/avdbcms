<?php

namespace App\Support;

use Throwable;

/**
 * The logs that background processes and the scheduler's cron line append to (storage/logs/schedule-run.log,
 * schedule-web.log, cron.log, queue-worker.log, the import and duration logs).
 *
 *  - Never world-writable: a new log is created 0644 (owner-write only, whatever the process umask); a log an older
 *    version left world-writable (1.2.x chmodded every background log 0666) loses only that bit, and only where this
 *    process owns it (chmod() of another account's file is refused anyway).
 *  - Appended to, never truncated: several runs (the cron's, the site's) each keep their lines.
 *  - Bounded: once a log is bigger than MAX_BYTES it is cut in place to its newest KEEP_BYTES, from the start of a
 *    line. In place (ftruncate + write under an exclusive flock, no new file): a process that has the log open for
 *    appending (O_APPEND: the shell's ">>", PHP's FILE_APPEND) goes on writing at the new end, and the file keeps its
 *    owner and mode.
 */
final class AppendLog
{
    /** A log is cut once it is bigger than this. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** What stays of a cut log: its newest lines, about this much. */
    public const KEEP_BYTES = 512 * 1024;

    /** The mode of a log this class creates. */
    public const MODE = 0644;

    /**
     * The file a background command's output may be appended to: $path, created (0644) when missing, cut when too
     * big and without its world-write bit where this process owns it. Null when this process cannot write it (a log
     * another account created): the command then still runs, its output goes nowhere.
     */
    public static function prepare(string $path): ?string
    {
        try {
            $directory = dirname($path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            if (! is_file($path)) {
                if (@touch($path)) {
                    @chmod($path, self::MODE);
                }
            } else {
                self::withoutWorldWrite($path);
                self::cap($path);
            }

            clearstatcache(true, $path);

            return is_file($path) && is_writable($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cut the log to its newest lines once it is bigger than $max bytes (in place, see the class comment). True when
     * it was cut; false when it was small enough, missing, not writable or being cut by another process right now.
     */
    public static function cap(string $path, int $max = self::MAX_BYTES, int $keep = self::KEEP_BYTES): bool
    {
        $keep = max(1, min($keep, $max));
        clearstatcache(true, $path);
        $size = @filesize($path);

        if ($size === false || $size <= $max) {
            return false;
        }

        $handle = @fopen($path, 'r+b');

        if ($handle === false) {
            return false;
        }

        try {
            if (! @flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            $stat = fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;

            if ($size <= $max) {
                return false;
            }

            if (fseek($handle, -$keep, SEEK_END) !== 0) {
                return false;
            }

            $tail = (string) stream_get_contents($handle);
            $lineStart = strpos($tail, "\n");

            // From the first whole line on; a tail that is one single line stays as it is.
            if ($lineStart !== false && $lineStart < strlen($tail) - 1) {
                $tail = substr($tail, $lineStart + 1);
            }

            if (! ftruncate($handle, 0) || ! rewind($handle)) {
                return false;
            }

            fwrite($handle, $tail);
            fflush($handle);

            return true;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Take the world-write bit off a file this process owns (0666 -> 0664, 0646 -> 0644): the only change, so an owner
     * group that writes the log keeps writing it. True when the mode was changed. POSIX only (Windows has no such bit).
     */
    public static function withoutWorldWrite(string $path): bool
    {
        if (Environment::isWindows() || ! is_file($path) || is_link($path)) {
            return false;
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);

        if ($perms === false || ($perms & 0002) === 0) {
            return false;
        }

        if (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid()) {
            return false;
        }

        $changed = @chmod($path, ($perms & 07777) & ~0002);
        clearstatcache(true, $path);

        return $changed;
    }

    /**
     * The newest $lines lines of a log, read from its end (a log of any size costs at most about $lines * 1 KB of
     * reading). [] when it is missing or unreadable.
     *
     * @return array<int, string>
     */
    public static function tail(string $path, int $lines = 80): array
    {
        if ($lines < 1 || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $stat = fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;
            $chunk = 8192;
            $position = $size;
            $buffer = '';

            while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
                $read = (int) min($chunk, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = (string) fread($handle, $read).$buffer;
            }

            $all = preg_split('/\r\n|\n|\r/', $buffer) ?: [];

            // A trailing line break ends the last line; a partial first line (the read started inside it) is dropped.
            if ($all !== [] && end($all) === '') {
                array_pop($all);
            }

            if ($position > 0 && count($all) > $lines) {
                array_shift($all);
            }

            return array_values(array_slice($all, -$lines));
        } finally {
            fclose($handle);
        }
    }
}
