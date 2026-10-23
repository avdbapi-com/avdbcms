<?php

namespace App\Support;

final class PhpBinary
{
    private const MIN_VERSION_ID = 80300;

    /**
     * Resolve a PHP CLI binary for background Artisan commands.
     *
     * @param  array<int, string>  $envKeys
     */
    public static function resolve(array $envKeys = []): ?string
    {
        foreach ($envKeys as $envKey) {
            $configured = trim((string) RuntimeEnv::get($envKey, ''));
            if (self::usable($configured)) {
                return $configured;
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

        $command = Environment::isWindows() ? 'where' : 'command -v';
        $stderr = Environment::isWindows() ? ' 2>NUL' : ' 2>/dev/null';
        $output = [];
        $status = 1;

        @exec($command.' '.escapeshellarg($name).$stderr, $output, $status);

        if ($status !== 0 || $output === []) {
            return null;
        }

        foreach ($output as $line) {
            $path = trim((string) $line);
            if (self::usable($path)) {
                return $path;
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

        $score = self::memoryScore($path) * 1000;
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
        static $limits = [];

        if (array_key_exists($path, $limits)) {
            return $limits[$path];
        }

        if ($path === (string) PHP_BINARY) {
            return $limits[$path] = self::parseMemoryLimit((string) ini_get('memory_limit'));
        }

        if (! Environment::functionAvailable('exec')) {
            return null;
        }

        $output = [];
        $status = 1;
        @exec(escapeshellarg($path).' -r '.escapeshellarg('echo ini_get("memory_limit");').' 2>&1', $output, $status);

        if ($status !== 0 || $output === []) {
            return $limits[$path] = null;
        }

        return $limits[$path] = self::parseMemoryLimit(trim((string) $output[0]));
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
        static $extensions = [];

        if (array_key_exists($path, $extensions)) {
            return $extensions[$path];
        }

        if ($path === (string) PHP_BINARY) {
            return $extensions[$path] = (
                extension_loaded('mbstring')
                && function_exists('mb_split')
                && extension_loaded('pdo')
                && extension_loaded('json')
            );
        }

        $code = 'echo (extension_loaded("mbstring") && function_exists("mb_split") && extension_loaded("pdo") && extension_loaded("json")) ? "EXT_OK" : "EXT_FAIL";';
        $output = self::runCliSnippet($path, $code);

        return $extensions[$path] = ($output !== null && str_contains($output, 'EXT_OK'));
    }

    private static function versionId(string $path): ?int
    {
        static $versions = [];

        if (array_key_exists($path, $versions)) {
            return $versions[$path];
        }

        if ($path === (string) PHP_BINARY) {
            return $versions[$path] = PHP_VERSION_ID;
        }

        $output = self::runCliSnippet($path, 'echo PHP_VERSION_ID;');
        if ($output === null || ! preg_match('/\b(\d{5,6})\b/', $output, $matches)) {
            return $versions[$path] = null;
        }

        return $versions[$path] = (int) $matches[1];
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
