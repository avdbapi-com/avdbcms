<?php

namespace App\Support\Updater;

use RuntimeException;

/**
 * The updater's own state, in files (not the cache store, so clearing the cache in the middle of an update drops
 * nothing): plan.json (the update or undo running or last run, its status and progress), state.json (written after
 * each update: installed_version, previous_version, updated_at, backup_id, admin_id) and update.lock (one step at a
 * time: a non-blocking flock around every step; the maintenance hook takes the same lock).
 */
final class PlanStore
{
    public function __construct(private readonly UpdatePaths $paths)
    {
    }

    public function get(): ?array
    {
        return self::read($this->paths->plan());
    }

    public function put(array $plan): void
    {
        self::write($this->paths->plan(), $plan);
    }

    public function state(): ?array
    {
        return self::read($this->paths->state());
    }

    public function putState(array $state): void
    {
        self::write($this->paths->state(), $state);
    }

    /**
     * Take the update lock without waiting.
     *
     * @return resource|null null when another step holds it
     *
     * @throws UpdateException when the disk cannot lock files at all (some network file systems): not "busy" forever
     */
    public function lock()
    {
        $folder = $this->paths->work();

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException('Cannot create storage/app/updates.');
        }

        $handle = @fopen($this->paths->lock(), 'c');

        if ($handle === false) {
            throw new RuntimeException('Cannot open the update lock.');
        }

        $wouldBlock = 0;

        if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);

            if (! $wouldBlock) {
                throw new UpdateException(__('This server cannot lock files in storage/app/updates, so an update cannot run safely there. Ask your host about file locking (flock) on this disk.'), 'update', false, 'flock not supported');
            }

            return null;
        }

        return $handle;
    }

    /** @param  resource|null  $handle */
    public function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function read(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** Write JSON through a temporary file and a rename, so a reader never sees half a file. */
    public static function write(string $file, array $data): void
    {
        $folder = dirname($file);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new UpdateException(__('Could not write :file. The disk may be full or the folder read-only.', ['file' => basename($file)]));
        }

        try {
            // Never an empty file: a text that is not UTF-8 (a reason quoted from an error) becomes U+FFFD.
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UpdateException(__('Could not write :file. The disk may be full or the folder read-only.', ['file' => basename($file)]), 'update', true, 'json: '.$e->getMessage(), $e);
        }

        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new UpdateException(__('Could not write :file. The disk may be full or the folder read-only.', ['file' => basename($file)]));
        }
    }
}
