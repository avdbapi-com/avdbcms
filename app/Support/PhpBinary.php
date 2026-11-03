<?php

namespace App\Support;

final class PhpBinary
{
    private const MIN_VERSION_ID = 80300;

    /** What the version probe prints before PHP_VERSION_ID (versionId()): only the number after it is read. */
    private const VERSION_MARK = 'AVDB_PHP_VERSION_ID=';

    /** What the SAPI probe prints before PHP_SAPI (sapi()): only the word after it is read (a PHP may print lines first). */
    private const SAPI_MARK = 'AVDB_PHP_SAPI=';

    /**
     * Added to a real command-line build's score (candidateScore()): a CLI build is strongly preferred for the cron line,
     * the site's own scheduler runs and the queue worker (CLF-SCHED-01). It dwarfs the memory and version terms, so any
     * CLI build outranks any non-CLI one (LiteSpeed's lsphp, a CGI build), which stays usable as a last choice: artisan
     * runs under it, only its output starts with the shebang line a cron line then logs.
     */
    private const CLI_SCORE = 1_000_000;

    /**
     * How long the answer of resolve() is kept (seconds). Working it out runs every candidate binary for its version and
     * extensions: about 1.6 s on Windows, on every visit of Content > Import videos (R3-077, 3.5-4.2 s a page). A kept
     * answer is used only while it is still the answer (stillTheAnswer()); Tools > Health check's Re-probe forgets it
     * (forget()).
     */
    private const RESOLVE_SECONDS = 600;

    /** How long a binary's version, extensions and memory limit are kept: until the file changes, at most a day. */
    private const PROBE_SECONDS = 86400;

    /**
     * How long "no PHP binary was found" and a probe that could not run (exec failed: a process limit reached, PHP being
     * updated by the panel) are kept: tried again soon, never a day.
     */
    private const NONE_SECONDS = 60;

    /**
     * How long this process uses an answer it read from the cache before reading the cache again (a queue worker runs
     * for days: Re-probe and the cache's own times reach it within this time).
     */
    private const MEMO_SECONDS = 60;

    /** @var array<string, array{0: mixed, 1: int}> what this process worked out or read from the cache (and until when), by cache key */
    private static array $memo = [];

    /**
     * Resolve a PHP CLI binary for background Artisan commands. The answer is kept RESOLVE_SECONDS (the configured
     * values of $envKeys and this PHP are part of its key, so a changed setting is looked at at once) and is used only
     * while it is still the answer (stillTheAnswer(): the binary it names still works, and no configured binary before
     * it works now); otherwise it is worked out again at once.
     *
     * @param  array<int, string>  $envKeys
     */
    public static function resolve(array $envKeys = []): ?string
    {
        $configured = [];

        foreach ($envKeys as $envKey) {
            $configured[$envKey] = trim((string) RuntimeEnv::get($envKey, ''));
        }

        $parts = [$configured, (string) PHP_BINARY, PHP_VERSION_ID, PHP_OS_FAMILY];
        $compute = static function () use ($configured): array {
            $path = self::resolveNow($configured);

            // "none found" is kept a short time only
            return $path === null ? ['v' => null, 'ttl' => self::NONE_SECONDS] : ['v' => $path];
        };
        $path = self::remembered('resolve', $parts, self::RESOLVE_SECONDS, $compute);

        if (! self::stillTheAnswer($path, $configured)) {
            self::forgetOne('resolve', $parts);
            $path = self::remembered('resolve', $parts, self::RESOLVE_SECONDS, $compute);
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Is a kept answer of resolve() still the one resolveNow() gives, as far as that shows without probing every
     * candidate again? resolveNow() takes the first configured binary that works, so a configured one before the kept
     * answer that works now (the host fixed it or put it back) makes the answer old; and the binary the answer names
     * must still work: the host may have removed it or changed the program behind the same path (an alternatives link,
     * a panel switching its PHP version in place). A kept binary that does not work any more ran every import inside
     * the admin's request for up to RESOLVE_SECONDS, in new processes too (review of R3-077, AD-R1).
     *
     * Nothing is probed while nothing changed: usable() keeps a binary's probes by its real path, size and time
     * (signature()). PHP's own stat and realpath caches are emptied first, so a long-running process (a queue worker,
     * PHP-FPM, php -S) sees a link that now points elsewhere at once. Whether another candidate became better is left to
     * the answer's own time (RESOLVE_SECONDS) and to Tools > Health check > Re-probe.
     *
     * @param  array<string, string>  $configured  env key => its value, in resolveNow()'s order
     */
    private static function stillTheAnswer(mixed $path, array $configured): bool
    {
        if ($path !== null && (! is_string($path) || $path === '')) {
            return false;
        }

        clearstatcache(true);

        foreach ($configured as $value) {
            if ($path !== null && $value === $path) {
                break;
            }

            if (self::usable($value)) {
                return false;
            }
        }

        return $path === null || self::usable($path);
    }

    /**
     * Forget every kept answer (resolve() and the binaries' versions, extensions and memory limits), e.g. after the host
     * changed its PHP (Tools > Health check > Re-probe).
     */
    public static function forget(): void
    {
        self::$memo = [];
        // a new generation: every key of this class changes (the old values expire by themselves)
        SafeCache::put('php_binary.generation', (string) microtime(true), 31536000);
    }

    /**
     * A value kept in the cache (SafeCache: a store that is down, or a console process in SafeCache::readOnly(), keeps
     * nothing) and for this process. $compute answers ['v' => value] and may say a shorter time to keep it ('ttl').
     *
     * @param  array<int, mixed>  $parts  what the value depends on
     * @param  \Closure(): array{v: mixed, ttl?: int}  $compute
     */
    private static function remembered(string $name, array $parts, int $seconds, \Closure $compute): mixed
    {
        $key = self::key($name, $parts);
        $memo = self::memoKey($key);
        $now = time();

        if (isset(self::$memo[$memo]) && self::$memo[$memo][1] > $now) {
            return self::$memo[$memo][0];
        }

        $cached = SafeCache::get($key);

        if (is_array($cached) && array_key_exists('v', $cached)) {
            self::$memo[$memo] = [$cached['v'], $now + self::MEMO_SECONDS];

            return $cached['v'];
        }

        $computed = $compute();
        $ttl = max(1, (int) ($computed['ttl'] ?? $seconds));
        SafeCache::put($key, ['v' => $computed['v']], $ttl);
        self::$memo[$memo] = [$computed['v'], $now + min($ttl, self::MEMO_SECONDS)];

        return $computed['v'];
    }

    /**
     * How long a binary's probes are kept: until the file changes (its size and time are part of the key), at most
     * PROBE_SECONDS; where PHP may not look at the file (open_basedir) its changes cannot be seen, so only as long as
     * resolve()'s own answer.
     */
    private static function probeSeconds(string $path): int
    {
        return self::looksLikeFilesystemPath($path) && self::canInspectPath($path) ? self::PROBE_SECONDS : self::RESOLVE_SECONDS;
    }

    /** @param  array<int, mixed>  $parts */
    private static function forgetOne(string $name, array $parts): void
    {
        $key = self::key($name, $parts);
        unset(self::$memo[self::memoKey($key)]);
        SafeCache::forget($key);
    }

    /** @param  array<int, mixed>  $parts */
    private static function key(string $name, array $parts): string
    {
        $generation = SafeCache::get('php_binary.generation');

        return 'php_binary.'.$name.'.'.sha1((is_scalar($generation) ? (string) $generation : '0').'|'.json_encode($parts));
    }

    /** The process memo's key: per application (each test makes its own), per cache key. */
    private static function memoKey(string $key): string
    {
        return (function_exists('app') ? spl_object_id(app()) : 0).'|'.$key;
    }

    /**
     * What a binary's probes depend on: the file itself (where PHP may look at it: its real path, size and time), so a
     * PHP the host replaced at the same path is probed again.
     */
    private static function signature(string $path): string
    {
        if (! self::looksLikeFilesystemPath($path) || ! self::canInspectPath($path)) {
            return $path;
        }

        $real = @realpath($path);
        $file = is_string($real) && $real !== '' ? $real : $path;
        $stat = @stat($file);

        return $file.'|'.(is_array($stat) ? $stat['size'].'|'.$stat['mtime'] : '-');
    }

    /**
     * The binary as resolve() worked it out before 1.2.3 (R3-077 keeps its answer): a configured one, else the best of
     * the candidates.
     *
     * @param  array<string, string>  $configured  env key => its value
     */
    private static function resolveNow(array $configured): ?string
    {
        foreach ($configured as $value) {
            if (self::usable($value)) {
                return $value;
            }
        }

        $usable = [];
        foreach (self::candidates() as $candidate) {
            if (self::usable($candidate)) {
                $usable[] = $candidate;
            }
        }

        if ($usable === []) {
            return null;
        }

        usort(
            $usable,
            static fn (string $a, string $b): int => self::candidateScore($b) <=> self::candidateScore($a)
        );

        return $usable[0];
    }

    /**
     * @return array<int, string>
     */
    private static function candidates(): array
    {
        $candidates = [];
        $binary = (string) PHP_BINARY;

        if ($binary !== '') {
            $candidates[] = $binary;

            $directory = dirname($binary);
            foreach (['php', 'php-cli', 'php.exe'] as $name) {
                $candidates[] = $directory.DIRECTORY_SEPARATOR.$name;
            }

            $aaPanelPhp = self::aaPanelCliFromPath($binary);
            if ($aaPanelPhp !== null) {
                array_unshift($candidates, $aaPanelPhp);
            }

            $liteSpeedPhp = self::liteSpeedCliFromPath($binary);
            if ($liteSpeedPhp !== null) {
                array_unshift($candidates, $liteSpeedPhp);
            }
        }

        $versionPath = self::aaPanelCliFromVersionId(PHP_VERSION_ID);
        if ($versionPath !== null) {
            array_unshift($candidates, $versionPath);
        }

        $liteSpeedVersionPath = self::liteSpeedCliFromVersionId(PHP_VERSION_ID);
        if ($liteSpeedVersionPath !== null) {
            array_unshift($candidates, $liteSpeedVersionPath);
        }

        $names = Environment::isWindows()
            ? ['php.exe', 'php']
            : ['php', 'php-cli', 'php8.4', 'php8.3', 'php8.2', 'php8.1', 'php8.0'];

        foreach ($names as $name) {
            $found = self::which($name);
            if ($found !== null) {
                $candidates[] = $found;
            }
        }

        foreach (self::commonPaths() as $path) {
            $candidates[] = $path;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return array<int, string>
     */
    private static function commonPaths(): array
    {
        if (Environment::isWindows()) {
            return [
                'C:\\php\\php.exe',
                'C:\\xampp\\php\\php.exe',
                'C:\\wamp64\\bin\\php\\php8.3.0\\php.exe',
                'D:\\OSPanel\\modules\\php\\PHP_8.4\\php.exe',
                'D:\\OSPanel\\modules\\php\\PHP_8.3\\php.exe',
                'D:\\OSPanel\\modules\\php\\PHP_8.2\\php.exe',
            ];
        }

        return [
            '/usr/local/lsws/lsphp84/bin/php',
            '/usr/local/lsws/lsphp83/bin/php',
            '/usr/local/lsws/lsphp82/bin/php',
            '/usr/local/lsws/lsphp81/bin/php',
            '/opt/1panel/runtime/php/php8/bin/php',
            '/opt/1panel/runtime/php/php8/php/bin/php',
            '/opt/1panel/runtime/php/php/bin/php',
            '/www/server/php/84/bin/php',
            '/www/server/php/83/bin/php',
            '/www/server/php/82/bin/php',
            '/www/server/php/81/bin/php',
            '/www/server/php/80/bin/php',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/bin/php',
            '/opt/homebrew/bin/php',
            '/opt/plesk/php/8.3/bin/php',
            '/opt/plesk/php/8.2/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
        ];
    }

    private static function which(string $name): ?string
    {
        if (! Environment::functionAvailable('exec')) {
            return null;
        }

        // The paths the shell finds (kept RESOLVE_SECONDS, by name and PATH); usable() is asked each time.
        $found = self::remembered('which', [$name, (string) getenv('PATH')], self::RESOLVE_SECONDS, static function () use ($name): array {
            $command = Environment::isWindows() ? 'where' : 'command -v';
            $stderr = Environment::isWindows() ? ' 2>NUL' : ' 2>/dev/null';
            $output = [];
            $status = 1;

            @exec($command.' '.escapeshellarg($name).$stderr, $output, $status);

            return ['v' => $status !== 0 ? [] : array_values(array_filter(array_map(static fn ($line): string => trim((string) $line), $output)))];
        });

        foreach ((array) $found as $path) {
            if (self::usable((string) $path)) {
                return (string) $path;
            }
        }

        return null;
    }

    private static function aaPanelCliFromPath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#/www/server/php/(\d{2})/(?:bin|sbin)/#', $path, $match) !== 1) {
            return null;
        }

        return "/www/server/php/{$match[1]}/bin/php";
    }

    private static function aaPanelCliFromVersionId(int $versionId): ?string
    {
        $major = intdiv($versionId, 10000);
        $minor = intdiv($versionId % 10000, 100);
        if ($major < 8) {
            return null;
        }

        return '/www/server/php/'.$major.$minor.'/bin/php';
    }

    private static function liteSpeedCliFromPath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#/usr/local/lsws/(lsphp\d{2})/bin/#', $path, $match) !== 1) {
            return null;
        }

        return "/usr/local/lsws/{$match[1]}/bin/php";
    }

    private static function liteSpeedCliFromVersionId(int $versionId): ?string
    {
        $major = intdiv($versionId, 10000);
        $minor = intdiv($versionId % 10000, 100);
        if ($major < 8) {
            return null;
        }

        return "/usr/local/lsws/lsphp{$major}{$minor}/bin/php";
    }

    private static function candidateScore(string $path): int
    {
        static $scores = [];

        if (array_key_exists($path, $scores)) {
            return $scores[$path];
        }

        // A real CLI build wins over any non-CLI one (lsphp, CGI): it is the right build for the cron line, the site's
        // own scheduler runs and the queue worker, where a non-CLI build's output starts with the shebang line.
        $score = self::sapi($path) === 'cli' ? self::CLI_SCORE : 0;
        $score += self::memoryScore($path) * 1000;
        $versionId = self::versionId($path);

        if ($versionId !== null) {
            $score += 1000 - min(999, abs($versionId - PHP_VERSION_ID));
        }

        $currentAaPanel = self::aaPanelCliFromVersionId(PHP_VERSION_ID);
        if ($currentAaPanel !== null && self::normalizePath($path) === self::normalizePath($currentAaPanel)) {
            $score += 500;
        }

        $currentLiteSpeed = self::liteSpeedCliFromVersionId(PHP_VERSION_ID);
        if ($currentLiteSpeed !== null && self::normalizePath($path) === self::normalizePath($currentLiteSpeed)) {
            $score += 500;
        }

        if (self::normalizePath($path) === self::normalizePath((string) PHP_BINARY)) {
            $score += 100;
        }

        return $scores[$path] = $score;
    }

    private static function memoryScore(string $path): int
    {
        $bytes = self::memoryLimitBytes($path);
        if ($bytes === null) {
            return 1;
        }

        if ($bytes < 0) {
            return 10;
        }

        if ($bytes >= 512 * 1024 * 1024) {
            return 9;
        }

        if ($bytes >= 256 * 1024 * 1024) {
            return 8;
        }

        if ($bytes >= 192 * 1024 * 1024) {
            return 6;
        }

        if ($bytes >= 128 * 1024 * 1024) {
            return 4;
        }

        return 2;
    }

    private static function memoryLimitBytes(string $path): ?int
    {
        if ($path === (string) PHP_BINARY) {
            return self::parseMemoryLimit((string) ini_get('memory_limit'));
        }

        if (! Environment::functionAvailable('exec')) {
            return null;
        }

        // kept until the binary changes, at most PROBE_SECONDS (R3-077); a probe that could not run only NONE_SECONDS
        return self::remembered('memory', [self::signature($path)], self::probeSeconds($path), static function () use ($path): array {
            $output = [];
            $status = 1;
            @exec(escapeshellarg($path).' -r '.escapeshellarg('echo ini_get("memory_limit");').' 2>&1', $output, $status);

            return $status !== 0 || $output === []
                ? ['v' => null, 'ttl' => self::NONE_SECONDS]
                : ['v' => self::parseMemoryLimit(trim((string) $output[0]))];
        });
    }

    private static function parseMemoryLimit(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($value === '-1') {
            return -1;
        }

        if (! preg_match('/^(\d+)([KMG])?$/i', $value, $match)) {
            return null;
        }

        $bytes = (int) $match[1];
        $unit = strtoupper((string) ($match[2] ?? ''));

        return match ($unit) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }

    public static function usable(?string $path): bool
    {
        $path = trim((string) $path);
        if ($path === '') {
            return false;
        }

        $basename = strtolower(basename($path));
        if (str_contains($basename, 'php-fpm') || str_contains($basename, 'php-cgi')) {
            return false;
        }

        if (self::looksLikeFilesystemPath($path) && self::canInspectPath($path)) {
            if (! @is_file($path)) {
                return false;
            }

            if (! Environment::isWindows() && ! @is_executable($path)) {
                return false;
            }
        }

        return self::meetsRequirements($path);
    }

    private static function meetsRequirements(string $path): bool
    {
        $versionId = self::versionId($path);
        if ($versionId === null || $versionId < self::MIN_VERSION_ID) {
            return false;
        }

        return self::hasRequiredExtensions($path);
    }

    private static function hasRequiredExtensions(string $path): bool
    {
        if ($path === (string) PHP_BINARY) {
            return extension_loaded('mbstring')
                && function_exists('mb_split')
                && extension_loaded('pdo')
                && extension_loaded('json');
        }

        // kept until the binary changes, at most PROBE_SECONDS (R3-077); a probe that could not run only NONE_SECONDS
        return (bool) self::remembered('extensions', [self::signature($path)], self::probeSeconds($path), static function () use ($path): array {
            $code = 'echo (extension_loaded("mbstring") && function_exists("mb_split") && extension_loaded("pdo") && extension_loaded("json")) ? "EXT_OK" : "EXT_FAIL";';
            $output = self::runCliSnippet($path, $code);

            return $output === null || trim($output) === '' ? ['v' => false, 'ttl' => self::NONE_SECONDS] : ['v' => str_contains($output, 'EXT_OK')];
        });
    }

    /**
     * The PHP_SAPI of the build at $path (lower case): 'cli' for a command-line build, 'litespeed' for LiteSpeed's lsphp,
     * 'cgi-fcgi' for a CGI build, etc.; null when it could not be read. Kept like the version and extensions, by the
     * file's size and time. Tools > Scheduled tasks and the "php" status probe show it so the owner sees which build runs
     * the scheduler (CLF-SCHED-01).
     */
    public static function sapiOf(?string $path): ?string
    {
        $path = trim((string) $path);

        return $path === '' ? null : self::sapi($path);
    }

    private static function sapi(string $path): ?string
    {
        // This very process's binary, run as the CLI it is: no probe needed (and no exec in a plain CLI run).
        if ($path === (string) PHP_BINARY && PHP_SAPI === 'cli') {
            return 'cli';
        }

        // kept until the binary changes, at most PROBE_SECONDS (like the version); a probe that could not run only
        // NONE_SECONDS, an answer that is no SAPI (not a PHP binary) as long as resolve()'s own answer
        return self::remembered('sapi', [self::signature($path)], self::probeSeconds($path), static function () use ($path): array {
            // Behind a marker of its own, like the version: a PHP may print warning lines (OPcache ASLR on Windows) first.
            $output = self::runCliSnippet($path, "echo '".self::SAPI_MARK."', PHP_SAPI;");

            if ($output === null || trim($output) === '') {
                return ['v' => null, 'ttl' => self::NONE_SECONDS];
            }

            return preg_match('/'.preg_quote(self::SAPI_MARK, '/').'([A-Za-z0-9_-]+)/', $output, $matches) === 1
                ? ['v' => strtolower($matches[1])]
                : ['v' => null, 'ttl' => self::RESOLVE_SECONDS];
        });
    }

    private static function versionId(string $path): ?int
    {
        if ($path === (string) PHP_BINARY) {
            return PHP_VERSION_ID;
        }

        // kept until the binary changes, at most PROBE_SECONDS (R3-077); a probe that could not run only NONE_SECONDS,
        // an answer that is no version (not a PHP binary) as long as resolve()'s own answer
        return self::remembered('version', [self::signature($path)], self::probeSeconds($path), static function () use ($path): array {
            // The version behind a marker of its own (single quotes: Windows' escapeshellarg() makes double quotes
            // spaces): a PHP may print lines before it. On Windows with OPcache every PHP started from a web request
            // printed "<date> (<pid>): Warning Opcode handlers are unusable due to ASLR ...", and the first 5-6 digit
            // number of the output - the process id - was read as the version (25120: not usable; 81234: "8.1", usable
            // whatever it was), then kept a day (lane AD, pass 2).
            $output = self::runCliSnippet($path, "echo '".self::VERSION_MARK."', PHP_VERSION_ID;");

            if ($output === null || trim($output) === '') {
                return ['v' => null, 'ttl' => self::NONE_SECONDS];
            }

            return preg_match('/'.preg_quote(self::VERSION_MARK, '/').'(\d{5,6})\b/', $output, $matches)
                ? ['v' => (int) $matches[1]]
                : ['v' => null, 'ttl' => self::RESOLVE_SECONDS];
        });
    }

    private static function runCliSnippet(string $path, string $code): ?string
    {
        if (Environment::functionAvailable('exec')) {
            $output = [];
            $status = 1;
            @exec(escapeshellarg($path).' -r '.escapeshellarg($code).' 2>&1', $output, $status);

            if ($status === 0 && ! empty($output)) {
                $cleanLines = array_values(array_filter($output, static function (string $line): bool {
                    $trimmed = trim($line);
                    return ! str_starts_with($trimmed, 'PHP Warning:') && ! str_starts_with($trimmed, 'PHP Deprecated:');
                }));
                return trim(implode("\n", ! empty($cleanLines) ? $cleanLines : $output));
            }
        }

        if (Environment::canRunProcesses()) {
            try {
                $process = new \Symfony\Component\Process\Process([$path, '-r', $code]);
                $process->setTimeout(5);
                $process->run();

                if ($process->isSuccessful()) {
                    $output = explode("\n", $process->getOutput());
                    $cleanLines = array_values(array_filter($output, static function (string $line): bool {
                        $trimmed = trim($line);
                        return ! str_starts_with($trimmed, 'PHP Warning:') && ! str_starts_with($trimmed, 'PHP Deprecated:');
                    }));
                    return trim(implode("\n", ! empty($cleanLines) ? $cleanLines : $output));
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        return null;
    }

    private static function canInspectPath(string $path): bool
    {
        if (! Environment::hasOpenBasedir()) {
            return true;
        }

        $path = self::normalizePath($path);

        foreach (Environment::openBasedirPaths() as $allowed) {
            $allowed = self::normalizePath($allowed === '.' ? base_path() : $allowed);

            if ($path === $allowed || str_starts_with($path, $allowed.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $real = @realpath($path);

        if (is_string($real) && $real !== '') {
            return rtrim(str_replace('\\', '/', $real), '/');
        }

        return rtrim($path, '/');
    }

    private static function looksLikeFilesystemPath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_contains($path, '/')
            || str_contains($path, '\\');
    }
}
