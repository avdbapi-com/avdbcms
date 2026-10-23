<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (! function_exists('avdbcms_bootstrap_failure')) {
    function avdbcms_bootstrap_failure(string $title, string $message, array $details = [], int $status = 500): never
    {
        http_response_code($status);

        $detailItems = '';
        foreach ($details as $label => $value) {
            $detailItems .= '<dt>'.htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8').'</dt><dd><code>'
                .htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8').'</code></dd>';
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>AVDBCMS Bootstrap Check</title><style>'
            .'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#080b12;color:#eef3ff;font:15px/1.55 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
            .'.card{width:min(760px,100%);border:1px solid rgba(255,255,255,.14);border-radius:14px;background:#151b28;padding:28px;box-shadow:0 24px 80px rgba(0,0,0,.35)}'
            .'h1{margin:0 0 10px;font-size:25px;line-height:1.2}p{margin:0 0 18px;color:#b9c4d8}dl{display:grid;grid-template-columns:160px minmax(0,1fr);gap:10px 14px;margin:18px 0 0}dt{color:#94a3b8;font-weight:700}dd{margin:0;min-width:0}code{color:#dce7ff;word-break:break-all}.hint{padding:12px 14px;border:1px solid rgba(255,202,92,.38);border-radius:10px;background:rgba(255,202,92,.09);color:#ffe3a3}</style></head><body><main class="card">'
            .'<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'
            .'<p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>'
            .'<div class="hint">Upload or extract the complete AVDBCMS package again. Make sure hidden files and the full vendor/ directory are included.</div>'
            .($detailItems !== '' ? '<dl>'.$detailItems.'</dl>' : '')
            .'</main></body></html>';

        exit;
    }
}

if (! function_exists('avdbcms_prepare_runtime')) {
    function avdbcms_prepare_runtime(string $root): void
    {
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            avdbcms_bootstrap_failure('PHP Version Not Supported', 'AVDBCMS requires PHP 8.3 or newer before the installer can start.', [
                'Current PHP' => PHP_VERSION,
                'Required PHP' => '8.3+',
                'Fix' => 'Change the domain PHP version in your hosting panel, then reload this page.',
            ]);
        }

        $requiredDirs = [
            'storage',
            'storage/app',
            'storage/app/public',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ];

        $blocked = [];
        foreach ($requiredDirs as $relative) {
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }

            if (is_dir($path) && ! is_writable($path)) {
                @chmod($path, 0775);
            }

            if (! is_dir($path)) {
                $blocked[$relative] = 'Missing';
                continue;
            }

            if (! is_writable($path)) {
                $blocked[$relative] = 'Not writable';
            }
        }

        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        $envExamplePath = $root.DIRECTORY_SEPARATOR.'.env.example';
        if (! is_file($envPath) && is_file($envExamplePath) && is_writable($root)) {
            @copy($envExamplePath, $envPath);
            @chmod($envPath, 0664);
        }

        if ($blocked !== []) {
            $details = ['Project root' => $root];
            foreach ($blocked as $relative => $reason) {
                $details[$relative] = $reason;
            }

            avdbcms_bootstrap_failure('Runtime Permissions Required', 'The installer cannot start until PHP can write Laravel runtime folders.', $details, 503);
        }
    }
}

avdbcms_prepare_runtime(dirname(__DIR__));

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

$autoloadPath = __DIR__.'/../vendor/autoload.php';
if (! is_file($autoloadPath)) {
    avdbcms_bootstrap_failure('Incomplete AVDBCMS Package', 'Composer dependencies are missing, so the installer cannot start.', [
        'Missing file' => $autoloadPath,
        'Expected folder' => realpath(__DIR__.'/..').DIRECTORY_SEPARATOR.'vendor',
    ]);
}

try {
    require $autoloadPath;
} catch (Throwable $e) {
    avdbcms_bootstrap_failure('Incomplete AVDBCMS Package', 'Composer dependencies are incomplete or damaged. This usually means the ZIP was not fully extracted.', [
        'Autoload file' => $autoloadPath,
        'Error' => $e->getMessage(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$bootstrapPath = __DIR__.'/../bootstrap/app.php';
if (! is_file($bootstrapPath)) {
    avdbcms_bootstrap_failure('Incomplete AVDBCMS Package', 'The Laravel bootstrap file is missing, so the installer cannot start.', [
        'Missing file' => $bootstrapPath,
    ]);
}

try {
    $app = require_once $bootstrapPath;
} catch (Throwable $e) {
    avdbcms_bootstrap_failure('AVDBCMS Bootstrap Failed', 'Laravel could not initialize. Check that the package was extracted completely and PHP extensions are enabled.', [
        'Bootstrap file' => $bootstrapPath,
        'Error' => $e->getMessage(),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// LOCALE URL REWRITING — Pure PHP, server-agnostic
// Works on Apache, Nginx, LiteSpeed, or any server without .htaccess changes.
//
// Handles three things before Laravel's router sees the request:
//   1. Strip /public/ path prefix (OSPanel dev: domain root → project root,
//      root .htaccess rewrites /vi → /public/vi. On Nginx/production this
//      never fires since web root IS public/).
//   2. Detect and strip /{locale} prefix → store in $_SERVER['DETECTED_LOCALE'].
//   3. 301-redirect trailing slashes in PHP (so public/.htaccess needs no
//      trailing-slash rule — works on Nginx/LiteSpeed without Apache rewrite).
// ══════════════════════════════════════════════════════════════════════════════
(function () {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $query = parse_url($uri, PHP_URL_QUERY);

    // Normalize root/public front-controller fallbacks before Laravel captures
    // the request. This prevents generated URLs like /public/index.php/... on
    // Apache/LiteSpeed panels that rewrite project-root requests into public/.
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $phpSelf = (string) ($_SERVER['PHP_SELF'] ?? '');
    if (str_contains($scriptName, '/public/index.php') || str_contains($phpSelf, '/public/index.php')) {
        $_SERVER['DOCUMENT_ROOT'] = __DIR__;
        $_SERVER['SCRIPT_FILENAME'] = __FILE__;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['AVDBCLIP_ROOT_FALLBACK'] = true;
    }

    foreach (['/public/index.php', '/index.php'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            if ($prefix === '/index.php') {
                $_SERVER['AVDBCLIP_INDEX_PHP_PREFIX'] = true;
            }

            $path = (string) substr($path, strlen($prefix));
            if ($path === '') $path = '/';
            $uri = $path . ($query ? '?' . $query : '');
            $_SERVER['REQUEST_URI'] = $uri;
            $_SERVER['PATH_INFO'] = $path;
            break;
        }
    }

    // ── 1. Strip /public/ prefix ─────────────────────────────────────────────
    // In OSPanel (and some shared hosts), the web root is the project's root dir
    // instead of public/. The root .htaccess rewrites /vi → /public/vi.
    // Production servers (Nginx, LiteSpeed, proper Apache vhost) point the
    // web root DIRECTLY to public/, so this block never runs on production.
    if (str_starts_with($path, '/public/') || $path === '/public') {
        $path = (string) substr($path, strlen('/public'));
        if ($path === '') $path = '/';
        $uri = $path . ($query ? '?' . $query : '');
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['PATH_INFO'] = $path;
    }

    // ── 2. Detect & strip /{locale} prefix ───────────────────────────────────
    $segments     = explode('/', ltrim($path, '/'));
    $firstSegment = $segments[0] ?? '';

    // Load supported locales (raw file read — Laravel hasn't booted yet)
    static $localeList = null;
    if ($localeList === null) {
        $cfg = @include __DIR__ . '/../config/languages.php';
        $localeList = is_array($cfg) ? array_keys($cfg) : [];
    }

    // These path roots are never locale codes
    $reservedPaths = [
        'api', 'admin', '_debugbar', 'logs', 'horizon',
        'storage', 'update-locale', 'public', '',
    ];

    $detectedLocale   = null;
    $pathBeforeStrip  = $path;   // remember the path BEFORE locale is stripped

    if (
        $firstSegment !== '' &&
        !in_array($firstSegment, $reservedPaths, true) &&
        in_array($firstSegment, $localeList, true)
    ) {
        $detectedLocale = $firstSegment;
        $_SERVER['DETECTED_LOCALE'] = $firstSegment;
        $_SERVER['LOCALE_PREFIX']   = '/' . $firstSegment;

        $stripped = (string) substr($path, strlen('/' . $firstSegment));
        $path     = $stripped !== '' ? $stripped : '/';
        $uri      = $path . ($query ? '?' . $query : '');

        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['PATH_INFO']   = $path;
    }

    // ── 3. PHP trailing-slash redirect ────────────────────────────────────────
    // Removes trailing slashes with a 301 redirect — no Apache RewriteRule needed.
    // Works transparently on Nginx, LiteSpeed, Apache, IIS.
    //
    // Case A: /vi/trend/ → path after strip = /trend/  → redirect to /vi/trend
    // Case B: /vi/       → path after strip = /         → redirect to /vi
    // Case C: /trend/    → no locale, path = /trend/    → redirect to /trend
    $needsSlashRedirect = false;
    $slashRedirectTo    = '';

    if ($path !== '/' && str_ends_with($path, '/')) {
        // Case A / C: path still has trailing slash after locale strip
        $localeSegment     = $detectedLocale ? '/' . $detectedLocale : '';
        $slashRedirectTo   = $localeSegment . rtrim($path, '/') . ($query ? '?' . $query : '');
        $needsSlashRedirect = true;
    } elseif ($detectedLocale !== null && $pathBeforeStrip !== '/' && str_ends_with($pathBeforeStrip, '/') && $path === '/') {
        // Case B: /vi/ → after stripping locale, path becomes '/'
        // The trailing slash was on the locale root itself → redirect /vi/ → /vi
        $slashRedirectTo    = '/' . $detectedLocale . ($query ? '?' . $query : '');
        $needsSlashRedirect = true;
    }

    if ($needsSlashRedirect) {
        header('Location: ' . $slashRedirectTo, true, 301);
        exit;
    }
})();


// ──────────────────────────────────────────────────────────────────────────────
// Set locale BEFORE kernel handles the request so that error pages (404/500)
// are localised even if the Language middleware doesn't run.
// ──────────────────────────────────────────────────────────────────────────────
if (!empty($_SERVER['DETECTED_LOCALE'])) {
    $app->afterBootstrapping(
        \Illuminate\Foundation\Bootstrap\BootProviders::class,
        function ($app) {
            $app->setLocale($_SERVER['DETECTED_LOCALE']);
        }
    );
}



$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);

if (class_exists(\App\Support\LaravelSchedulerCron::class)) {
    \App\Support\LaravelSchedulerCron::triggerDueWebScheduler();
}
