<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves files from the "public" disk (storage/app/public) through PHP.
 *
 * The normal setup is the public/storage symlink created by `php artisan
 * storage:link`. Many panel and shared hosts (aaPanel, CyberPanel, cPanel, ...)
 * disable PHP's symlink(), so the web installer cannot create that link and
 * every uploaded avatar, thumbnail or attachment would 404. When the symlink
 * exists the web server serves the file directly and this route is never hit;
 * when it does not, this controller streams the file instead.
 */
class StorageFallbackController extends Controller
{
    public function show(string $path): StreamedResponse
    {
        $path = trim(str_replace(chr(92), '/', $path), '/');

        if ($path === ''
            || str_contains($path, chr(0))
            || str_contains($path, '..')
            || str_starts_with($path, '.')
            || str_contains($path, '/.')) {
            throw new NotFoundHttpException();
        }

        $disk = Storage::disk('public');

        try {
            if (! $disk->fileExists($path)) {
                throw new NotFoundHttpException();
            }

            return $disk->response($path, null, [
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (PathTraversalDetected) {
            throw new NotFoundHttpException();
        }
    }
}
