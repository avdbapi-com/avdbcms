<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Offload large-file streaming to the web server when possible.
 *
 * Apache (with `mod_xsendfile`) → `X-Sendfile`
 * Nginx                        → `X-Accel-Redirect` (paired with an
 *                                 `internal` location in the vhost)
 * LiteSpeed / OpenLiteSpeed    → `X-LiteSpeed-Location` (documented
 *                                 as the LSAPI-native accelerator)
 * everything else              → transparent `response()->file()`
 *
 * The accelerator is chosen automatically from the detected web server
 * unless `STREAM_ACCELERATOR` (env) or `site.stream_accelerator`
 * (admin setting) pins an explicit mode: `auto|none|nginx|apache|litespeed`.
 *
 * For the Nginx / LiteSpeed accelerators we need a public-ish URI that
 * only the web server treats as `internal`. The URI is built from the
 * configured prefix (`site.stream_accelerator_prefix`, default
 * `/_internal/`) plus a relative path under `storage/app/` (or whatever
 * base directory the caller passes).
 *
 * nginx: a server whose configuration has no such internal location sends the X-Accel-Redirect address back to PHP
 * (try_files) until it gives up with HTTP 500, or answers 404: every video stored on the site failed with "auto" (round
 * 1b R1B-003). So "auto" hands a file to nginx only after the site checked that its internal location works
 * (App\Support\ServerChecks::NGINX_STREAM: a cached self-check), and an explicit "nginx" only once that check ran and did
 * not find it failing. And whatever the check said: when nginx does send the address back (the location was removed
 * since), this request streams the file with PHP and the site stops asking nginx (accelLoop()).
 *
 * Because a misconfigured accelerator would break every video, this helper
 * fails closed to `response()->file()` when:
 *   - `$request` is missing (CLI contexts),
 *   - the accelerator is explicitly disabled, or
 *   - the web server isn't known to support the chosen accelerator.
 */
final class SendFile
{
    public const MODE_AUTO = 'auto';
    public const MODE_NONE = 'none';
    public const MODE_NGINX = 'nginx';
    public const MODE_APACHE = 'apache';
    public const MODE_LITESPEED = 'litespeed';

    /** Added to every X-Accel-Redirect address: nginx keeps it when it sends the address back to PHP (accelLoop()). */
    public const LOOP_PARAMETER = 'avdbcms_accel';

    /**
     * Build a response that serves `$absolutePath` using the best
     * available streaming strategy.
     *
     * @param  string  $absolutePath  real on-disk path of the file to serve
     * @param  array<string, string>  $headers  additional headers merged onto the response
     * @param  string|null  $internalBase  filesystem prefix to strip when building the
     *                                   accelerated URI (defaults to storage_path('app'))
     */
    public static function stream(
        string $absolutePath,
        Request $request,
        array $headers = [],
        ?string $internalBase = null,
    ): SymfonyResponse {
        if (! is_file($absolutePath)) {
            abort(404);
        }

        $base = $internalBase ?? storage_path('app');
        $internalUri = self::buildInternalUri($absolutePath, $base);

        // nginx sent our own X-Accel-Redirect address back to PHP: it has no internal location for it. This request
        // streams the file, and the next ones do so at once (the check's answer is NO now).
        if ($internalUri !== null && self::accelLoop($request, $internalUri)) {
            ServerChecks::record(ServerChecks::NGINX_STREAM, ServerChecks::NO, 'nginx sent the X-Accel-Redirect address of a video back to PHP');

            return self::plainFileResponse($absolutePath, $headers);
        }

        $mode = self::effectiveMode();
        $serverSupport = self::webServerSupportsMode($mode);

        if (Environment::webServer() === Environment::WEB_NGINX && in_array(self::configuredMode(), [self::MODE_AUTO, self::MODE_NGINX], true)) {
            // Not checked yet, or a while ago: check after this answer (meanwhile PHP streams with "auto").
            ServerChecks::runSoon(ServerChecks::NGINX_STREAM);
        }

        if ($mode === self::MODE_NONE || ! $serverSupport) {
            return self::plainFileResponse($absolutePath, $headers);
        }

        if ($internalUri === null) {
            return self::plainFileResponse($absolutePath, $headers);
        }

        return match ($mode) {
            self::MODE_NGINX => self::nginxResponse($internalUri, $headers),
            self::MODE_APACHE => self::apacheResponse($absolutePath, $headers),
            self::MODE_LITESPEED => self::litespeedResponse($internalUri, $absolutePath, $headers),
            default => self::plainFileResponse($absolutePath, $headers),
        };
    }

    /**
     * The answer to the site's own nginx check (App\Http\Controllers\ServerCheckController): the check file through
     * X-Accel-Redirect, exactly as a video, or a 404 when nginx sent that address back to PHP.
     *
     * @param  array<string, string>  $headers
     */
    public static function nginxCheckResponse(string $file, Request $request, array $headers = []): SymfonyResponse
    {
        try {
            $base = \Illuminate\Support\Facades\Storage::disk('local')->path('');
        } catch (\Throwable) {
            $base = storage_path('app');
        }

        $internalUri = self::buildInternalUri($file, $base);

        if ($internalUri === null || self::accelLoop($request, $internalUri)) {
            return response('', 404, $headers);
        }

        return self::nginxResponse($internalUri, $headers);
    }

    /** The configured mode (admin setting, else STREAM_ACCELERATOR, else auto), before the server is looked at. */
    public static function configuredMode(): string
    {
        $configured = strtolower(trim((string) (
            config('site.stream_accelerator')
            ?: RuntimeEnv::get('STREAM_ACCELERATOR', self::MODE_AUTO)
        )));

        $modes = [self::MODE_AUTO, self::MODE_NONE, self::MODE_NGINX, self::MODE_APACHE, self::MODE_LITESPEED];

        return in_array($configured, $modes, true) ? $configured : self::MODE_AUTO;
    }

    public static function effectiveMode(): string
    {
        $configured = self::configuredMode();

        if ($configured === self::MODE_NGINX) {
            // The owner chose nginx: honoured once the site's check did not find it failing (it found the location, or
            // the site cannot reach itself to tell). Before the first check (it runs right after this answer) PHP sends
            // the file, and after a "no" PHP sends them all: a server without the internal location and without
            // try_files answered 404 for every video until the check had run (round 1b review BWR-01).
            $answer = ServerChecks::answer(ServerChecks::NGINX_STREAM);

            if ($answer === ServerChecks::NO || ($answer === null && ServerChecks::siteAddress() !== null)) {
                return self::MODE_NONE;
            }

            return self::MODE_NGINX;
        }

        if ($configured !== self::MODE_AUTO) {
            return $configured;
        }

        return match (Environment::webServer()) {
            Environment::WEB_NGINX => ServerChecks::nginxStreamWorks() === true ? self::MODE_NGINX : self::MODE_NONE,
            Environment::WEB_APACHE => self::apacheAvailable() ? self::MODE_APACHE : self::MODE_NONE,
            Environment::WEB_LITESPEED, Environment::WEB_OPENLITESPEED => self::MODE_LITESPEED,
            default => self::MODE_NONE,
        };
    }

    public static function internalPrefix(): string
    {
        $prefix = (string) (config('site.stream_accelerator_prefix') ?: '/_internal/');
        $prefix = '/' . trim($prefix, '/');

        return rtrim($prefix, '/') . '/';
    }

    /**
     * Whether this request is nginx sending our own X-Accel-Redirect address for $internalUri back to PHP: the address
     * carries LOOP_PARAMETER with a value only this site can make, and nginx keeps that query string when try_files sends
     * the address to index.php. A visitor's own query string can never make it.
     */
    public static function accelLoop(Request $request, string $internalUri): bool
    {
        $value = $request->query(self::LOOP_PARAMETER);

        return is_string($value) && $value !== '' && hash_equals(self::loopSignature($internalUri), $value);
    }

    private static function loopSignature(string $internalUri): string
    {
        return substr(hash_hmac('sha256', 'x-accel|'.$internalUri, (string) config('app.key')), 0, 32);
    }

    private static function webServerSupportsMode(string $mode): bool
    {
        if ($mode === self::MODE_AUTO || $mode === self::MODE_NONE) {
            return true;
        }

        return match ($mode) {
            self::MODE_NGINX => Environment::webServer() === Environment::WEB_NGINX,
            self::MODE_APACHE => Environment::webServer() === Environment::WEB_APACHE,
            self::MODE_LITESPEED => in_array(
                Environment::webServer(),
                [Environment::WEB_LITESPEED, Environment::WEB_OPENLITESPEED],
                true
            ),
            default => false,
        };
    }

    private static function buildInternalUri(string $absolutePath, string $base): ?string
    {
        $absolute = self::normalise($absolutePath);
        $baseReal = self::normalise($base);

        if ($baseReal === null || $absolute === null) {
            return null;
        }

        $baseReal = rtrim($baseReal, '/') . '/';

        if (! Str::startsWith($absolute, $baseReal)) {
            return null;
        }

        $relative = substr($absolute, strlen($baseReal));
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));

        return self::internalPrefix() . $encoded;
    }

    private static function normalise(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $real);
    }

    private static function plainFileResponse(string $absolutePath, array $headers): BinaryFileResponse
    {
        $response = response()->file($absolutePath, array_merge([
            'Accept-Ranges' => 'bytes',
        ], $headers));

        // BinaryFileResponse already supports range requests and
        // Last-Modified / ETag validators.
        return $response;
    }

    private static function apacheAvailable(): bool
    {
        if (! function_exists('apache_get_modules')) {
            return false;
        }

        try {
            return in_array('mod_xsendfile', (array) apache_get_modules(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function nginxResponse(string $internalUri, array $headers): SymfonyResponse
    {
        // nginx passes the answer's Content-Type on with the file. Without one, Laravel's answer would carry its default
        // "text/html; charset=UTF-8" (Response::prepare()), so a video went out as an HTML page: the caller's type
        // (video/mp4 for the videos), else the type of the file's name.
        $type = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                $type = trim((string) $value);
                unset($headers[$name]);
            }
        }

        if ($type === '') {
            $path = (string) parse_url($internalUri, PHP_URL_PATH);
            $type = (string) (\Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($path, PATHINFO_EXTENSION)))[0] ?? 'application/octet-stream');
        }

        return response('', 200, array_merge([
            // The loop parameter: see accelLoop(). nginx ignores a query string when it serves a file.
            'X-Accel-Redirect' => $internalUri.'?'.self::LOOP_PARAMETER.'='.self::loopSignature($internalUri),
            'Content-Type' => $type,
        ], $headers));
    }

    private static function apacheResponse(string $absolutePath, array $headers): SymfonyResponse
    {
        return response('', 200, array_merge([
            'X-Sendfile' => $absolutePath,
            'Content-Type' => '',
        ], $headers));
    }

    private static function litespeedResponse(string $internalUri, string $absolutePath, array $headers): SymfonyResponse
    {
        // LSAPI honours `X-LiteSpeed-Location` with an internal URI, or
        // `X-LiteSpeed-Send-File` with an absolute filesystem path. We
        // emit both so vhosts without the internal rule still work.
        $response = response('', 200, array_merge([
            'X-LiteSpeed-Location' => $internalUri,
            'X-LiteSpeed-Send-File' => $absolutePath,
            'Content-Type' => '',
        ], $headers));
        $response->headers->remove('Content-Type');

        return $response;
    }
}
