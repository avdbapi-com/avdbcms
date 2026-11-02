<?php

namespace App\Http\Middleware;

use App\Support\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A site that is not installed yet answers its pages with "being set up" (503 with Retry-After, never indexed), not with
 * the pages of an empty or half-made site (round 3 R3-038: while the installation was unfinished /trend answered 200 with
 * an empty page and /categories 500, and a crawler indexed what it found).
 *
 * Only while Installer::beingSetUp(): no installer lock (storage/app/installed.json, the installer's last step writes it)
 * AND the upload's own .env still says APP_INSTALLED=false (a fresh upload, an installation not finished) or an
 * installation of the web installer is in progress; next to the lock only a reinstallation (INSTALLER_ALLOW_REINSTALL)
 * that has begun rebuilding the site's own database and did not finish. An installed site passes after two file checks
 * (the lock, and no run note of the installer), without loading the installer; so does a site without the lock whose
 * .env does not say false and that has no run note.
 *
 * Never answered so: the installer (/install, /index.php/install), the home page (it leads to the installer) and the
 * site's checks of itself (/_avdbcms/check/..., which the installer's private-files check asks: App\Support\ServerChecks).
 */
class AnswerWhileInstalling
{
    /** Retry-After of the answer (seconds): as long as the maintenance page's default. */
    public const int RETRY_AFTER = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->plainlyInstalled() || $this->passes($request)) {
            return $next($request);
        }

        try {
            $settingUp = app(Installer::class)->beingSetUp();
        } catch (Throwable) {
            $settingUp = false;
        }

        if (! $settingUp) {
            return $next($request);
        }

        $installer = url((! empty($_SERVER['AVDBCLIP_ROOT_FALLBACK']) || ! empty($_SERVER['AVDBCLIP_INDEX_PHP_PREFIX']) ? '/index.php' : '').'/install');
        $headers = ['Retry-After' => (string) self::RETRY_AFTER, 'X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private'];
        $message = 'This site is being set up. Please come back later.';

        if ($request->expectsJson() || $request->is('api', 'api/*')) {
            return response()->json(['message' => $message], 503, $headers);
        }

        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow"><title>Being set up</title>'
            .'<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b0f18;color:#e8edf7;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
            .'main{max-width:34rem;padding:2rem}h1{font-size:1.6rem;margin:0 0 .5rem}a{color:#9db7ff}</style></head>'
            .'<body><main><h1>Being set up</h1><p>'.e($message).'</p>'
            .'<p>Setting up this site? Continue in <a href="'.e($installer).'">the installer</a>.</p></main></body></html>';

        return response($page, 503, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** The installer, the home page (it leads to the installer) and the site's checks of itself. */
    private function passes(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return $path === '' || $path === 'index.php'
            || preg_match('#^(index\.php/)?install(/|$)#', $path) === 1
            || preg_match('#^(index\.php/)?'.preg_quote(\App\Support\ServerChecks::PATH, '#').'/#', $path) === 1;
    }

    /**
     * An installed site without a run of the installer: the installer's lock and no run note (the files of
     * Installer::lockPath() and Installer::runStatePath()), two file checks without loading the installer on every
     * request. An installer bound in the container (a test's) answers for itself.
     */
    private function plainlyInstalled(): bool
    {
        return ! app()->bound(Installer::class)
            && is_file(storage_path('app/installed.json'))
            && ! is_file(storage_path('framework/avd-install-run.json'));
    }
}
