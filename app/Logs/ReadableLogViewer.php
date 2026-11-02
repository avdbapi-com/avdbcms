<?php

namespace App\Logs;

use Closure;
use Opcodes\LogViewer\LogViewerService;
use Throwable;

/**
 * Tools > Logs lists only the log files PHP can read (R1BR-02, Linux hosting).
 *
 * config/log-viewer.php also takes the web server's logs (/var/log/nginx, /var/log/httpd) for the hosts where the site
 * may read them. On a normal host PHP runs as the site's own user, who may list those folders but not open their files
 * (nginx keeps them root:adm 0640): the log viewer listed them anyway, and the first search or list that reached one
 * answered 500 ("Could not open /var/log/nginx/access.log for reading"). Now a folder outside the site that PHP cannot
 * read, or may not look into at all (open_basedir), is left out without being touched, and a file PHP cannot open is
 * not listed. The site's own logs (storage/logs) are listed as before.
 */
class ReadableLogViewer extends LogViewerService
{
    protected function getLaravelLogFilePaths(): array
    {
        $included = config('log-viewer.include_files', []);
        $included = is_array($included) ? $included : [];

        // The package reads its patterns from the configuration: only the reachable ones while it collects the files.
        config(['log-viewer.include_files' => array_filter(
            $included,
            static fn (mixed $alias, int|string $pattern): bool => self::reachable(is_numeric($pattern) ? (string) $alias : (string) $pattern),
            ARRAY_FILTER_USE_BOTH
        )]);

        try {
            $files = parent::getLaravelLogFilePaths();
        } finally {
            config(['log-viewer.include_files' => $included]);
        }

        return array_values(array_filter(
            $files,
            static fn (mixed $file): bool => self::readable(is_array($file) ? (string) ($file[0] ?? '') : (string) $file)
        ));
    }

    /**
     * Whether the log viewer may look at a pattern: one of the site's own logs (a pattern relative to storage/logs, as
     * the package treats every pattern that does not start with the directory separator), or an absolute pattern
     * whose folder (the part before its first wildcard) PHP may read.
     */
    public static function reachable(string $pattern): bool
    {
        if ($pattern === '' || ! str_starts_with($pattern, DIRECTORY_SEPARATOR)) {
            return true;
        }

        $cut = strcspn($pattern, '*?[{');
        $prefix = substr($pattern, 0, $cut);

        if ($cut >= strlen($pattern)) {
            $folder = dirname($pattern);
        } elseif (str_ends_with($prefix, DIRECTORY_SEPARATOR)) {
            $folder = rtrim($prefix, DIRECTORY_SEPARATOR);
        } else {
            $folder = dirname($prefix);
        }

        $folder = $folder === '' ? DIRECTORY_SEPARATOR : $folder;

        return self::quietly(static fn (): bool => is_dir($folder) && is_readable($folder));
    }

    /** Whether PHP can open the file for reading. */
    public static function readable(string $path): bool
    {
        return $path !== '' && self::quietly(static fn (): bool => is_file($path) && is_readable($path));
    }

    /**
     * A check that may warn (a path outside open_basedir) is a plain no: Laravel turns warnings into exceptions, which
     * was the 500 of a site restricted to its own folders.
     *
     * @param  Closure(): bool  $check
     */
    private static function quietly(Closure $check): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $check();
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
