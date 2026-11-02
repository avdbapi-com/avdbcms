<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (! function_exists('avdbcms_bootstrap_failure')) {
    // $hint: what to do about it (R1B-008: "upload the package again" only where files are missing; a permission problem
    // or the PHP version says what fixes those).
    function avdbcms_bootstrap_failure(string $title, string $message, array $details = [], int $status = 500, ?string $hint = null): never
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
            .'<div class="hint">'.htmlspecialchars($hint ?? 'Upload or extract the complete AVDBCMS package again. Make sure hidden files and the full vendor/ directory are included.', ENT_QUOTES, 'UTF-8').'</div>'
            .($detailItems !== '' ? '<dl>'.$detailItems.'</dl>' : '')
            .'</main></body></html>';

        exit;
    }
}

if (! function_exists('avdbcms_runtime_owner')) {
    /**
     * Who PHP runs as and who owns the project folder, for the permission page (R1B-008). When they differ (a Docker bind
     * mount, files unpacked as root or by a deploy user), write permission for the owner does not help PHP: the folder must
     * belong to the PHP user. Empty where PHP cannot tell (Windows, no posix extension).
     *
     * @return array<string, string>
     */
    function avdbcms_runtime_owner(string $root): array
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return [];
        }

        $uid = posix_geteuid();
        $gid = function_exists('posix_getegid') ? posix_getegid() : $uid;
        $user = @posix_getpwuid($uid);
        $ownerUid = @fileowner($root);
        $owner = is_int($ownerUid) ? @posix_getpwuid($ownerUid) : false;
        $details = ['PHP runs as' => (is_array($user) ? $user['name'] : 'user id '.$uid).' (user id '.$uid.')'];

        if (is_int($ownerUid)) {
            $details['Project folder owner'] = (is_array($owner) ? $owner['name'] : 'user id '.$ownerUid).' (user id '.$ownerUid.')';

            if ($ownerUid !== $uid) {
                $details['Example fix'] = 'chown -R '.$uid.':'.$gid.' '.escapeshellarg($root);
            }
        }

        return $details;
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
            ], 500, 'The package itself is fine: only the PHP version of this site must change.');
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
            // Read and written by its owner (this PHP user) only, read by the group: .env holds every secret of the
            // site; another account never reads it, and the web server's group (www-data in the site's group on many
            // panels) never rewrites it (R1B-016).
            @chmod($envPath, 0640);
        }

        if ($blocked !== []) {
            $details = ['Project root' => $root];
            foreach ($blocked as $relative => $reason) {
                $details[$relative] = $reason;
            }

            avdbcms_bootstrap_failure('Runtime Permissions Required', 'The installer cannot start until PHP can write Laravel runtime folders.', $details + avdbcms_runtime_owner($root), 503,
                'Uploading the package again does not fix this. Make the site\'s folder belong to the user PHP runs as (with its subfolders; in a panel\'s file manager: Permissions / Owner), or at least make storage/ and bootstrap/cache/ writable for that user, then reload this page. The installer\'s Filesystem step lists everything else PHP must write.');
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
// Before Laravel's router sees the request (App\Support\SiteAddress::prepare()):
//   0. A site installed in a folder of its domain (https://example.com/site):
//      everything below reads the address inside that folder, and Laravel gets
//      the folder in front of the page, so its links start with it.
//   1. Strip /index.php and /public/ path prefixes (servers without rewrite
//      rules; OSPanel dev: domain root → project root, root .htaccess rewrites
//      /vi → /public/vi. On Nginx/production with the web root at public/ the
//      /public/ part never fires).
//   2. Detect and strip /{locale} prefix → store in $_SERVER['DETECTED_LOCALE'].
//   3. 301-redirect trailing slashes in PHP (so public/.htaccess needs no
//      trailing-slash rule — works on Nginx/LiteSpeed without Apache rewrite).
// ══════════════════════════════════════════════════════════════════════════════
(function () {
    // Load supported locales (raw file read — Laravel hasn't booted yet)
    $cfg = @include __DIR__ . '/../config/languages.php';
    $localeList = is_array($cfg) ? array_keys($cfg) : [];

    if (! class_exists(\App\Support\SiteAddress::class)) {
        avdbcms_bootstrap_failure('Incomplete AVDBCMS Package', 'A file of the package is missing, so the site cannot start.', [
            'Missing file' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'SiteAddress.php',
        ]);
    }

    $slashRedirectTo = \App\Support\SiteAddress::prepare($_SERVER, $localeList, __DIR__, __FILE__);

    if ($slashRedirectTo !== null) {
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
