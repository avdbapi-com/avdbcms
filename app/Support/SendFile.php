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
 * Because a misconfigured accelerator will 404 every video, this helper
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

        $mode = self::effectiveMode();
        $serverSupport = self::webServerSupportsMode($mode);

        if ($mode === self::MODE_NONE || ! $serverSupport) {
            return self::plainFileResponse($absolutePath, $headers);
        }

        $base = $internalBase ?? storage_path('app');
        $internalUri = self::buildInternalUri($absolutePath, $base);

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

    public static function effectiveMode(): string
    {
        $configured = strtolower(trim((string) (
            config('site.stream_accelerator')
            ?: RuntimeEnv::get('STREAM_ACCELERATOR', self::MODE_AUTO)
        )));

        $modes = [self::MODE_AUTO, self::MODE_NONE, self::MODE_NGINX, self::MODE_APACHE, self::MODE_LITESPEED];

        if (! in_array($configured, $modes, true)) {
            $configured = self::MODE_AUTO;
        }

        if ($configured !== self::MODE_AUTO) {
            return $configured;
        }

        return match (Environment::webServer()) {
            Environment::WEB_NGINX => self::MODE_NGINX,
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
        $response = response('', 200, array_merge([
            'X-Accel-Redirect' => $internalUri,
            // Clear content headers so nginx can decide them itself.
            'Content-Type' => '',
        ], $headers));
        $response->headers->remove('Content-Type');

        return $response;
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
