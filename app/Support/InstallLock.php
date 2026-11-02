<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Throwable;

/**
 * The lock of one step of the web installer (App\Support\Installer::runStep()): two tabs, two clicks or a page that is
 * reloaded during a step never work on the database at the same time, and a request the host ended never holds the next
 * one up (R1B-001).
 *
 * Like App\Support\FinishLock (the update's Finish), it is made only of locks that the operating system and the
 * database release by themselves, however the request ends - also when PHP-FPM ends it with SIGTERM and no PHP code runs
 * any more (no "finally", no shutdown function):
 *  - a file lock (flock) on storage/framework/avd-install.lock, released when the process ends or closes the file;
 *  - on MySQL and MariaDB, a named lock (GET_LOCK) of the database session the installer works on, released when that
 *    connection closes - only after a statement the ended request had started is over, so a table that is still being
 *    built is never built a second time meanwhile.
 * Where neither exists (a disk without file locks and a database without named locks) the installer goes by the age of
 * its "running" note instead (Installer::runStep()).
 */
final class InstallLock
{
    private ?string $session = null;

    private ?Connection $connection = null;

    /** @param resource|null $handle */
    private function __construct(private mixed $handle)
    {
    }

    /**
     * The file lock (null $file: none, one process works alone), or null while another request holds it.
     */
    public static function acquire(?string $file): ?self
    {
        if ($file === null) {
            return new self(null);
        }

        $handle = self::fileLock($file);

        return $handle === false ? null : new self($handle);
    }

    /**
     * Adds the named lock of the database the installer works on. False while another session holds it (the caller then
     * releases this lock and answers "busy"); true when this request holds it, or where the database has none.
     */
    public function withDatabase(?Connection $connection): bool
    {
        if ($connection === null) {
            return true;
        }

        try {
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return true;
            }

            $name = 'avd-install:'.substr(sha1($connection->getDatabaseName().'|'.$connection->getTablePrefix()), 0, 24);
            $row = $connection->selectOne('SELECT GET_LOCK(?, 0) AS got', [$name], false);
            $got = is_object($row) ? ($row->got ?? null) : null;
        } catch (Throwable) {
            // No named locks here (a Galera cluster), or the database does not answer: the step reports that itself.
            return true;
        }

        if ((string) $got === '0') {
            return false;
        }

        if ((string) $got === '1') {
            $this->session = $name;
            $this->connection = $connection;
        }

        return true;
    }

    /** Whether this lock holds anything at all (false: neither a file lock nor a named lock exists here). */
    public function real(): bool
    {
        return $this->handle !== null || $this->session !== null;
    }

    /** Release both parts (safe to call twice). */
    public function release(): void
    {
        if ($this->session !== null && $this->connection !== null) {
            try {
                $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->session], false);
            } catch (Throwable) {
                // The connection is gone, and its named lock with it.
            }
        }

        $this->session = null;
        $this->connection = null;
        self::closeFile($this->handle);
        $this->handle = null;
    }

    /**
     * The lock file, locked: the open file; false while another request holds it; null where this disk cannot lock files.
     *
     * @return resource|false|null
     */
    private static function fileLock(string $file): mixed
    {
        $directory = dirname($file);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $handle = @fopen($file, 'c');

        if ($handle === false) {
            return null;
        }

        $wouldBlock = 0;

        if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return $handle;
        }

        fclose($handle);

        // Windows reports a lock held by another process without setting $wouldBlock: a lockable file that did not lock
        // is held by somebody else.
        return $wouldBlock || PHP_OS_FAMILY === 'Windows' ? false : null;
    }

    /** @param resource|null $handle */
    private static function closeFile(mixed $handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        try {
            flock($handle, LOCK_UN);
        } catch (Throwable) {
        }

        @fclose($handle);
    }
}
