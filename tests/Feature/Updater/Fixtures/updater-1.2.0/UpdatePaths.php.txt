<?php

namespace App\Support\Updater;

/**
 * Where the updater reads and writes. In the site: the site root (base_path()) and its storage/ folder; tests give a
 * site root of their own. The updater's work folder is storage/app/updates (plan, state, lock, one folder per version,
 * the file backups of each update); the database backup goes to storage/app/backups like every other backup.
 */
final class UpdatePaths
{
    public readonly string $root;

    public readonly string $storage;

    public function __construct(string $root, ?string $storage = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->storage = rtrim(str_replace('\\', '/', $storage ?? $this->root.'/storage'), '/');
    }

    public static function forApp(): self
    {
        return new self(base_path(), storage_path());
    }

    /** A path inside the site ("app/Models/User.php" -> <root>/app/Models/User.php). */
    public function root(string $path = ''): string
    {
        return $path === '' ? $this->root : $this->root.'/'.ltrim($path, '/');
    }

    public function work(string $path = ''): string
    {
        $work = $this->storage.'/app/updates';

        return $path === '' ? $work : $work.'/'.ltrim($path, '/');
    }

    public function plan(): string
    {
        return $this->work('plan.json');
    }

    public function state(): string
    {
        return $this->work('state.json');
    }

    public function lock(): string
    {
        return $this->work('update.lock');
    }

    /** The work folder of one update (download, staging, journal, step log) or undo. */
    public function job(string $name): string
    {
        return $this->work($name);
    }

    /** The file backups of finished updates: backups/<from>-to-<to>-<YmdHis>/{files/, backup.json}. */
    public function backups(string $id = ''): string
    {
        return $this->work('backups'.($id === '' ? '' : '/'.$id));
    }

    /** Tools > Backups: where the database backup of an update goes. */
    public function databaseBackups(): string
    {
        return $this->storage.'/app/backups';
    }

    /** Laravel's maintenance hook: public/index.php requires it (before vendor/autoload.php) when it exists. */
    public function hook(): string
    {
        return $this->storage.'/framework/maintenance.php';
    }

    /** Laravel's "down" file: while it exists the scheduler and the queue worker pause. */
    public function down(): string
    {
        return $this->storage.'/framework/down';
    }

    public function bootstrapCache(): string
    {
        return $this->root.'/bootstrap/cache';
    }

    /** The installed version's manifest (every shipped file and its SHA-256). */
    public function manifest(): string
    {
        return $this->root.'/'.Manifest::FILE;
    }
}
