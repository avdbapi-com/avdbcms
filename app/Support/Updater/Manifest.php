<?php

namespace App\Support\Updater;

use App\Support\AppVersion;

/**
 * avdbcms-manifest.json: every package (full and update) carries it at its root, and the copy at the site root is
 * the manifest of the installed version.
 *
 *   {"product":"AVDBCMS","version":"X.Y.Z","requires_php":"8.3.0","min_from":"1.2.0","built_at":"...",
 *    "files":{"<path>":"<sha256>",...},"removed":["<path>",...]}
 *
 * files: every file of that package except bin/, storage/ and database/install/ (and the manifest itself);
 * removed: the paths of the previous release's manifest that this release no longer has. The installed manifest
 * tells shipped files (live hash = its hash) from files the owner changed.
 */
final class Manifest
{
    public const FILE = 'avdbcms-manifest.json';

    /** A manifest of 20,000 files is about 2 MB. */
    private const MAX_BYTES = 32 * 1024 * 1024;

    /**
     * @param  array<string, string>  $files  path => sha256 (lower-case hex)
     * @param  list<string>  $removed
     */
    public function __construct(
        public readonly string $version,
        public readonly string $requiresPhp,
        public readonly string $minFrom,
        public readonly array $files,
        public readonly array $removed,
        public readonly string $builtAt = '',
    ) {
    }

    /** Read and check a manifest; an UpdateException says what is wrong with it. */
    public static function parse(string $json): self
    {
        $invalid = fn (string $why): UpdateException => new UpdateException(
            __('The update package has no valid list of its files. Nothing on your site was changed.'), 'verify', false, 'manifest: '.$why
        );

        if (strlen($json) > self::MAX_BYTES) {
            throw $invalid('too big');
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw $invalid('not JSON');
        }

        if (($data['product'] ?? null) !== AppVersion::PRODUCT) {
            throw $invalid('another product');
        }

        foreach (['version', 'requires_php', 'min_from'] as $field) {
            if (! AppVersion::isVersion($data[$field] ?? null)) {
                throw $invalid($field.' is not X.Y.Z');
            }
        }

        if (! is_array($data['files'] ?? null) || $data['files'] === [] || array_is_list($data['files'])) {
            throw $invalid('no files');
        }

        $files = [];

        foreach ($data['files'] as $path => $hash) {
            $path = (string) $path;

            if (! self::safePath($path) || ! is_string($hash) || preg_match('/^[0-9a-fA-F]{64}\z/', $hash) !== 1) {
                throw $invalid('bad entry '.substr($path, 0, 120));
            }

            $files[$path] = strtolower($hash);
        }

        $removed = [];

        foreach ((array) ($data['removed'] ?? []) as $path) {
            if (! is_string($path) || ! self::safePath($path)) {
                throw $invalid('bad removed path');
            }

            $removed[] = $path;
        }

        return new self($data['version'], $data['requires_php'], $data['min_from'], $files, array_values(array_unique($removed)), (string) ($data['built_at'] ?? ''));
    }

    /** The manifest of the installed version (the site root's copy), or null when the site has none or it is broken. */
    public static function installed(string $file): ?self
    {
        if (! is_file($file)) {
            return null;
        }

        try {
            return self::parse((string) file_get_contents($file));
        } catch (UpdateException) {
            return null;
        }
    }

    /** The shipped SHA-256 of a path, or null when this release did not ship it. */
    public function hash(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    /**
     * Can this package go over the running site? PHP at least requires_php, the installed version at least min_from
     * and older than this one. The reason comes as an UpdateException (nothing has changed yet when it is asked).
     */
    public function assertInstallable(string $installed, string $php = PHP_VERSION): void
    {
        if (version_compare($php, $this->requiresPhp, '<')) {
            throw new UpdateException(__('AVDBCMS :version needs PHP :required or newer, and this server runs PHP :current. Ask your host to switch the site to a newer PHP, then try again.', [
                'version' => $this->version,
                'required' => $this->requiresPhp,
                'current' => $php,
            ]), 'verify', false);
        }

        if (version_compare($installed, $this->minFrom, '<')) {
            throw new UpdateException(__('AVDBCMS :version can only be installed over :min or newer, and this site has :current. Install :min first.', [
                'version' => $this->version,
                'min' => $this->minFrom,
                'current' => $installed,
            ]), 'verify', false);
        }

        if (! version_compare($this->version, $installed, '>')) {
            throw new UpdateException(__('This package is AVDBCMS :version, which is not newer than your :current.', [
                'version' => $this->version,
                'current' => $installed,
            ]), 'verify', false);
        }
    }

    /**
     * A path a manifest may name: relative, forward slashes, no "." / ".." / empty parts, no control characters,
     * not one of the places an update never writes.
     */
    public static function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 400 || preg_match('/[\x00-\x1F\x7F\\\\:]/', $path) === 1 || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return ! self::preserved($path);
    }

    /**
     * Paths an update never writes, deletes or backs up: site secrets, storage/, compiled caches, upload folders,
     * bin/ and the installer's database/install/. Compared without case: where the file system ignores it (Windows,
     * macOS), .ENV is .env and public/Uploads is public/uploads.
     */
    public static function preserved(string $path): bool
    {
        $path = strtolower($path);
        $leaf = basename($path);

        if ((str_starts_with($leaf, '.env') && $leaf !== '.env.example') || $leaf === '.user.ini') {
            return true;
        }

        if (str_starts_with($path, 'bootstrap/cache/') && str_ends_with($path, '.php')) {
            return true;
        }

        foreach (['storage/', 'bin/', 'database/install/', 'public/storage/', 'public/uploads/', 'public/thumbnails/', 'public/avatars/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return in_array($path, ['storage', 'bin', 'public/storage', 'public/uploads', 'public/thumbnails', 'public/avatars'], true);
    }
}
