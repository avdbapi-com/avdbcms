<?php

namespace App\Support\Updater;

/**
 * Moves the files of an update (or of an undo) into place one by one, journaled, and puts everything back when a move
 * fails or the administrator asks for it.
 *
 * A job names the site (root), the folder the new files come from (source: the update's work folder, or for an undo
 * the update's file backup), the folder the replaced live files go to (backup, as files/<path>), the journal and the
 * list of moves (ops). Each op is {i, phase (pre|main|last), do (put|delete), path, src, old, new}: "put" moves
 * source/src to path, "delete" takes path away; old is the SHA-256 the live file must have before (null: no file),
 * new the one it has after. Every op is marked "begin" and "end" in the journal (one JSON object per line), and every
 * step checks hashes first, so running an op twice, or resuming after the request was killed half-way, is safe.
 * rollback() walks the begun ops backwards: added files go, replaced and deleted files come back from the backup;
 * a file somebody changed since is left alone and listed.
 *
 * EMBEDDED: HookWriter copies this class (and MaintenanceHook) into storage/framework/maintenance.php, which
 * public/index.php runs before vendor/autoload.php while vendor/ may be half replaced. So this file uses PHP
 * built-ins only: no other class, no imports, global classes written with their leading backslash.
 */
final class SwapEngine
{
    /** Laravel's caches of the old code (config, routes, events, packages, services): deleted after the files moved. */
    public const BOOTSTRAP_CACHES = ['config.php', 'routes-v7.php', 'events.php', 'packages.php', 'services.php'];

    /** @var array<string, mixed> */
    private array $job;

    /** @var list<array<string, mixed>>|null */
    private ?array $ops = null;

    /** @var resource|null */
    private $journal = null;

    /**
     * @param  array<string, mixed>  $job  root, source, backup, journal, ops (the list, or a JSON file holding
     *                                      {"ops": [...]}), log (optional step log file), timezone (optional: the
     *                                      time zone of the log's lines)
     */
    public function __construct(array $job)
    {
        foreach (['root', 'source', 'backup', 'journal'] as $key) {
            if (! isset($job[$key]) || ! is_string($job[$key]) || $job[$key] === '') {
                throw new \RuntimeException('The update job has no '.$key.'.');
            }

            $job[$key] = rtrim(str_replace('\\', '/', $job[$key]), '/');
        }

        $this->job = $job;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Close the journal (so it can be renamed or deleted, also on Windows); a later mark opens it again. */
    public function close(): void
    {
        if (is_resource($this->journal)) {
            fclose($this->journal);
        }

        $this->journal = null;
    }

    /** @return list<array<string, mixed>> */
    public function ops(): array
    {
        if ($this->ops !== null) {
            return $this->ops;
        }

        $ops = $this->job['ops'] ?? [];

        if (is_string($ops)) {
            $decoded = json_decode((string) @file_get_contents($ops), true);
            $ops = is_array($decoded) ? ($decoded['ops'] ?? null) : null;
        }

        if (! is_array($ops)) {
            throw new \RuntimeException('The list of files to move cannot be read.');
        }

        foreach ($ops as $op) {
            if (! is_array($op) || ! isset($op['i'], $op['do'], $op['path']) || ! self::safePath((string) $op['path'])
                || ! in_array($op['do'], ['put', 'delete'], true) || ($op['do'] === 'put' && ! self::safePath((string) ($op['src'] ?? '')))) {
                throw new \RuntimeException('The list of files to move is damaged.');
            }
        }

        return $this->ops = array_values($ops);
    }

    /**
     * The journal: op index => its last mark (begin, end or undone).
     *
     * @return array<int, string>
     */
    public function marks(): array
    {
        $file = (string) $this->job['journal'];

        if (! is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('The update journal cannot be read.');
        }

        $marks = [];

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);

            if (is_array($row) && isset($row['i'], $row['s'])) {
                $marks[(int) $row['i']] = (string) $row['s'];
            }
        }

        fclose($handle);

        return $marks;
    }

    /** @return array{done: int, total: int, begun: int, undone: int} */
    public function progress(): array
    {
        $marks = $this->marks();
        $counts = array_count_values($marks);

        return [
            'done' => (int) ($counts['end'] ?? 0),
            'total' => count($this->ops()),
            'begun' => count($marks) - (int) ($counts['undone'] ?? 0),
            'undone' => (int) ($counts['undone'] ?? 0),
        ];
    }

    /**
     * Run the ops (of $phases, or all) that have not ended, in list order, until $deadline (a microtime() value).
     * A move that fails throws; the caller then rolls back.
     *
     * @param  list<string>|null  $phases
     * @return array{state: string, done: int, total: int}
     */
    public function forward(float $deadline, ?array $phases = null): array
    {
        $marks = $this->marks();

        foreach ($this->ops() as $op) {
            $i = (int) $op['i'];

            if (($phases !== null && ! in_array($op['phase'] ?? 'main', $phases, true)) || ($marks[$i] ?? null) === 'end') {
                continue;
            }

            if (($marks[$i] ?? null) === 'undone') {
                throw new \RuntimeException('This update was put back already. Start it again.');
            }

            if (microtime(true) >= $deadline) {
                return ['state' => 'more'] + $this->counts($phases);
            }

            $this->mark($i, 'begin');
            $op['do'] === 'put' ? $this->put($op) : $this->delete($op);
            $this->mark($i, 'end');
        }

        return ['state' => 'done'] + $this->counts($phases);
    }

    /**
     * Put back every op that began, newest first, until $deadline. Safe to run again; it only moves files whose hash
     * shows they are still the update's.
     *
     * @return array{state: string, left: int, kept: list<string>}
     */
    public function rollback(float $deadline): array
    {
        $marks = $this->marks();
        $kept = [];
        $left = 0;

        foreach (array_reverse($this->ops()) as $op) {
            $i = (int) $op['i'];

            if (! isset($marks[$i]) || $marks[$i] === 'undone') {
                continue;
            }

            if (microtime(true) >= $deadline) {
                $left++;

                continue;
            }

            $this->revert($op, $kept);

            try {
                $this->mark($i, 'undone');
            } catch (\Throwable) {
                // A journal that cannot be written (disk full) must not stop the files from coming back; the hashes
                // tell what is done when this runs again.
            }
        }

        return ['state' => $left > 0 ? 'more' : 'done', 'left' => $left, 'kept' => $kept];
    }

    /**
     * After the last move (or the last move back): delete Laravel's compiled caches of the old code and the compiled
     * Blade views (job 'views', optional), then drop the moved PHP files (and the deleted caches) from OPcache and
     * reset it where the host allows that. When OPcache could not be refreshed for every file, 'wait' > 0 where it
     * rereads changed files every revalidate_freq seconds, and 'opcache' is 'stale' where it does not check time stamps.
     *
     * @return array{caches: int, views: int, invalidated: int, failed: int, reset: bool, wait: int, opcache: string}
     */
    public function settle(): array
    {
        $cleared = 0;

        foreach (self::BOOTSTRAP_CACHES as $name) {
            $file = $this->job['root'].'/bootstrap/cache/'.$name;

            if (is_file($file) && @unlink($file)) {
                $cleared++;
            }
        }

        // Blade recompiles a template only when it is newer than its compiled copy: the moves keep (or, for an undo,
        // bring back) older times, so the compiled views of the other version would go on being served.
        $views = 0;
        $folder = is_string($this->job['views'] ?? null) ? rtrim(str_replace('\\', '/', $this->job['views']), '/') : '';

        if ($folder !== '' && is_dir($folder)) {
            foreach (scandir($folder) ?: [] as $name) {
                if (substr($name, -4) === '.php' && is_file($folder.'/'.$name) && @unlink($folder.'/'.$name)) {
                    $views++;
                }
            }
        }

        $opcache = $this->refreshOpcache();
        $this->log('caches cleared: '.$cleared.', compiled views: '.$views.'; opcache: '.$opcache['opcache'].' (invalidated '.$opcache['invalidated'].', failed '.$opcache['failed'].')');

        return ['caches' => $cleared, 'views' => $views] + $opcache;
    }

    /**
     * Drop the moved PHP files (and Laravel's bootstrap caches) from OPcache and reset it where the host allows that.
     * 'opcache' says how that went: off, reset, invalidated, wait (it rereads changed files after 'wait' seconds) or
     * stale (old files may stay in memory until PHP restarts). settle() runs it after the moves; Finish again when the
     * swap's was stale.
     *
     * @return array{invalidated: int, failed: int, reset: bool, wait: int, opcache: string}
     */
    public function refreshOpcache(): array
    {
        $state = self::opcache();
        $invalidated = 0;
        $failed = 0;

        if ($state['invalidate']) {
            $files = [];

            foreach ($this->ops() as $op) {
                if (substr((string) $op['path'], -4) === '.php') {
                    $files[] = $this->job['root'].'/'.$op['path'];
                }
            }

            foreach (self::BOOTSTRAP_CACHES as $name) {
                $files[] = $this->job['root'].'/bootstrap/cache/'.$name;
            }

            foreach ($files as $file) {
                // A file that is gone is still dropped from the cache, but the call then answers false.
                $exists = is_file($file);
                $done = @\opcache_invalidate($file, true) === true;

                if ($exists) {
                    $done ? $invalidated++ : $failed++;
                }
            }
        }

        $reset = $state['reset'] && @\opcache_reset() === true;
        $refreshed = ! $state['enabled'] || $reset || ($state['invalidate'] && $failed === 0);
        $wait = ! $refreshed && $state['validate_timestamps'] ? $state['revalidate_freq'] + 1 : 0;
        $summary = ! $state['enabled'] ? 'off' : ($reset ? 'reset' : ($refreshed ? 'invalidated' : ($wait > 0 ? 'wait' : 'stale')));

        return ['invalidated' => $invalidated, 'failed' => $failed, 'reset' => $reset, 'wait' => $wait, 'opcache' => $summary];
    }

    /**
     * What this PHP lets the updater do with OPcache: enabled for this kind of request (the extension and its ini
     * switch: opcache_get_status() is often disabled on shared hosts while OPcache runs), opcache_invalidate() and
     * opcache_reset() callable (not in disable_functions, not blocked by opcache.restrict_api), and whether changed
     * files are picked up by their time stamps.
     *
     * @return array{enabled: bool, invalidate: bool, reset: bool, validate_timestamps: bool, revalidate_freq: int}
     */
    public static function opcache(): array
    {
        $enabled = \extension_loaded('Zend OPcache')
            && filter_var(\ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $disabled = array_map('strtolower', array_map('trim', explode(',', (string) \ini_get('disable_functions'))));
        $exists = fn (string $name): bool => \function_exists($name) && ! in_array($name, $disabled, true);
        // opcache.restrict_api is compared with the script PHP started, not with $_SERVER['SCRIPT_FILENAME'] (the entry
        // files rewrite it): a call that changes nothing (this file, not forced) answers true only where the API may run.
        $invalidate = $enabled && $exists('opcache_invalidate') && @\opcache_invalidate(__FILE__, false) === true;
        $restrict = str_replace('\\', '/', (string) \ini_get('opcache.restrict_api'));
        $script = str_replace('\\', '/', (string) (\get_included_files()[0] ?? ''));
        $allowed = $invalidate || $restrict === '' || ($script !== '' && str_starts_with($script, $restrict));

        return [
            'enabled' => $enabled,
            'invalidate' => $invalidate,
            'reset' => $enabled && $allowed && $exists('opcache_reset'),
            'validate_timestamps' => filter_var(\ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN),
            'revalidate_freq' => max(0, (int) \ini_get('opcache.revalidate_freq')),
        ];
    }

    /** SHA-256 of a regular file, or null when there is none (missing, a folder, unreadable). */
    public static function hash(string $file): ?string
    {
        clearstatcache(true, $file);

        if (! is_file($file)) {
            return null;
        }

        $hash = @hash_file('sha256', $file);

        return is_string($hash) ? $hash : null;
    }

    /** A relative path with forward slashes and no ".", ".." or empty parts: it can only point inside its folder. */
    public static function safePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || preg_match('/[\x00-\x1F\x7F\\\\:]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $op */
    private function put(array $op): void
    {
        $path = (string) $op['path'];
        $target = $this->job['root'].'/'.$path;
        $source = $this->job['source'].'/'.$op['src'];
        $backup = $this->job['backup'].'/files/'.$path;
        $now = self::hash($target);

        if ($now !== null && $now === $op['new']) {
            // Already in place: this op ran before the request that did it was cut off.
            return;
        }

        // The new copy first: a missing or damaged one stops the swap before this live file moves (a damaged file
        // moved into place would be neither the old nor the new one, and the way back would have to leave it there).
        if (self::hash($source) !== $op['new']) {
            throw new \RuntimeException('The new copy of '.$path.' is missing or damaged.');
        }

        if ($now !== null) {
            if ($now !== $op['old']) {
                throw new \RuntimeException($path.' changed while the update was running.');
            }

            $this->move($target, $backup, $now);
        } elseif ($op['old'] !== null && self::hash($backup) !== $op['old']) {
            // The live file is gone: an earlier try of this op moved it to the backup, where it must be.
            throw new \RuntimeException($path.' is missing and its backup copy is not there.');
        }

        $this->move($source, $target, (string) $op['new']);
        self::fresh($target);
    }

    /** @param  array<string, mixed>  $op */
    private function delete(array $op): void
    {
        $path = (string) $op['path'];
        $target = $this->job['root'].'/'.$path;
        $now = self::hash($target);

        if ($now === null) {
            return;
        }

        if ($now !== $op['old']) {
            throw new \RuntimeException($path.' changed while the update was running.');
        }

        $this->move($target, $this->job['backup'].'/files/'.$path, $now);
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  list<string>  $kept
     */
    private function revert(array $op, array &$kept): void
    {
        $path = (string) $op['path'];
        $target = $this->job['root'].'/'.$path;
        $backup = $this->job['backup'].'/files/'.$path;
        $now = self::hash($target);

        if ($op['do'] === 'put' && $now !== null && $now === $op['new']) {
            // The update's file goes back where it came from (a later try can use it again), or away.
            $source = $this->job['source'].'/'.$op['src'];

            if (! file_exists($source)) {
                $this->move($target, $source, $now);
            } elseif (! @unlink($target)) {
                throw new \RuntimeException('Cannot remove '.$path.'.');
            }

            $now = null;
        }

        if ($now !== null && $now !== $op['old']) {
            // Changed (or made again) by somebody after the move: not ours to touch.
            $kept[] = $path;

            return;
        }

        if ($now === null && $op['old'] !== null && self::hash($backup) === $op['old']) {
            $this->move($backup, $target, (string) $op['old']);
            self::fresh($target);
        }
    }

    /**
     * Move a file whose SHA-256 the caller just checked and make sure it arrived whole: rename() (same disk, instant,
     * the size must stay the same), else copy to a temporary name next to the target, check its hash, rename that into
     * place and delete the original. A file that did not arrive whole goes back where it came from. Folders are made
     * as needed.
     */
    private function move(string $from, string $to, string $hash): void
    {
        $folder = dirname($to);

        if (! self::makeFolder($folder)) {
            throw new \RuntimeException('Cannot create the folder '.$this->relative($folder).'.');
        }

        if (file_exists($to) && ! @unlink($to)) {
            throw new \RuntimeException('Cannot replace '.$this->relative($to).'.');
        }

        clearstatcache(true, $from);
        $size = @filesize($from);

        if (@rename($from, $to)) {
            clearstatcache(true, $to);

            if (! is_file($to) || @filesize($to) !== $size) {
                @rename($to, $from);

                throw new \RuntimeException($this->relative($to).' did not arrive whole.');
            }

            return;
        }

        $temporary = $to.'.avdbcms-tmp';

        if (! @copy($from, $temporary) || self::hash($temporary) !== $hash || ! @rename($temporary, $to)) {
            @unlink($temporary);

            throw new \RuntimeException('Cannot move '.$this->relative($from).'. The disk may be full or the folder read-only.');
        }

        // chmod() may be in disable_functions (then it does not exist at all and calling it would throw).
        if (\function_exists('chmod')) {
            @\chmod($to, @fileperms($from) & 0777 ?: 0644);
        }

        @unlink($from);
    }

    /**
     * Make a folder and the missing ones above it, each 0755 whatever the umask (a restrictive umask would leave new
     * folders under public/ unreadable to the web server).
     */
    public static function makeFolder(string $folder): bool
    {
        $missing = [];

        for ($at = $folder; ! is_dir($at) && dirname($at) !== $at; $at = dirname($at)) {
            $missing[] = $at;
        }

        foreach (array_reverse($missing) as $at) {
            if (! @mkdir($at, 0755) && ! is_dir($at)) {
                return false;
            }

            if (\function_exists('chmod')) {
                @\chmod($at, 0755);
            }
        }

        return true;
    }

    /**
     * A file moved into the site gets the time of the move: rename() keeps the time the file had, and Blade recompiles
     * a template only when it is newer than its compiled copy (an undo puts back files with old times).
     */
    private static function fresh(string $file): void
    {
        if (\function_exists('touch')) {
            @\touch($file);
        }
    }

    private function mark(int $i, string $state): void
    {
        if ($this->journal === null) {
            $folder = dirname((string) $this->job['journal']);

            if (! is_dir($folder)) {
                @mkdir($folder, 0755, true);
            }

            $handle = @fopen((string) $this->job['journal'], 'ab');

            if ($handle === false) {
                throw new \RuntimeException('The update journal cannot be written.');
            }

            $this->journal = $handle;
        }

        $line = json_encode(['i' => $i, 's' => $state, 't' => time()])."\n";

        if (@fwrite($this->journal, $line) !== strlen($line) || ! @fflush($this->journal)) {
            throw new \RuntimeException('The update journal cannot be written. The disk may be full.');
        }
    }

    /** @return array{done: int, total: int} */
    private function counts(?array $phases): array
    {
        $marks = $this->marks();
        $done = 0;
        $total = 0;

        foreach ($this->ops() as $op) {
            if ($phases === null || in_array($op['phase'] ?? 'main', $phases, true)) {
                $total++;
                $done += ($marks[(int) $op['i']] ?? null) === 'end' ? 1 : 0;
            }
        }

        return ['done' => $done, 'total' => $total];
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        foreach (['root', 'source', 'backup'] as $key) {
            if (str_starts_with($path, $this->job[$key].'/')) {
                return substr($path, strlen($this->job[$key]) + 1);
            }
        }

        return basename($path);
    }

    private function log(string $message): void
    {
        $file = $this->job['log'] ?? null;

        if (is_string($file) && $file !== '') {
            @file_put_contents($file, self::stamp(is_string($this->job['timezone'] ?? null) ? $this->job['timezone'] : null)."\tswap\t".$message."\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * The time now in $zone (the site's time zone, which the maintenance hook gets in its config: without Laravel,
     * PHP would use php.ini's date.timezone), or in PHP's own zone when none or an unknown one is given.
     */
    public static function stamp(?string $zone, string $format = 'Y-m-d H:i:s'): string
    {
        if (is_string($zone) && $zone !== '') {
            try {
                return (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format($format);
            } catch (\Throwable) {
                // An unknown zone: PHP's own below.
            }
        }

        return date($format);
    }
}
