<?php

namespace Tests\Feature\Updater\Support;

use App\Support\AppVersion;
use App\Support\Updater\Manifest;
use App\Support\Updater\UpdatePaths;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A throwaway site root for updater tests, under storage/framework/testing (this PHP's system temp folder may not be
 * writable), with helpers to write a file tree, the installed avdbcms-manifest.json, an update zip of a release and a
 * snapshot (path => SHA-256) to compare trees byte for byte. delete() removes everything it made.
 */
final class UpdaterSandbox
{
    public readonly string $base;

    public readonly string $root;

    public function __construct(string $label = 'site')
    {
        $this->base = str_replace('\\', '/', storage_path('framework/testing/updater-'.Str::lower(Str::random(10))));
        $this->root = $this->base.'/'.$label;
        File::ensureDirectoryExists($this->root);
    }

    public function paths(): UpdatePaths
    {
        return new UpdatePaths($this->root);
    }

    /** A path inside the base folder (zips, extra trees), outside the site. */
    public function path(string $relative): string
    {
        return $this->base.'/'.ltrim($relative, '/');
    }

    /** @param  array<string, string>  $files  relative path => content */
    public function write(array $files, ?string $root = null): void
    {
        $root ??= $this->root;

        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));

            if (file_put_contents($root.'/'.$path, $content) !== strlen($content)) {
                throw new RuntimeException('Cannot write '.$path);
            }
        }
    }

    /**
     * The manifest of a package made of $files (the files a manifest lists: not bin/, storage/, database/install/, not
     * the manifest itself).
     *
     * @param  array<string, string>  $files  path => content
     * @param  list<string>  $removed
     */
    public static function manifest(string $version, array $files, array $removed = [], string $requiresPhp = '8.3.0', string $minFrom = '1.2.0'): string
    {
        $hashes = [];

        foreach ($files as $path => $content) {
            $path = (string) $path;

            if (! self::packaged($path)) {
                continue;
            }

            $hashes[$path] = hash('sha256', $content);
        }

        ksort($hashes, SORT_STRING);

        return (string) json_encode([
            'product' => AppVersion::PRODUCT,
            'version' => $version,
            'requires_php' => $requiresPhp,
            'min_from' => $minFrom,
            'built_at' => '2026-10-23T12:00:00Z',
            'files' => $hashes,
            'removed' => array_values($removed),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** A file that a manifest lists (the release build's rule). */
    public static function packaged(string $path): bool
    {
        return $path !== Manifest::FILE && ! str_starts_with($path, 'bin/') && ! str_starts_with($path, 'storage/')
            && ! str_starts_with($path, 'database/install/');
    }

    /**
     * An installed site: $shipped (the files of version $version, written with its manifest) plus $extra (site data
     * the release does not ship: .env, uploads, storage/...).
     *
     * @param  array<string, string>  $shipped
     * @param  array<string, string>  $extra
     */
    public function install(string $version, array $shipped, array $extra = []): void
    {
        $this->write($shipped + $extra);
        $this->write([Manifest::FILE => self::manifest($version, $shipped)]);
    }

    /**
     * The update zip of a release: every file of $files, its manifest, and what a real package also carries that the
     * updater skips (storage placeholders, database/install/example.sql, folder entries).
     *
     * @param  array<string, string>  $files
     * @param  list<string>  $removed
     */
    public function releaseZip(string $version, array $files, array $removed = [], ?string $manifest = null): string
    {
        $zip = new ZipBuilder();
        $zip->dir('app')->dir('storage/app/public');
        ksort($files, SORT_STRING);

        foreach ($files as $path => $content) {
            $zip->file((string) $path, $content);
        }

        $zip->file(Manifest::FILE, $manifest ?? self::manifest($version, $files, $removed))
            ->file('storage/app/.gitignore', "*\n!public/\n!.gitignore\n")
            ->file('storage/logs/.gitkeep', '')
            ->file('database/install/example.sql', "-- example\n");

        return $zip->save($this->path('release/'.AppVersion::updateAsset($version)));
    }

    /**
     * Every file under the site root (or $root) => its SHA-256, without the paths starting with one of $skip.
     *
     * @param  list<string>  $skip
     * @return array<string, string>
     */
    public function snapshot(array $skip = ['storage/'], ?string $root = null): array
    {
        $root = rtrim(str_replace('\\', '/', $root ?? $this->root), '/');
        $hashes = [];

        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);

            foreach ($skip as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }

            $hashes[$path] = (string) hash_file('sha256', $file->getPathname());
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }

    public function read(string $path): ?string
    {
        $file = $this->root.'/'.$path;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function delete(): void
    {
        File::deleteDirectory($this->base);
    }

    /** The version after CURRENT (patch + 1): the release the tests install, whatever CURRENT is. */
    public static function nextVersion(?string $version = null): string
    {
        [$major, $minor, $patch] = array_map('intval', explode('.', $version ?? AppVersion::CURRENT));

        return $major.'.'.$minor.'.'.($patch + 1);
    }
}
