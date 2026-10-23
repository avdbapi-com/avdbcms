<?php

/**
 * AVDBCMS — Root front-controller fallback.
 *
 * This file makes the application work when the web server reaches index.php:
 *   - aaPanel + Nginx (default Running Directory: /)
 *   - CyberPanel + OpenLiteSpeed
 *   - HestiaCP, Plesk, cPanel (when root != /public)
 *   - Any server where the document root is the project root, not public/
 *
 * WHAT THIS FILE DOES:
 *   1. Creates runtime folders and applies shallow permission checks
 *   2. Best-effort writes Nginx rewrite rules for aaPanel when PHP may access them
 *   3. Auto-creates .env from .env.example if missing
 *   4. Auto-writes .user.ini to override open_basedir restrictions
 *   5. Blocks access to sensitive directories (vendor, .env, config, etc.)
 *   6. Serves static files (CSS/JS/images) from public/ with correct MIME types
 *   7. Forwards PHP requests to public/index.php with corrected SERVER vars
 *
 * ZERO-CONFIG DEPLOYMENT:
 *   Upload ZIP, extract in panel file manager, visit domain.
 *   For Nginx clean URLs such as /admin/settings, the server still needs a
 *   front-controller rewrite. Without it, Nginx returns 404 before PHP runs.
 *
 * WHEN IS THIS USED?
 *   - When the web server serves from /www/wwwroot/domain.com (not /public)
 *
 * WHEN IS THIS IGNORED?
 *   - When the document root is already public/ (best setup)
 *   - Apache/LiteSpeed use the root .htaccess instead
 *   - If the root is already public/, this file is never executed
 *
 * SECURITY:
 *   This file does NOT expose app/, config/, vendor/, storage/, or .env.
 *   It only proxies requests into the public/ directory.
 */

// ── 0. Quick sanity: are we already in public/? Skip if so. ───────────────
//    If the document root IS public/, this file should not be here.
//    But if it somehow runs, just forward to the real entry point.
$publicDir = __DIR__ . DIRECTORY_SEPARATOR . 'public';
$realPublicIndex = $publicDir . DIRECTORY_SEPARATOR . 'index.php';

if (!is_dir($publicDir) || !is_file($realPublicIndex)) {
    http_response_code(500);
    echo 'AVDBCMS: public/index.php not found. Check that the archive was extracted correctly.';
    exit;
}

// ── 1. Pre-boot auto-repair ──────────────────────────────────────────────
//    Runs BEFORE Laravel boots. Fixes the most common deployment issues
//    automatically so the user never needs to touch a terminal.
//    This is designed to be idempotent and safe to run on every request.
//    Once everything is fixed, the checks are cheap no-ops.

(function () use ($publicDir) {
    $projectRoot = __DIR__;
    $isLinux = PHP_OS_FAMILY === 'Linux';

    // ── 1a. Auto-fix file ownership and permissions ──────────────────────
    //    When aaPanel's file manager extracts a ZIP, files are owned by root.
    //    PHP-FPM runs as www:www → can't write to storage/ or bootstrap/cache/.
//    Try shallow fixes automatically using exec() if available. Never recurse
//    through storage here: imported media can be huge and recursive chown/chmod
//    from a web request can overload production servers.
    $writableDirs = [
        $projectRoot . '/storage',
        $projectRoot . '/storage/app',
        $projectRoot . '/storage/app/private',
        $projectRoot . '/storage/app/private/avatars',
        $projectRoot . '/storage/app/public',
        $projectRoot . '/storage/app/public/avatars',
        $projectRoot . '/storage/framework',
        $projectRoot . '/storage/framework/cache',
        $projectRoot . '/storage/framework/cache/data',
        $projectRoot . '/storage/framework/sessions',
        $projectRoot . '/storage/framework/views',
        $projectRoot . '/storage/logs',
        $projectRoot . '/bootstrap/cache',
    ];

    // Create missing directories first
    foreach ($writableDirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    // Check if storage is writable — if not, try to fix
    $storageDir = $projectRoot . '/storage';
    $needsPermissionFix = is_dir($storageDir) && !is_writable($storageDir);

    if (!$needsPermissionFix) {
        // Also check deeper dirs — storage might be writable but children aren't
        foreach ($writableDirs as $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                $needsPermissionFix = true;
                break;
            }
        }
    }

    if ($needsPermissionFix && $isLinux) {
        // Marker file to avoid repeating failed fixes on every request
        $fixMarker = sys_get_temp_dir() . '/avdbcms_permfix_' . md5($projectRoot);
        $markerAge = is_file($fixMarker) ? (time() - filemtime($fixMarker)) : PHP_INT_MAX;

        // Only retry fix every hour to avoid repeated shell work on live traffic.
        if ($markerAge > 3600) {
            $fixed = false;
            $fixTargets = array_values(array_filter($writableDirs, static fn ($dir) => is_dir($dir)));
            $escapedTargets = implode(' ', array_map('escapeshellarg', $fixTargets));

            // Strategy 1: Use exec() to chown + chmod (shallow only).
            if ($escapedTargets !== '' && function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
                // Try chown to www:www (the standard aaPanel PHP-FPM user).
                @exec('chown www:www ' . $escapedTargets . ' 2>/dev/null', $output, $status);
                if ($status === 0) {
                    @exec('chmod 775 ' . $escapedTargets . ' 2>/dev/null');
                    $fixed = true;
                }

                // Also fix the project root and .env writability
                if (!$fixed) {
                    // Try with the current PHP-FPM process user
                    $processUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                        ? (posix_getpwuid(posix_geteuid())['name'] ?? '')
                        : '';
                    if ($processUser !== '' && $processUser !== 'root') {
                        @exec('chown ' . escapeshellarg($processUser . ':' . $processUser) . ' ' . $escapedTargets . ' 2>/dev/null', $output, $status);
                        if ($status === 0) {
                            @exec('chmod 775 ' . $escapedTargets . ' 2>/dev/null');
                            $fixed = true;
                        }
                    }
                }

                // Also try to fix the project root for .env writability
                if ($fixed) {
                    $envPath = $projectRoot . '/.env';
                    $envExamplePath = $projectRoot . '/.env.example';
                    if (!is_file($envPath) && is_file($envExamplePath)) {
                        // Ensure root dir is writable for .env creation
                        @exec('chmod 775 ' . escapeshellarg($projectRoot) . ' 2>/dev/null');
                    }
                }
            }

            // Strategy 2: Try PHP chmod (works when files have appropriate group permissions)
            if (!$fixed) {
                foreach ($writableDirs as $dir) {
                    if (is_dir($dir) && !is_writable($dir)) {
                        @chmod($dir, 0775);
                    }
                }
            }

            // Write marker so we don't retry too fast
            @file_put_contents($fixMarker, date('c'));
        }
    } else {
        // Non-Linux: just try chmod
        foreach ($writableDirs as $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                @chmod($dir, 0775);
            }
        }
    }

    // ── 1b. Auto-create .env from .env.example ──────────────────────────
    $envPath = $projectRoot . '/.env';
    $envExamplePath = $projectRoot . '/.env.example';
    if (!is_file($envPath) && is_file($envExamplePath)) {
        @copy($envExamplePath, $envPath);
        // Ensure it's writable by PHP
        if (is_file($envPath)) {
            @chmod($envPath, 0664);
        }
    }

    // ── 1c. Auto-write Nginx rewrite for aaPanel ────────────────────────
    //    aaPanel stores per-site Nginx rewrite rules in:
    //      /www/server/panel/vhost/rewrite/<domain>.conf
    //    This file is included INSIDE the server {} block.
    //    Without the try_files rule, clean URLs like /install or /admin/settings
    //    return Nginx 404 before PHP can run. This is best-effort only because
    //    aaPanel open_basedir can block PHP from reading this panel directory.
    if ($isLinux) {
        $rewriteDir = '/www/server/panel/vhost/rewrite';
        if (@is_dir($rewriteDir)) {
            // Detect our site domain
            $domain = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
            if (str_contains($domain, ':')) {
                $domain = explode(':', $domain, 2)[0];
            }
            if (str_starts_with($domain, 'www.')) {
                $domain = substr($domain, 4);
            }

            // If host is an IP or localhost, try to detect from document root
            if ($domain === '' || $domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
                $docRoot = rtrim(str_replace('\\', '/', ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
                // Try matching document root to /www/wwwroot/<domain>
                if (preg_match('#^/www/wwwroot/([^/]+)(?:/public)?$#', $docRoot, $m)) {
                    $domain = $m[1];
                } else {
                    // Scan vhost configs to find which domain points to our directory
                    $vhostDir = '/www/server/panel/vhost/nginx';
                    if (@is_dir($vhostDir)) {
                        $vhostFiles = @glob($vhostDir . '/*.conf') ?: [];
                        foreach ($vhostFiles as $vhostFile) {
                            $vhostContent = @file_get_contents($vhostFile);
                            if ($vhostContent !== false) {
                                $escapedRoot = preg_quote(str_replace('\\', '/', $projectRoot), '#');
                                if (preg_match('#root\s+' . $escapedRoot . '(?:/public)?[;\s]#', $vhostContent)) {
                                    $domain = pathinfo($vhostFile, PATHINFO_FILENAME);
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            if ($domain !== '' && $domain !== 'localhost' && !filter_var($domain, FILTER_VALIDATE_IP)) {
                $rewriteFile = $rewriteDir . '/' . $domain . '.conf';
                $currentContent = @is_file($rewriteFile) ? trim((string) @file_get_contents($rewriteFile)) : '';

                $laravelRewrite = implode("\n", [
                    '# AVDBClip auto-configured rules (web root = project root).',
                    'location / {',
                    '    try_files $uri $uri/ /index.php?$query_string;',
                    '}',
                    '',
                    '# Public-disk files (the php artisan storage:link target) are served straight',
                    '# from storage/app/public, so they work even when PHP symlink() is disabled.',
                    'location ^~ /storage/ {',
                    '    alias ' . $projectRoot . '/storage/app/public/;',
                    '    location ~ \.php$ { return 404; }',
                    '    try_files $uri =404;',
                    '    expires 1d;',
                    '}',
                    '',
                    '# Published package assets (Horizon, Log Viewer, Umami) live in public/vendor;',
                    '# the composer vendor/ source tree is never served (missing file = plain 404).',
                    'location ^~ /vendor/ {',
                    '    alias ' . $projectRoot . '/public/vendor/;',
                    '    location ~ \.php$ { return 404; }',
                    '    try_files $uri =404;',
                    '}',
                    '',
                    '# Never serve application source, config, tests, dotfiles or install data.',
                    '# Prefix locations with ^~ win over the panel regex PHP handler whatever the order.',
                    'location ^~ /app/ { return 404; }',
                    'location ^~ /bootstrap/ { return 404; }',
                    'location ^~ /config/ { return 404; }',
                    'location ^~ /database/ { return 404; }',
                    'location ^~ /lang/ { return 404; }',
                    'location ^~ /resources/ { return 404; }',
                    'location ^~ /routes/ { return 404; }',
                    'location ^~ /tests/ { return 404; }',
                    'location ^~ /node_modules/ { return 404; }',
                    'location ~ ^/\.(?!well-known/) { return 404; }',
                    'location ~ ^/(artisan|composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml|vite\.config\.(js|ts))$ { return 404; }',
                ]);

                // Write the rewrite rule if:
                // - File is empty (default aaPanel state)
                // - File contains a broken server{} block
                // - File doesn't have try_files yet
                $shouldWrite = false;
                if ($currentContent === '' || !str_contains($currentContent, 'location')) {
                    $shouldWrite = true;
                } elseif (str_contains($currentContent, 'server {') || str_contains($currentContent, 'server{')) {
                    $shouldWrite = true; // Bad nested server block — replace it
                }
                // Don't overwrite if try_files already exists
                if (str_contains($currentContent, 'try_files')) {
                    $shouldWrite = false;
                }
                // Upgrade rules written by an earlier release (they lack the
                // /storage/ location); hand-written rules are left alone.
                if (str_contains($currentContent, '# AVDBClip auto-configured') && !str_contains($currentContent, 'location ^~ /storage/')) {
                    $shouldWrite = true;
                }

                if ($shouldWrite) {
                    if (@file_put_contents($rewriteFile, $laravelRewrite . "\n")) {
                        // Try to reload Nginx so the rule takes effect immediately
                        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
                            @exec('nginx -t 2>&1', $testOutput, $testStatus);
                            if ($testStatus === 0) {
                                @exec('nginx -s reload 2>&1');
                            }
                        }
                    }
                }
            }
        }
    }

    // ── 1d. Auto-write .user.ini for open_basedir override ──────────────
    //    aaPanel's "Anti-XSS attack" sets open_basedir that can block Laravel
    //    from accessing vendor/, config/, etc. when Running Directory is /public.
    //    Write open_basedir=none to disable the restriction.
    $openBasedir = (string) ini_get('open_basedir');
    if ($openBasedir !== '') {
        foreach ([$projectRoot . '/public/.user.ini', $projectRoot . '/.user.ini'] as $userIniPath) {
            $dir = dirname($userIniPath);
            if (!is_dir($dir) || !is_writable($dir)) {
                continue;
            }
            $existing = is_file($userIniPath) ? (string) @file_get_contents($userIniPath) : '';
            if (str_contains($existing, 'open_basedir')) {
                continue; // Already handled
            }
            $content = rtrim($existing) . ($existing === '' ? '' : "\n") . "open_basedir=none\n";
            @file_put_contents($userIniPath, $content);
        }
    }

    // ── 1e. Auto-mirror static assets to project root if missing ────────
    //    When control panels (aaPanel, 1Panel, CyberPanel) serve the project root,
    //    Nginx/LiteSpeed static regex rules serve *.css, *.js, *.woff2, and images
    //    directly from the document root before PHP is ever invoked.
    //    Mirroring public/ assets to root ensures 100% reliable zero-config delivery.
    $assetDirs = ['build', 'images', 'icons', 'sounds', 'css'];
    foreach ($assetDirs as $dirName) {
        $srcDir = $publicDir . DIRECTORY_SEPARATOR . $dirName;
        $dstDir = $projectRoot . DIRECTORY_SEPARATOR . $dirName;
        if (is_dir($srcDir) && (!is_dir($dstDir) || (count(@scandir($dstDir) ?: []) <= 2))) {
            $linked = false;
            if ($isLinux && function_exists('symlink')) {
                if (is_link($dstDir)) {
                    @unlink($dstDir);
                }
                $linked = @symlink($srcDir, $dstDir);
            }
            if (!$linked && !is_dir($dstDir)) {
                @mkdir($dstDir, 0775, true);
                $copyRecursive = static function ($src, $dst) use (&$copyRecursive) {
                    $dir = @opendir($src);
                    if ($dir) {
                        @mkdir($dst, 0775, true);
                        while (($file = readdir($dir)) !== false) {
                            if ($file !== '.' && $file !== '..') {
                                if (is_dir($src . '/' . $file)) {
                                    $copyRecursive($src . '/' . $file, $dst . '/' . $file);
                                } else {
                                    @copy($src . '/' . $file, $dst . '/' . $file);
                                }
                            }
                        }
                        closedir($dir);
                    }
                };
                $copyRecursive($srcDir, $dstDir);
            }
        }
    }
    foreach (['favicon.ico', 'manifest.json', 'robots.txt'] as $fileName) {
        $srcFile = $publicDir . DIRECTORY_SEPARATOR . $fileName;
        $dstFile = $projectRoot . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($srcFile) && !is_file($dstFile)) {
            @copy($srcFile, $dstFile);
        }
    }
})();

// ── 2. Parse the real URI (strip PATH_INFO like /index.php/install) ───────
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = urldecode(parse_url($requestUri, PHP_URL_PATH) ?? '/');

// Handle PATH_INFO: /index.php/install → real URI is /install
$pathInfo = '';
if (preg_match('#^/index\.php(/.*)?$#i', $uri, $m)) {
    $pathInfo = $m[1] ?? '';
    $_SERVER['AVDBCLIP_USED_INDEX_PHP'] = true;
    // Rewrite so Laravel sees /install, not /index.php/install
    $uri = $pathInfo ?: '/';
    $_SERVER['REQUEST_URI'] = $uri . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
    $_SERVER['PATH_INFO'] = $pathInfo;
}

// ── 3. Block sensitive paths ──────────────────────────────────────────────
//    Directory entries match whole path segments, file entries match by prefix
//    (/.env also covers /.env.backup, /composer covers composer.json and .lock).
//    /storage/... is deliberately not listed: section 4 resolves it strictly
//    inside storage/app/public, so the real storage/ tree is never reachable.
$blockedDirs = [
    '/.git', '/config', '/database', '/resources', '/routes', '/bootstrap', '/node_modules',
];
$blockedFiles = [
    '/.env', '/.user.ini', '/.htaccess', '/artisan', '/composer', '/package.json', '/webpack', '/vite',
];

$uriLower = strtolower($uri);

// Allow safe published vendor assets (e.g. /vendor/horizon/..., /vendor/log-viewer/...)
if (str_starts_with($uriLower, '/vendor')) {
    $isVendorAsset = !str_ends_with($uriLower, '.php') && is_file($publicDir . $uri);
    if (!$isVendorAsset) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

foreach ($blockedDirs as $dir) {
    if ($uriLower === $dir || str_starts_with($uriLower, $dir . '/')) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

foreach ($blockedFiles as $prefix) {
    if (str_starts_with($uriLower, $prefix)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

// ── 4. Serve static files from public/ ────────────────────────────────────
//    Only for URIs that look like static assets (have a file extension).
//    Avoids the is_file() call on PATH_INFO-style paths that trigger
//    open_basedir warnings (e.g., /public/index.php/install).
if ($uri !== '/' && !str_ends_with($uriLower, '.php') && preg_match('#\.\w{1,10}$#', $uri)) {
    // Public-disk files (Storage::url() => /storage/...) live in storage/app/public.
    // Resolve them there directly so they are served even when the public/storage
    // symlink could not be created (symlink() is disabled on many panels), and so
    // the symlinked path, whose realpath() lies outside public/, is accepted too.
    if (str_starts_with($uriLower, '/storage/')) {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public';
        $publicFile = $baseDir . substr($uri, 8);
    } else {
        $baseDir = $publicDir;
        $publicFile = $publicDir . $uri;
    }

    // Prevent directory traversal: the resolved file must stay inside its base directory
    $realPublicDir = realpath($baseDir);
    $realFile = realpath($publicFile);

    if ($realFile !== false && $realPublicDir !== false
        && str_starts_with($realFile, $realPublicDir . DIRECTORY_SEPARATOR)
        && is_file($realFile)) {

        $mimeTypes = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'mjs'   => 'application/javascript',
            'json'  => 'application/json',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'mp3'   => 'audio/mpeg',
            'ogg'   => 'audio/ogg',
            'map'   => 'application/json',
            'txt'   => 'text/plain',
            'xml'   => 'application/xml',
            'pdf'   => 'application/pdf',
            'zip'   => 'application/zip',
        ];

        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? (function_exists('mime_content_type') ? (mime_content_type($realFile) ?: 'application/octet-stream') : 'application/octet-stream');

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($realFile));

        // Cache: uploaded public-disk files for a day, build assets for 30 days
        if (str_starts_with($uriLower, '/storage/')) {
            header('Cache-Control: public, max-age=86400');
        } elseif (in_array($ext, ['css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'eot'], true)) {
            header('Cache-Control: public, max-age=2592000, immutable');
        }

        readfile($realFile);
        exit;
    }
}

// ── 5. Pre-boot check: Can Laravel even boot? ─────────────────────────────
//    If storage is still not writable after all fix attempts, show a minimal
//    self-contained HTML page with instructions — no Laravel needed.
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
$bootstrapCache = __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'cache';

if ((is_dir($storageDir) && !is_writable($storageDir)) || (is_dir($bootstrapCache) && !is_writable($bootstrapCache))) {
    // Last-ditch shallow attempt: if exec is available but chown failed
    // earlier, try to detect the correct user from the process. Do not recurse
    // here; a web request must never walk a large media storage tree.
    $canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    if ($canExec && PHP_OS_FAMILY === 'Linux') {
        // Try to get current user from whoami
        $whoami = trim((string) @exec('whoami 2>/dev/null'));
        if ($whoami !== '' && $whoami !== 'root') {
            $targets = implode(' ', array_map('escapeshellarg', array_filter([$storageDir, $bootstrapCache], static fn ($dir) => is_dir($dir))));
            if ($targets !== '') {
                @exec('chown ' . escapeshellarg($whoami . ':' . $whoami) . ' ' . $targets . ' 2>/dev/null', $output, $status);
                @exec('chmod 775 ' . $targets . ' 2>/dev/null');
            }
        }
    }

    // Recheck after final attempt
    clearstatcache(true);
    if ((is_dir($storageDir) && !is_writable($storageDir)) || (is_dir($bootstrapCache) && !is_writable($bootstrapCache))) {
        http_response_code(503);
        $projectPath = str_replace('\\', '/', __DIR__);
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVDBCMS — Permission Fix Required</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .card { background: #1e293b; border-radius: 16px; padding: 3rem; max-width: 720px; width: 100%; box-shadow: 0 25px 50px rgba(0,0,0,.5); }
        h1 { font-size: 1.5rem; color: #f59e0b; margin-bottom: .5rem; }
        .subtitle { color: #94a3b8; margin-bottom: 2rem; }
        .status { background: #7f1d1d; border: 1px solid #991b1b; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; }
        .status.ok { background: #14532d; border-color: #166534; }
        .code { background: #0f172a; border-radius: 8px; padding: 1rem; font-family: 'Fira Code', monospace; font-size: .875rem; color: #22d3ee; overflow-x: auto; margin: 1rem 0; cursor: pointer; position: relative; }
        .code:hover::after { content: 'Click to copy'; position: absolute; top: .5rem; right: .5rem; background: #334155; padding: .25rem .5rem; border-radius: 4px; font-size: .75rem; color: #94a3b8; }
        .steps { list-style: none; counter-reset: step; }
        .steps li { counter-increment: step; padding: .75rem 0; border-bottom: 1px solid #334155; }
        .steps li::before { content: counter(step) '.'; color: #f59e0b; font-weight: 700; margin-right: .5rem; }
        .steps li:last-child { border-bottom: none; }
        .hint { color: #64748b; font-size: .875rem; margin-top: 1.5rem; }
        .btn { display: inline-block; background: #f59e0b; color: #0f172a; padding: .75rem 2rem; border-radius: 8px; text-decoration: none; font-weight: 700; margin-top: 1rem; cursor: pointer; border: none; font-size: 1rem; }
        .btn:hover { background: #d97706; }
    </style>
</head>
<body>
    <div class="card">
        <h1>⚠️ Permission Fix Required</h1>
        <p class="subtitle">AVDBCMS needs write access to storage/ and bootstrap/cache/. This is a one-time fix.</p>

        <div class="status">
            <strong>storage/</strong> — Not writable by PHP<br>
            <strong>bootstrap/cache/</strong> — Not writable by PHP
        </div>

        <p><strong>Fix it:</strong> Run this command in aaPanel Terminal (or SSH):</p>
        <div class="code" onclick="navigator.clipboard.writeText(this.innerText)">mkdir -p {$projectPath}/storage/app/public {$projectPath}/storage/framework/cache/data {$projectPath}/storage/framework/sessions {$projectPath}/storage/framework/views {$projectPath}/storage/logs {$projectPath}/bootstrap/cache && chown www:www {$projectPath}/storage {$projectPath}/storage/app {$projectPath}/storage/app/public {$projectPath}/storage/framework {$projectPath}/storage/framework/cache {$projectPath}/storage/framework/cache/data {$projectPath}/storage/framework/sessions {$projectPath}/storage/framework/views {$projectPath}/storage/logs {$projectPath}/bootstrap/cache && chmod 775 {$projectPath}/storage {$projectPath}/storage/app {$projectPath}/storage/app/public {$projectPath}/storage/framework {$projectPath}/storage/framework/cache {$projectPath}/storage/framework/cache/data {$projectPath}/storage/framework/sessions {$projectPath}/storage/framework/views {$projectPath}/storage/logs {$projectPath}/bootstrap/cache</div>

        <p style="margin-top: 1rem;"><strong>Or in aaPanel GUI:</strong></p>
        <ol class="steps">
            <li>Go to <strong>Files</strong> → navigate to <code>{$projectPath}</code></li>
            <li>Select all files → click <strong>Permissions</strong> (or right-click → Permissions)</li>
            <li>Set owner to <strong>www</strong>, check <strong>Apply to subdirectories</strong>, click <strong>Confirm</strong></li>
        </ol>

        <button class="btn" onclick="location.reload()">🔄 Retry</button>

        <p class="hint">This page appears because the files were extracted as root. After fixing permissions, this page will disappear and the installer will load automatically.</p>
    </div>
</body>
</html>
HTML;
        exit;
    }
}

// ── 6. Forward request to public/index.php (the real Laravel entry) ───────
//    Change working directory to public/ so __DIR__ in public/index.php
//    resolves correctly and all relative paths (../vendor, etc.) work.
chdir($publicDir);

// Mark that we're running from the root fallback — public/index.php can
// use this to know the document root is NOT /public.
$_SERVER['AVDBCLIP_ROOT_FALLBACK'] = true;

// Override DOCUMENT_ROOT and SCRIPT_FILENAME so Laravel and PHP see
// public/ as the document root, exactly as if Nginx pointed there directly.
$_SERVER['DOCUMENT_ROOT'] = $publicDir;
$_SERVER['SCRIPT_FILENAME'] = $realPublicIndex;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $realPublicIndex;
