<?php

namespace App\Support\Updater;

use Throwable;

/**
 * Files of the site that Finish of a one-click update removes or replaces outside the swap (judge R3-029): the database
 * dump, the development Docker files and the unused pictures that 1.2.0 to 1.2.2 shipped (their 1.2.3 migrations), the
 * starter data of new installs database/install/example.sql (Updater::refreshInstallData()), and later the old built
 * assets the tidy-up retires (Updater::tidy(), judge R3-033). Each goes into the update's file backup first, with a line
 * in its backup.json, so "Undo this update" brings the tree back byte for byte, as it does for the files the swap moved.
 *
 * backup.json says what each file IS after the change before the change happens (new: null for a file that goes, the
 * SHA-256 of the new copy for one that is replaced), and the backup holds the copy from before. A change that never
 * happens (a request cut off, a file the server may not delete) leaves the live file as the backup's line calls it old:
 * Undo then finds it "back as it was already" and leaves it alone. A file the swap itself put there keeps its content in
 * the line as "was" when it is to go: Undo also knows it while its delete has not happened. A file that changed after
 * the swap (backup.json names another content) is not touched at all.
 *
 * Without a one-click update finishing on the site (an upload by hand and Finish update, a new install, artisan migrate)
 * remove() simply deletes: the owner's own backup of the site is step 1 of the way by hand. While one may be finishing
 * and its plan.json or its backup.json cannot be read (another program holding it for a moment), nothing changes: a file
 * deleted then would be missing from its Undo (the Finish step "leftovers" and the next clean-up try again). Only the
 * files the callers name: each caller checks first that a file is exactly what a release shipped (an owner's changed
 * copy stays).
 */
final class FinishFiles
{
    /** A file backup's folder name: <from>-to-<to>-<YmdHis> (Updater::planAndGoDown()). */
    private const ID = '/^\d+\.\d+\.\d+-to-\d+\.\d+\.\d+-\d{14}\z/';

    private function __construct(
        private readonly string $folder,
    ) {
    }

    /**
     * The file backup of the one-click update that is finishing on this site now: plan.json is an update whose files
     * are in (swapped, migrating) and its backup is there. Null otherwise (no update running, an undo, a backup that
     * went).
     */
    public static function finishing(UpdatePaths $paths): ?self
    {
        $backup = self::finishingOrBusy($paths);

        return $backup instanceof self ? $backup : null;
    }

    /** The file backup $id of this site while it is in use (pending: its update is finishing; complete: Undo can use it). */
    public static function backup(UpdatePaths $paths, string $id): ?self
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }

        $folder = $paths->backups($id);
        $data = PlanStore::read($folder.'/backup.json');

        if (! is_array($data) || ! in_array($data['status'] ?? null, ['pending', 'complete'], true) || ! is_array($data['entries'] ?? null)) {
            return null;
        }

        return new self($folder);
    }

    /**
     * backup(), told apart from a backup that is there but cannot be used now: false while its backup.json exists and
     * cannot be read or is not in use (another program holding it for a moment, a copy being written). The caller then
     * changes nothing (a file changed now would be missing from Undo) and tries again later. Null when there is no such
     * backup (an id of no update, a backup deleted): there is no Undo to keep exact.
     */
    public static function backupOrBusy(UpdatePaths $paths, string $id): self|false|null
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }

        $backup = self::backup($paths, $id);

        if ($backup !== null) {
            return $backup;
        }

        $file = $paths->backups($id).'/backup.json';
        clearstatcache(true, $file);

        return file_exists($file) ? false : null;
    }

    /**
     * finishing(), and false while it cannot be told: plan.json is there but cannot be read, or the backup of the update
     * that is finishing cannot be used now (backupOrBusy()).
     */
    private static function finishingOrBusy(UpdatePaths $paths): self|false|null
    {
        $file = $paths->plan();
        clearstatcache(true, $file);

        if (! file_exists($file)) {
            return null;
        }

        $plan = PlanStore::read($file);

        if (! is_array($plan)) {
            return false;
        }

        if (($plan['kind'] ?? 'update') !== 'update' || ! in_array($plan['status'] ?? null, ['swapped', 'migrating'], true)) {
            return null;
        }

        return self::backupOrBusy($paths, (string) ($plan['backup_id'] ?? ''));
    }

    /**
     * For a migration of Finish: the files $paths (relative to the site root $root, each checked by the caller) leave the
     * site, into the update's file backup while a one-click update finishes there, else deleted. Nothing leaves while
     * that cannot be told (finishingOrBusy()).
     *
     * @param  list<string>  $paths
     * @return list<string> the paths that are gone now
     */
    public static function remove(string $root, array $paths): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $paths = array_values(array_filter(array_unique($paths), fn (mixed $path): bool => is_string($path) && SwapEngine::safePath($path)));

        if ($paths === []) {
            return [];
        }

        $backup = self::finishingOrBusy(self::pathsFor($root));

        if ($backup === false) {
            return [];
        }

        if ($backup !== null) {
            return $backup->takeOut($root, $paths);
        }

        $gone = [];

        foreach ($paths as $path) {
            $file = $root.'/'.$path;

            if (is_link($file) || ! is_file($file)) {
                continue;
            }

            if (@unlink($file)) {
                $gone[] = $path;
            }
        }

        return $gone;
    }

    /**
     * The files $paths of the site $root go into this backup: one write of backup.json first, then each file in one
     * rename (else a copy checked by its hash, then the original deleted). A file the swap itself put there (backup.json
     * names its content as the new one) is only deleted: the backup has what was there before it; its line keeps that
     * content as "was" (Undo puts the file from before back over it should the delete not happen, and the next run
     * deletes it then). A file that changed after the swap stays. Nothing moves when backup.json cannot be written.
     *
     * @param  list<string>  $paths
     * @return list<string> the paths that are gone now
     */
    public function takeOut(string $root, array $paths): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $data = PlanStore::read($this->folder.'/backup.json');

        if (! is_array($data) || ! is_array($data['entries'] ?? null)) {
            return [];
        }

        $index = self::index($data['entries']);
        $move = [];
        $drop = [];

        foreach ($paths as $path) {
            if (! is_string($path) || ! SwapEngine::safePath($path)) {
                continue;
            }

            $file = $root.'/'.$path;

            if (is_link($file) || ! is_file($file) || ($hash = SwapEngine::hash($file)) === null) {
                continue;
            }

            if (! isset($index[$path])) {
                $data['entries'][] = ['path' => $path, 'action' => 'removed', 'old' => $hash, 'new' => null];
                $move[$path] = $hash;

                continue;
            }

            $entry = $data['entries'][$index[$path]];

            if (($entry['new'] ?? null) === $hash || (($entry['new'] ?? null) === null && ($entry['was'] ?? null) === $hash)) {
                // The swap's copy (also when an earlier run wrote the line and its delete did not happen): the backup holds
                // the file from before the update (or none: the update added it).
                $data['entries'][$index[$path]]['new'] = null;
                $data['entries'][$index[$path]]['was'] = $hash;
                $drop[] = $path;
            } elseif (($entry['new'] ?? null) === null && ($entry['old'] ?? null) === $hash) {
                // An earlier run wrote the line and stopped before the move.
                $move[$path] = $hash;
            }
        }

        if ($move === [] && $drop === []) {
            return [];
        }

        try {
            PlanStore::write($this->folder.'/backup.json', $data);
        } catch (Throwable) {
            // Without the line Undo would not know the copy: nothing leaves the site.
            return [];
        }

        $gone = [];
        $moved = 0;

        foreach ($move as $path => $hash) {
            if ($this->moveIn($root.'/'.$path, $this->folder.'/files/'.$path, $hash)) {
                $gone[] = $path;
                $moved += (int) @filesize($this->folder.'/files/'.$path);
            }
        }

        foreach ($drop as $path) {
            if (@unlink($root.'/'.$path)) {
                $gone[] = $path;
            }
        }

        if ($moved > 0 && ($data['status'] ?? null) === 'complete') {
            // Tools > Updates lists a finished backup with its size.
            try {
                $data = PlanStore::read($this->folder.'/backup.json') ?? $data;
                $data['size'] = (int) ($data['size'] ?? 0) + $moved;
                PlanStore::write($this->folder.'/backup.json', $data);
            } catch (Throwable) {
                // Only the shown size.
            }
        }

        return $gone;
    }

    /**
     * Before the file $path of the site $root is replaced by a copy whose SHA-256 is $newHash: the backup keeps the file
     * as it is now and backup.json says what it will be. True when the backup has it (or the file is not there: nothing
     * to keep); false when it could not be kept, or the file changed after the swap: then do not replace it.
     */
    public function keepBefore(string $root, string $path, string $newHash): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $file = $root.'/'.$path;

        if (! SwapEngine::safePath($path) || is_link($file)) {
            return false;
        }

        if (! is_file($file)) {
            return true;
        }

        $hash = SwapEngine::hash($file);
        $data = PlanStore::read($this->folder.'/backup.json');

        if ($hash === null || ! is_array($data) || ! is_array($data['entries'] ?? null)) {
            return false;
        }

        $index = self::index($data['entries']);

        if (isset($index[$path])) {
            $entry = $data['entries'][$index[$path]];

            if (($entry['new'] ?? null) !== $hash) {
                // Changed after the swap (or a change of an earlier run that did not happen): left as it is.
                return ($entry['new'] ?? null) === $newHash;
            }

            $data['entries'][$index[$path]]['new'] = $newHash;
        } else {
            if (! $this->copyIn($file, $this->folder.'/files/'.$path, $hash)) {
                return false;
            }

            $data['entries'][] = ['path' => $path, 'action' => 'replaced', 'old' => $hash, 'new' => $newHash];
        }

        try {
            PlanStore::write($this->folder.'/backup.json', $data);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** The folder of this backup (storage/app/updates/backups/<id>). */
    public function folder(): string
    {
        return $this->folder;
    }

    /** The updater's paths of the site in $root: this site's own (its storage_path()), else <root>/storage. */
    public static function pathsFor(string $root): UpdatePaths
    {
        try {
            $site = function_exists('base_path') ? realpath(base_path()) : false;
        } catch (Throwable) {
            $site = false;
        }

        if ($site !== false && realpath($root) === $site) {
            return UpdatePaths::forApp();
        }

        return new UpdatePaths($root);
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<string, int> path => the index of its (last) line
     */
    private static function index(array $entries): array
    {
        $index = [];

        foreach ($entries as $i => $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $index[$entry['path']] = $i;
            }
        }

        return $index;
    }

    /** $from into the backup as $to (a copy there with the same content counts as done): true when $from is gone. */
    private function moveIn(string $from, string $to, string $hash): bool
    {
        if (SwapEngine::hash($to) === $hash) {
            return @unlink($from) || ! is_file($from);
        }

        if (! SwapEngine::makeFolder(dirname($to)) || (file_exists($to) && ! @unlink($to))) {
            return false;
        }

        if (@rename($from, $to)) {
            clearstatcache(true, $to);

            if (SwapEngine::hash($to) === $hash) {
                return true;
            }

            @rename($to, $from);

            return false;
        }

        if (! $this->copyIn($from, $to, $hash)) {
            return false;
        }

        if (! @unlink($from)) {
            // The live file stays (its line says it went: Undo finds it as it was and leaves it).
            return false;
        }

        return true;
    }

    /** A checked copy of $from as $to (through a temporary name next to it). */
    private function copyIn(string $from, string $to, string $hash): bool
    {
        if (SwapEngine::hash($to) === $hash) {
            return true;
        }

        if (! SwapEngine::makeFolder(dirname($to))) {
            return false;
        }

        $temporary = $to.'.avdbcms-tmp';
        if (! @copy($from, $temporary) || SwapEngine::hash($temporary) !== $hash || (file_exists($to) && ! @unlink($to)) || ! @rename($temporary, $to)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
