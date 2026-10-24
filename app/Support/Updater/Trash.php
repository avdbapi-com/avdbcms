<?php

namespace App\Support\Updater;

use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

/**
 * Folders of the updater that are not needed any more (the work folder of a finished update with its ~18,500 unpacked
 * files and the zip, a file backup that was deleted or pruned, the staging of an earlier try) leave their place with
 * ONE rename into storage/app/updates/.trash. A rename is instant on any disk, so no request of an update spends its
 * time on them: deleting the work folder in the Finish request took 13-18 s on the 1.2.0 test sites, enough for a host
 * with a 30-second limit and a slow disk to kill Finish half-way.
 *
 * The trash is emptied later, in short slices: purge($seconds) deletes until its time is up, and the next purge goes
 * on from there (Updater::tidy(): Tools > Updates asks for slices while it is open, the scheduler every five minutes,
 * Undo before it takes the site down, a new update before it measures the free space).
 *
 * Only paths inside the work folder are taken. A path that cannot be renamed (on Windows a file somebody holds open, a
 * virus scanner looking at a new file) is deleted where it is for a short time; what is left there is found again by
 * Updater::tidy().
 */
final class Trash
{
    /** Tries of a rename that is refused for a moment (a file being scanned), 50 ms apart. */
    private const TRIES = 3;

    public function __construct(private readonly UpdatePaths $paths)
    {
    }

    /**
     * Move $path (a folder or a file in storage/app/updates) into the trash. True when it is out of the way (moved, or
     * not there at all); false when it had to be deleted in place and some of it is still there.
     */
    public function put(string $path, float $seconds = 2.0): bool
    {
        $path = $this->own($path);

        if (! self::exists($path)) {
            return true;
        }

        if ($this->makeTrash()) {
            $target = $this->paths->trash(self::entryName($path));

            for ($try = 1; $try <= self::TRIES; $try++) {
                if (@rename($path, $target)) {
                    return true;
                }

                if (! self::exists($path)) {
                    return true;
                }

                if ($try < self::TRIES) {
                    usleep(50_000);
                }
            }
        }

        return self::delete($path, microtime(true) + max(0.0, $seconds))['done'];
    }

    /**
     * Move everything in $folder but the names in $keep into one trash entry; the folder itself stays. False when
     * something had to be deleted in place and is still there.
     *
     * @param  list<string>  $keep
     */
    public function putChildren(string $folder, array $keep = [], float $seconds = 2.0): bool
    {
        $folder = $this->own($folder);

        if (! is_dir($folder) || is_link($folder)) {
            return true;
        }

        $names = array_values(array_filter(scandir($folder) ?: [], fn (string $name): bool => $name !== '.' && $name !== '..' && ! in_array($name, $keep, true)));

        if ($names === []) {
            return true;
        }

        $entry = $this->makeTrash() ? $this->paths->trash(self::entryName($folder)) : null;

        if ($entry !== null && ! @mkdir($entry, 0755) && ! is_dir($entry)) {
            $entry = null;
        }

        $deadline = microtime(true) + max(0.0, $seconds);
        $clean = true;

        foreach ($names as $name) {
            $from = $folder.'/'.$name;

            if ($entry !== null && @rename($from, $entry.'/'.$name)) {
                continue;
            }

            if (self::exists($from)) {
                $clean = self::delete($from, $deadline)['done'] && $clean;
            }
        }

        return $clean;
    }

    /** Is anything waiting in the trash? */
    public function pending(): bool
    {
        $trash = $this->paths->trash();

        if (! is_dir($trash)) {
            return false;
        }

        $handle = @opendir($trash);

        if ($handle === false) {
            return false;
        }

        try {
            while (($name = readdir($handle)) !== false) {
                if ($name !== '.' && $name !== '..') {
                    return true;
                }
            }
        } finally {
            closedir($handle);
        }

        return false;
    }

    /**
     * Delete what is in the trash for at most $seconds (one purge at a time: while another runs, this one answers at
     * once with busy). 'deleted' counts the files and folders this purge removed; 'pending' says whether anything is
     * left.
     *
     * @return array{pending: bool, deleted: int, busy: bool}
     */
    public function purge(float $seconds): array
    {
        $deadline = microtime(true) + max(0.0, $seconds);
        $trash = $this->paths->trash();

        if (! is_dir($trash)) {
            return ['pending' => false, 'deleted' => 0, 'busy' => false];
        }

        $lock = @fopen($this->paths->trashLock(), 'c');
        $wouldBlock = 0;

        if ($lock !== false && ! flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);

            if ($wouldBlock) {
                return ['pending' => true, 'deleted' => 0, 'busy' => true];
            }

            // A disk without file locks (some network file systems): the purge runs without one.
            $lock = false;
        }

        $deleted = 0;

        try {
            foreach (scandir($trash) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if (microtime(true) >= $deadline) {
                    break;
                }

                $deleted += self::delete($trash.'/'.$name, $deadline)['deleted'];
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return ['pending' => $this->pending(), 'deleted' => $deleted, 'busy' => false];
    }

    /**
     * Delete a file, a link or a folder with everything in it until $deadline (a microtime() value). Links are never
     * followed: a link is removed, never what it points to.
     *
     * @return array{done: bool, deleted: int}
     */
    private static function delete(string $path, float $deadline): array
    {
        if (is_link($path) || ! is_dir($path)) {
            $gone = self::remove($path);

            return ['done' => $gone, 'deleted' => $gone ? 1 : 0];
        }

        $deleted = 0;

        try {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($items as $item) {
                if (microtime(true) >= $deadline) {
                    return ['done' => false, 'deleted' => $deleted];
                }

                $name = $item->getPathname();

                if ($item->isDir() && ! $item->isLink() ? @rmdir($name) : self::remove($name)) {
                    $deleted++;
                }
            }
        } catch (UnexpectedValueException) {
            // A folder this PHP may not read: left for a later try.
            return ['done' => false, 'deleted' => $deleted];
        }

        if (@rmdir($path)) {
            return ['done' => true, 'deleted' => $deleted + 1];
        }

        return ['done' => ! self::exists($path), 'deleted' => $deleted];
    }

    /**
     * A file or a link. On Windows a link to a folder or a junction is removed with rmdir (which takes the link away,
     * never what it points to: PHP may not even report a junction as a folder or a link); a read-only file is made
     * writable once.
     */
    private static function remove(string $path): bool
    {
        if (@unlink($path)) {
            return true;
        }

        clearstatcache(true, $path);

        if (@rmdir($path)) {
            return true;
        }

        if (is_file($path) && function_exists('chmod') && @chmod($path, 0666) && @unlink($path)) {
            return true;
        }

        return ! self::exists($path);
    }

    private static function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    /** The trash folder, made when needed. */
    private function makeTrash(): bool
    {
        $trash = $this->paths->trash();

        return is_dir($trash) || @mkdir($trash, 0755, true) || is_dir($trash);
    }

    /** A path of the work folder (never the work folder itself, never the trash or anything in it), with "/". */
    private function own(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $work = rtrim(str_replace('\\', '/', $this->paths->work()), '/');
        $trash = $work.'/.trash';
        $relative = str_starts_with($path, $work.'/') ? substr($path, strlen($work) + 1) : '';

        if ($relative === '' || $path === $trash || str_starts_with($path, $trash.'/') || in_array('..', explode('/', $relative), true)) {
            throw new LogicException('Only the updater\'s own work files go to its trash: '.$path);
        }

        return $path;
    }

    /** "<name>-<YmdHis>-<6 hex>": unique, and says what it was and since when it waits. */
    private static function entryName(string $path): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path));

        return substr($name !== '' ? $name : 'item', 0, 60).'-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
    }
}
