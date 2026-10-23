<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class MediaBinary
{
    public static function ffmpeg(): string
    {
        return self::resolve('FFMPEG_BINARY', 'ffmpeg', 'FFMPEG_PATH');
    }

    public static function ffprobe(): string
    {
        return self::resolve('FFPROBE_BINARY', 'ffprobe', 'FFPROBE_PATH');
    }

    private static function resolve(string $envKey, string $binaryName, ?string $legacyEnvKey = null): string
    {
        static $resolved = [];

        $cacheKey = $envKey.':'.$legacyEnvKey.':'.$binaryName;
        if (isset($resolved[$cacheKey])) {
            return $resolved[$cacheKey];
        }

        foreach (self::candidates($envKey, $binaryName, $legacyEnvKey) as $candidate) {
            if (self::works($candidate)) {
                return $resolved[$cacheKey] = $candidate;
            }
        }

        return $resolved[$cacheKey] = $binaryName;
    }

    private static function candidates(string $envKey, string $binaryName, ?string $legacyEnvKey = null): array
    {
        $candidates = [];
        $configured = trim((string) RuntimeEnv::get($envKey, ''));
        $legacyConfigured = $legacyEnvKey ? trim((string) RuntimeEnv::get($legacyEnvKey, '')) : '';

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        if ($legacyConfigured !== '' && $legacyConfigured !== $configured) {
            $candidates[] = $legacyConfigured;
        }

        $bundled = self::bundledCandidates($binaryName);

        $system = [
            "/usr/bin/{$binaryName}",
            "/usr/local/bin/{$binaryName}",
            "/bin/{$binaryName}",
            "/snap/bin/{$binaryName}",
            "/opt/bin/{$binaryName}",
            "/opt/homebrew/bin/{$binaryName}",
            "C:\\ffmpeg\\bin\\{$binaryName}.exe",
            "C:\\ProgramData\\chocolatey\\bin\\{$binaryName}.exe",
            "C:\\Program Files\\ffmpeg\\bin\\{$binaryName}.exe",
            "C:\\Program Files (x86)\\ffmpeg\\bin\\{$binaryName}.exe",
            "D:\\OSPanel\\modules\\ffmpeg\\{$binaryName}.exe",
            $binaryName,
            "{$binaryName}.exe",
        ];

        foreach ([...$bundled, ...$system] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $candidates[] = trim($candidate);
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function bundledCandidates(string $binaryName): array
    {
        $generic = [
            base_path("bin/{$binaryName}"),
            base_path("bin/{$binaryName}.exe"),
        ];
        $windows = [
            base_path("bin/windows/{$binaryName}.exe"),
            base_path("bin/win64/{$binaryName}.exe"),
            base_path("bin/win-x64/{$binaryName}.exe"),
        ];
        $linuxX64 = [
            base_path("bin/linux-x64/{$binaryName}"),
            base_path("bin/linux-amd64/{$binaryName}"),
            base_path("bin/linux/{$binaryName}"),
        ];
        $linuxArm64 = [
            base_path("bin/linux-arm64/{$binaryName}"),
        ];

        if (DIRECTORY_SEPARATOR === '\\') {
            return [...$windows, ...$generic, ...$linuxX64, ...$linuxArm64];
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $machine = strtolower((string) php_uname('m'));
            $isArm64 = str_contains($machine, 'aarch64') || str_contains($machine, 'arm64');

            return $isArm64
                ? [...$linuxArm64, ...$linuxX64, ...$generic, ...$windows]
                : [...$linuxX64, ...$linuxArm64, ...$generic, ...$windows];
        }

        return [...$generic, ...$linuxX64, ...$linuxArm64, ...$windows];
    }

    public static function canRunProcesses(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');
        if ($disabled !== '') {
            $list = array_map('trim', explode(',', $disabled));
            if (in_array('proc_open', $list, true)) {
                return false;
            }
        }

        return true;
    }

    public static function hasFfmpeg(): bool
    {
        return self::canRunProcesses() && self::works(self::ffmpeg());
    }

    public static function hasFfprobe(): bool
    {
        return self::canRunProcesses() && self::works(self::ffprobe());
    }

    private static function works(string $candidate): bool
    {
        if (!self::canRunProcesses()) {
            return false;
        }

        if (self::looksLikePath($candidate)) {
            if (self::canInspectPath($candidate) && ! @is_file($candidate)) {
                return false;
            }
            self::ensureExecutable($candidate);
        }

        try {
            $process = new Process([$candidate, '-version']);
            $process->setTimeout(8);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ensureExecutable(string $candidate): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || str_ends_with(strtolower($candidate), '.exe')) {
            return;
        }

        if (! self::canInspectPath($candidate)) {
            return;
        }

        if (@is_executable($candidate)) {
            return;
        }

        @chmod($candidate, 0755);
    }

    private static function looksLikePath(string $candidate): bool
    {
        return str_contains($candidate, '/')
            || str_contains($candidate, '\\')
            || preg_match('/^[A-Za-z]:/', $candidate) === 1;
    }

    private static function canInspectPath(string $path): bool
    {
        $openBasedir = (string) ini_get('open_basedir');
        if ($openBasedir === '') {
            return true;
        }

        $normalizedPath = self::normalizePath($path);
        $separator = DIRECTORY_SEPARATOR === '\\' ? ';' : PATH_SEPARATOR;
        $allowedPaths = array_filter(array_map('trim', explode($separator, $openBasedir)));

        foreach ($allowedPaths as $allowedPath) {
            $normalizedAllowed = self::normalizePath($allowedPath);
            if ($normalizedAllowed === '') {
                continue;
            }

            if ($normalizedPath === $normalizedAllowed || str_starts_with($normalizedPath, rtrim($normalizedAllowed, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($path === '.') {
            $path = getcwd() ?: $path;
        }

        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            $path = $real;
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
