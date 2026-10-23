<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Throwable;

final class EnvironmentRepairer
{
    /**
     * Lightweight repairs that are safe to run during normal HTTP boot.
     *
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    public static function repairRuntimeFilesystem(): array
    {
        $repairer = new self();

        return $repairer->repairDirectories();
    }

    /**
     * Full app-owned repair pass for `app:doctor --fix` and admin preflight.
     *
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    public static function repairInstallation(): array
    {
        $repairer = new self();

        return [
            ...$repairer->repairOwnership(),
            ...$repairer->repairDirectories(),
            ...$repairer->repairFilePermissions(),
            ...$repairer->repairStorageLinks(),
            ...$repairer->repairAppKey(),
            ...$repairer->repairNginxRewrite(),
            ...$repairer->repairOpenBasedir(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function requiredDirectories(): array
    {
        return [
            'storage' => storage_path(),
            'storage/app' => storage_path('app'),
            'storage/app/private' => storage_path('app/private'),
            'storage/app/public' => storage_path('app/public'),
            'storage/framework' => storage_path('framework'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/cache/data' => storage_path('framework/cache/data'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
    }

    /**
     * Auto-fix runtime directory ownership using exec() when available.
     *
     * On aaPanel, files extracted by the file manager are owned by root:root.
     * PHP-FPM runs as www:www and can't write. This uses exec() to chown
     * the critical directories to the correct user automatically. Repairs are
     * shallow so imported media storage is never scanned from a web request.
     *
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    private function repairOwnership(): array
    {
        if (!Environment::isLinux()) {
            return [];
        }

        // Check if we actually need to fix ownership.
        $directories = self::requiredDirectories();
        $basePath = base_path();

        $needsRepair = false;
        foreach ($directories as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $needsRepair = true;
                break;
            }
        }

        if (! $needsRepair) {
            return []; // Already writable, no fix needed
        }

        $results = [];

        // Try using exec() to fix ownership
        $canExec = Environment::functionAvailable('exec');
        if (!$canExec) {
            return [$this->result(
                'ownership',
                'storage/ or bootstrap/cache/ is not writable and exec() is disabled. '
                . 'Run the safe runtime-directory permission command shown by the installer.',
                'warn'
            )];
        }

        // Detect the PHP-FPM user
        $phpUser = $this->detectPhpUser();
        if ($phpUser === '' || $phpUser === 'root') {
            return [];
        }

        // Try shallow chown on runtime directories only.
        $paths = array_map('escapeshellarg', array_values($directories));

        $cmd = 'chown ' . escapeshellarg($phpUser . ':' . $phpUser) . ' ' . implode(' ', $paths) . ' 2>&1';
        $output = [];
        $status = 1;
        @exec($cmd, $output, $status);

        if ($status === 0) {
            // Also fix permissions, shallow only.
            @exec('chmod 775 ' . implode(' ', $paths) . ' 2>&1');

            // Also try to make .env writable
            $envPath = base_path('.env');
            if (is_file($envPath) && !is_writable($envPath)) {
                @exec('chown ' . escapeshellarg($phpUser . ':' . $phpUser) . ' ' . escapeshellarg($envPath) . ' 2>&1');
                @exec('chmod 664 ' . escapeshellarg($envPath) . ' 2>&1');
            }

            // Also try to make the project root writable for .env creation
            if (!is_file($envPath) && !is_writable($basePath)) {
                @exec('chmod 775 ' . escapeshellarg($basePath) . ' 2>&1');
            }

            $results[] = $this->result('ownership', 'Fixed runtime directory ownership to ' . $phpUser . ':' . $phpUser . '.', 'success');
        } else {
            // chown failed — try chmod 777 as last resort
            @exec('chmod 777 ' . implode(' ', $paths) . ' 2>&1', $chmodOutput, $chmodStatus);
            if ($chmodStatus === 0) {
                $results[] = $this->result('ownership', 'Set 777 permissions on runtime directories as fallback.', 'success');
            } else {
                $results[] = $this->result(
                    'ownership',
                    'Could not fix runtime directory ownership. Run the safe runtime-directory permission command shown by the installer.',
                    'warn'
                );
            }
        }

        clearstatcache(true);
        return $results;
    }

    /**
     * Detect the user PHP-FPM is running as.
     */
    private function detectPhpUser(): string
    {
        // Method 1: posix functions
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return $info['name'];
            }
        }

        // Method 2: whoami command
        if (Environment::functionAvailable('exec')) {
            $user = trim((string) @exec('whoami 2>/dev/null'));
            if ($user !== '') {
                return $user;
            }
        }

        // Method 3: Known panel users
        foreach (['www', 'www-data', 'apache', 'nginx', 'nobody'] as $candidate) {
            $output = [];
            $status = 1;
            if (Environment::functionAvailable('exec')) {
                @exec('id ' . escapeshellarg($candidate) . ' 2>/dev/null', $output, $status);
                if ($status === 0) {
                    return $candidate;
                }
            }
        }

        // Default for aaPanel
        return 'www';
    }

    /**
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    private function repairDirectories(): array
    {
        $results = [];

        foreach (self::requiredDirectories() as $label => $path) {
            if (! is_dir($path)) {
                if (@mkdir($path, 0775, true)) {
                    $results[] = $this->result('filesystem', "Created missing directory: {$label}", 'success');
                } else {
                    $results[] = $this->result('filesystem', "Could not create directory: {$label}", 'warn');
                    continue;
                }
            }

            if (! is_writable($path)) {
                @chmod($path, 0775);
            }

            if (! is_writable($path)) {
                $results[] = $this->result('filesystem', "Directory is not writable: {$label}", 'warn');
            }
        }

        return $results;
    }

    /**
     * Enforce standard Linux web server permissions:
     * - Files: 644 (rw-r--r--)
     * - Directories: 755 (rwxr-xr-x)
     * - Artisan: 755 (executable)
     * - Storage & bootstrap/cache: 775/777
     *
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    public function repairFilePermissions(): array
    {
        if (!Environment::isLinux()) {
            return [];
        }

        $results = [];
        $base = escapeshellarg(base_path());

        if (Environment::functionAvailable('exec')) {
            // Set standard directories to 755 (excluding storage to preserve runtime write permissions)
            @exec("find {$base} -maxdepth 4 -type d ! -path '*/.git*' ! -path '*/storage*' ! -path '*/bootstrap/cache*' -exec chmod 755 {} + 2>/dev/null");

            // Set standard files to 644 (excluding storage and artisan)
            @exec("find {$base} -maxdepth 4 -type f ! -path '*/.git*' ! -path '*/storage*' ! -path '*/bootstrap/cache*' ! -name 'artisan' -exec chmod 644 {} + 2>/dev/null");

            // Ensure artisan executable is 755
            $artisan = base_path('artisan');
            if (is_file($artisan)) {
                @chmod($artisan, 0755);
            }

            // Ensure .env is 644 or 664 if present
            $envPath = base_path('.env');
            if (is_file($envPath)) {
                @chmod($envPath, 0664);
            }

            $results[] = $this->result(
                'permissions',
                'Standard file permissions applied (files: 644, directories: 755, artisan: 755).',
                'success'
            );
        } else {
            // PHP fallback for essential root folders and files
            foreach (['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes'] as $folder) {
                $dirPath = base_path($folder);
                if (is_dir($dirPath)) {
                    @chmod($dirPath, 0755);
                }
            }
            $artisan = base_path('artisan');
            if (is_file($artisan)) {
                @chmod($artisan, 0755);
            }
            $envPath = base_path('.env');
            if (is_file($envPath)) {
                @chmod($envPath, 0664);
            }
            $results[] = $this->result(
                'permissions',
                'Core directory and file permissions normalized.',
                'success'
            );
        }

        return $results;
    }

    /**
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    private function repairStorageLinks(): array
    {
        $results = [];
        $links = (array) config('filesystems.links', []);

        foreach ($links as $link => $target) {
            $link = (string) $link;
            $target = (string) $target;
            $label = $this->relativePath($link);
            $targetLabel = $this->relativePath($target);

            if ($target === '' || $link === '') {
                continue;
            }

            if (! is_dir($target)) {
                if (@mkdir($target, 0775, true)) {
                    $results[] = $this->result('filesystem', "Created missing storage target: {$targetLabel}", 'success');
                } else {
                    $results[] = $this->result('filesystem', "Could not create storage target: {$targetLabel}", 'warn');
                    continue;
                }
            }

            if (file_exists($link) || is_link($link)) {
                if ($this->linkPointsTo($link, $target)) {
                    continue;
                }

                if (! $this->replacePlaceholderDirectory($link)) {
                    $results[] = $this->result(
                        'storage.link',
                        "Cannot create {$label} because that path already exists and points somewhere else.",
                        'warn'
                    );
                    continue;
                }
            }

            $parent = dirname($link);
            if (! is_dir($parent) && ! @mkdir($parent, 0775, true)) {
                $results[] = $this->result('storage.link', "Could not create parent directory for {$label}.", 'warn');
                continue;
            }

            $createdLink = false;
            if (Environment::functionAvailable('symlink')) {
                $createdLink = @\symlink($target, $link);
            }

            if (! $createdLink) {
                $createdLink = $this->createWindowsJunction($link, $target);
            }

            if ($createdLink) {
                $results[] = $this->result('storage.link', "Created storage link: {$label} -> {$targetLabel}", 'success');
                continue;
            }

            $this->restorePlaceholderDirectory($link);

            $results[] = $this->result(
                'storage.link',
                "Could not create {$label}. The host may block symlink creation; run `php artisan storage:link` with the web user.",
                'warn'
            );
        }

        return $results;
    }

    /**
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    private function repairAppKey(): array
    {
        $current = trim((string) (RuntimeEnv::get('APP_KEY', '') ?: config('app.key', '')));
        if ($current !== '') {
            return [];
        }

        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            $example = base_path('.env.example');
            if (is_file($example) && is_readable($example)) {
                if (! @copy($example, $envPath)) {
                    return [$this->result('app.key', 'APP_KEY is empty and .env could not be created from .env.example.', 'warn')];
                }
            } else {
                return [$this->result('app.key', 'APP_KEY is empty and no .env file exists.', 'warn')];
            }
        }

        if (! is_writable($envPath) || ! is_readable($envPath)) {
            return [$this->result('app.key', 'APP_KEY is empty but .env is not readable and writable.', 'warn')];
        }

        try {
            $key = 'base64:' . base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
            $contents = (string) file_get_contents($envPath);

            if (preg_match('/^APP_KEY=.*$/m', $contents)) {
                $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents) ?? $contents;
            } else {
                $contents = rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;
            }

            file_put_contents($envPath, $contents, LOCK_EX);
            Config::set('app.key', $key);

            $results = [$this->result('app.key', 'Generated missing APP_KEY in .env.', 'success')];
            $configCache = base_path('bootstrap/cache/config.php');
            if (is_file($configCache) && is_writable($configCache) && @unlink($configCache)) {
                $results[] = $this->result('config.cache', 'Cleared stale cached config after generating APP_KEY.', 'success');
            }

            return $results;
        } catch (Throwable $e) {
            return [$this->result('app.key', 'Could not generate APP_KEY: ' . $e->getMessage(), 'warn')];
        }
    }

    /**
     * Auto-write the Laravel try_files rewrite rule for aaPanel + Nginx.
     *
     * aaPanel stores per-site Nginx rewrite rules under:
     *   /www/server/panel/vhost/rewrite/<domain>.conf
     *
     * This file is included INSIDE the server {} block. When it's empty,
     * pretty URLs like /install, /admin, /video/xxx won't work — only
     * /index.php works. This method writes the location block automatically.
     *
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    private function repairNginxRewrite(): array
    {
        // Only run on aaPanel + Nginx
        if (Environment::panel() !== Environment::PANEL_AAPANEL) {
            return [];
        }

        if (Environment::webServer() !== Environment::WEB_NGINX) {
            return [];
        }

        // Detect the rewrite config file path from the site domain
        $rewriteDir = '/www/server/panel/vhost/rewrite';
        if (! $this->canInspectPath($rewriteDir) || ! @is_dir($rewriteDir)) {
            return [];
        }

        $domain = $this->detectSiteDomain();
        if ($domain === '') {
            return [];
        }

        $rewriteFile = $rewriteDir . '/' . $domain . '.conf';

        // Read current content — only modify if empty or has default aaPanel content
        if (! $this->canInspectPath($rewriteFile)) {
            return [];
        }

        $currentContent = @is_file($rewriteFile) ? trim((string) @file_get_contents($rewriteFile)) : '';

        $laravelRewrite = <<<'NGINX'
# AVDBClip auto-configured Laravel rewrite rule.
# This was written automatically by the installer.
# It makes pretty URLs work (e.g. /install, /admin, /video/xxx).
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
NGINX;

        // If the rewrite file already contains try_files, don't touch it
        if (str_contains($currentContent, 'try_files')) {
            return [];
        }

        // If it contains a "server" directive, someone pasted the wrong config — replace it
        if (str_contains($currentContent, 'server {') || str_contains($currentContent, 'server{')) {
            if (@file_put_contents($rewriteFile, $laravelRewrite . PHP_EOL)) {
                $this->reloadNginx();
                return [$this->result(
                    'nginx.rewrite',
                    'Replaced invalid server{} block in Nginx rewrite file with Laravel try_files rule. '
                    . 'aaPanel rewrite files cannot contain server{} blocks — that causes "server directive is not allowed here".',
                    'success'
                )];
            }

            return [$this->result('nginx.rewrite', 'Cannot write to Nginx rewrite file: ' . $rewriteFile, 'warn')];
        }

        // Empty or default — write our rewrite
        if ($currentContent === '' || ! str_contains($currentContent, 'location')) {
            if (@file_put_contents($rewriteFile, $laravelRewrite . PHP_EOL)) {
                $this->reloadNginx();
                return [$this->result(
                    'nginx.rewrite',
                    'Wrote Laravel URL rewrite rule to ' . $rewriteFile,
                    'success'
                )];
            }

            return [$this->result('nginx.rewrite', 'Cannot write to Nginx rewrite file: ' . $rewriteFile, 'warn')];
        }

        return [];
    }

    /**
     * Disable aaPanel's restrictive open_basedir that blocks Laravel.
     *
     * aaPanel's "Anti-XSS attack" feature sets open_basedir to restrict
     * PHP to just the site directory. When the Running Directory is /public,
     * PHP cannot read vendor/, config/, storage/, etc. — causing 500 errors.
     *
     * This writes open_basedir=none to the aaPanel site config.
     *
     * @return array<int, array{scope:string,message:string,severity:string}>
     */
    private function repairOpenBasedir(): array
    {
        // Only run when open_basedir is actively restricting
        if (! Environment::hasOpenBasedir()) {
            return [];
        }

        // Only auto-fix on aaPanel
        if (Environment::panel() !== Environment::PANEL_AAPANEL) {
            return [];
        }

        // Check if the restriction is actually blocking Laravel paths
        $basePath = rtrim(str_replace('\\', '/', base_path()), '/');
        $openPaths = Environment::openBasedirPaths();
        $canAccessBase = false;

        foreach ($openPaths as $allowed) {
            if (str_starts_with($basePath, $allowed) || str_starts_with($allowed, $basePath)) {
                $canAccessBase = true;
                break;
            }
        }

        if ($canAccessBase) {
            return []; // open_basedir allows the project root, no fix needed
        }

        $domain = $this->detectSiteDomain();

        // Try to fix via the aaPanel open_basedir config files
        // aaPanel stores open_basedir in the PHP config or per-site vhost
        // Try to write a .user.ini that overrides it
        $userIni = base_path('.user.ini');
        $userIniPublic = public_path('.user.ini');
        $override = 'open_basedir=none';

        $wrote = false;
        foreach ([$userIniPublic, $userIni] as $path) {
            $dir = dirname($path);
            if (! is_dir($dir) || ! is_writable($dir)) {
                continue;
            }

            $existing = is_file($path) ? (string) @file_get_contents($path) : '';
            if (str_contains($existing, 'open_basedir')) {
                continue; // already has an open_basedir line
            }

            $content = rtrim($existing) . ($existing === '' ? '' : PHP_EOL) . $override . PHP_EOL;
            if (@file_put_contents($path, $content)) {
                $wrote = true;
            }
        }

        if ($wrote) {
            return [$this->result(
                'open_basedir',
                'Wrote open_basedir=none to .user.ini to override aaPanel restriction. '
                . 'For a permanent fix, disable "Anti-XSS attack" in Website > Directory.',
                'success'
            )];
        }

        $domainHint = $domain !== '' ? $domain : 'your-domain';
        return [$this->result(
            'open_basedir',
            'open_basedir is restricting PHP. Disable "Anti-XSS attack (open_basedir)" in aaPanel: '
            . 'Website > ' . $domainHint . ' > Directory > Anti-XSS attack → OFF.',
            'warn'
        )];
    }

    /**
     * Detect the current site domain from the Nginx server_name or HTTP Host.
     * Enhanced to work with IP-based access (when DNS hasn't resolved yet)
     * by scanning aaPanel vhost configs to match the project directory.
     */
    private function detectSiteDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $host = strtolower(trim((string) $host));

        // Strip port
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        // Basic validation — if we have a proper domain, use it
        if ($host !== '' && $host !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP)) {
            // Remove www. prefix for the config filename
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            return $host;
        }

        // Host is an IP or localhost — try to detect from document root
        $docRoot = rtrim(str_replace('\\', '/', ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $basePath = rtrim(str_replace('\\', '/', base_path()), '/');

        // Strategy 1: Match document root to /www/wwwroot/<domain>
        if (preg_match('#^/www/wwwroot/([^/]+)(?:/public)?$#', $docRoot, $m)) {
            return $m[1];
        }

        // Strategy 2: Match base_path to /www/wwwroot/<domain>
        if (preg_match('#^/www/wwwroot/([^/]+)$#', $basePath, $m)) {
            return $m[1];
        }

        // Strategy 3: Scan aaPanel rewrite directory for matching domain
        $rewriteDir = '/www/server/panel/vhost/rewrite';
        if ($this->canInspectPath($rewriteDir) && @is_dir($rewriteDir)) {
            $files = @glob($rewriteDir . '/*.conf') ?: [];
            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $possibleRoot = '/www/wwwroot/' . $name;
                if ($docRoot === $possibleRoot || $docRoot === $possibleRoot . '/public'
                    || $basePath === $possibleRoot) {
                    return $name;
                }
            }
        }

        // Strategy 4: Scan aaPanel Nginx vhost configs to match our project root
        $vhostDir = '/www/server/panel/vhost/nginx';
        if ($this->canInspectPath($vhostDir) && @is_dir($vhostDir)) {
            $vhostFiles = @glob($vhostDir . '/*.conf') ?: [];
            foreach ($vhostFiles as $vhostFile) {
                $vhostContent = @file_get_contents($vhostFile);
                if ($vhostContent !== false) {
                    $escapedBase = preg_quote($basePath, '#');
                    if (preg_match('#root\s+' . $escapedBase . '(?:/public)?[;\s]#', $vhostContent)) {
                        return pathinfo($vhostFile, PATHINFO_FILENAME);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Safely reload Nginx after writing rewrite config.
     */
    private function reloadNginx(): void
    {
        if (! Environment::canRunProcesses()) {
            // Fallback: try exec directly even without proc_open
            if (Environment::functionAvailable('exec')) {
                $output = [];
                $status = 1;
                @exec('nginx -t 2>&1', $output, $status);
                if ($status === 0) {
                    @exec('nginx -s reload 2>&1');
                }
            }
            return;
        }

        try {
            $test = Process::fromShellCommandline('nginx -t 2>&1');
            $test->setTimeout(10);
            $test->run();

            if ($test->isSuccessful()) {
                $reload = Process::fromShellCommandline('nginx -s reload 2>&1');
                $reload->setTimeout(10);
                $reload->run();
            }
        } catch (Throwable $e) {
            // Non-critical — the config will apply on next Nginx restart
        }
    }

    private function linkPointsTo(string $link, string $target): bool
    {
        $linkReal = realpath($link);
        $targetReal = realpath($target);

        return $linkReal !== false
            && $targetReal !== false
            && rtrim(str_replace('\\', '/', $linkReal), '/') === rtrim(str_replace('\\', '/', $targetReal), '/');
    }

    private function replacePlaceholderDirectory(string $path): bool
    {
        if (! is_dir($path) || is_link($path)) {
            return false;
        }

        $items = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        $allowed = ['', '.gitignore'];
        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                return false;
            }
        }

        $gitignore = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.gitignore';
        if (is_file($gitignore) && ! @unlink($gitignore)) {
            return false;
        }

        return @rmdir($path);
    }

    private function createWindowsJunction(string $link, string $target): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\' || file_exists($link) || is_link($link)) {
            return false;
        }

        try {
            $process = Process::fromShellCommandline(
                'cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target)
            );
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful() && $this->linkPointsTo($link, $target);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function restorePlaceholderDirectory(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            return;
        }

        if (@mkdir($path, 0775, true)) {
            @file_put_contents(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.gitignore', '*' . PHP_EOL . '!.gitignore' . PHP_EOL);
        }
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function canInspectPath(string $path): bool
    {
        if (! Environment::hasOpenBasedir()) {
            return true;
        }

        $path = $this->normalizePath($path);

        foreach (Environment::openBasedirPaths() as $allowed) {
            $allowed = $this->normalizePath($allowed === '.' ? base_path() : $allowed);

            if ($path === $allowed || str_starts_with($path, $allowed . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @return array{scope:string,message:string,severity:string}
     */
    private function result(string $scope, string $message, string $severity): array
    {
        return [
            'scope' => $scope,
            'message' => $message,
            'severity' => $severity,
        ];
    }
}
