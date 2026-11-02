<?php

namespace App\Support;

use Throwable;

/**
 * Copies of .env made before it is changed (Money > payment keys, the .env editor). Each copy holds every secret of the
 * site, so they live outside the web folder in storage/app/env-backups (0700), each file is 0600, a name cannot be
 * guessed (.env.<YmdHis>.<16 random hex>: the time still sorts first) and only the newest KEEP copies stay.
 */
final class EnvBackups
{
    public const KEEP = 5;

    /** storage/app/env-backups */
    public static function directory(): string
    {
        return storage_path('app/env-backups');
    }

    /**
     * Keeps a copy of this .env content and returns its file name (null when it could not be written). $stamp
     * (YmdHis) dates the copy, for a file moved in from elsewhere; the default is now.
     */
    public static function store(string $content, ?string $stamp = null): ?string
    {
        $directory = self::directory();

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        @chmod($directory, 0700);

        $stamp = is_string($stamp) && preg_match('/^\d{14}$/', $stamp) === 1 ? $stamp : now()->format('YmdHis');

        try {
            $name = '.env.'.$stamp.'.'.bin2hex(random_bytes(8));
        } catch (Throwable) {
            return null;
        }

        $path = $directory.DIRECTORY_SEPARATOR.$name;

        // Quiet (@): a PHP warning would carry the file's content into the log.
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            return null;
        }

        @chmod($path, 0600);
        self::prune();

        return $name;
    }

    /** Deletes all but the newest $keep copies (names sort by their time). */
    public static function prune(int $keep = self::KEEP): void
    {
        $files = glob(self::directory().DIRECTORY_SEPARATOR.'.env.*') ?: [];
        rsort($files, SORT_STRING);

        foreach (array_slice($files, max(0, $keep)) as $file) {
            @unlink($file);
        }
    }

    /** @return list<string> the names of the kept copies, newest first */
    public static function names(): array
    {
        $files = array_map('basename', glob(self::directory().DIRECTORY_SEPARATOR.'.env.*') ?: []);
        rsort($files, SORT_STRING);

        return array_values($files);
    }
}
