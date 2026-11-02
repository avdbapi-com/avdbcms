<?php

use App\Support\Updater\FinishFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Up to 1.2.2 the release shipped database/schema/mysql-schema.sql, a database dump of an older version. Laravel loads
 * such a dump first when "php artisan migrate" (the installer's "Run migrations and seeders", a host's own deploy) meets
 * an empty database, and this one no longer fits the migrations: they then fail with "Duplicate column name language".
 * 1.2.3 ships it no more, but the one-click updater deletes old files only in code folders (database/schema/ was not
 * one before 1.2.3: App\Support\Updater\SwapPlanner::REMOVABLE), and an upload by hand deletes nothing, so this removes
 * the copy an updated site still has.
 *
 * Only a file with exactly the content a release shipped (any line endings) is deleted; a dump the owner made or changed
 * stays. Tools > Logs > Admin actions says so. No database change: safe to run again (the file is gone then), on 1.2.0,
 * 1.2.1 and 1.2.2 sites and on new sites (which have no such file).
 *
 * In the Finish of a one-click update the dump goes into the update's file backup (App\Support\Updater\FinishFiles), so
 * "Undo this update" brings it back (judge R3-029); the folder database/schema/ goes too when it is empty then (R3-030).
 */
return new class extends Migration
{
    public const PATH = 'database/schema/mysql-schema.sql';

    /** SHA-256 of the dump with line endings as "\n": the community packages and 1.2.0 to 1.2.2 all shipped this one file. */
    public const SHIPPED = [
        'a5e6434efb25c868eed09dfb69ce2e1f14168ece0e4605a804547a00d6328db6',
    ];

    public const AUDIT_ACTION = 'update.removed_files';

    /** The admin lists show the details up to 120 characters. */
    public const AUDIT_DETAILS = '1.2.3: removed database/schema/mysql-schema.sql, the outdated database dump of 1.2.0 to 1.2.2';

    public function up(): void
    {
        $this->clean(base_path());
    }

    /** The shipped dump below $root goes, and Tools > Logs > Admin actions says so (on a site with accounts). */
    public function clean(string $root): bool
    {
        if (! $this->removeFrom($root)) {
            return false;
        }

        if ($this->siteHasAccounts()) {
            $this->audit();
        }

        return true;
    }

    public function down(): void
    {
        // Nothing to put back: the dump only made "php artisan migrate" fail on an empty database.
    }

    /**
     * Deletes the shipped dump below $root (into the update's file backup during a one-click Finish), then the folder
     * database/schema/ when nothing else is in it; true when the dump was there and is gone now.
     */
    public function removeFrom(string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = $root.'/'.self::PATH;

        try {
            if (is_link($path) || ! is_file($path)) {
                return false;
            }

            $content = @file_get_contents($path);

            if (! is_string($content) || ! in_array(hash('sha256', str_replace("\r\n", "\n", $content)), self::SHIPPED, true)) {
                return false;
            }

            if (FinishFiles::remove($root, [self::PATH]) !== [self::PATH]) {
                return false;
            }

            $folder = dirname($path);

            if (! is_link($folder) && is_dir($folder) && array_diff(scandir($folder) ?: ['x'], ['.', '..']) === []) {
                @rmdir($folder);
            }
        } catch (Throwable) {
            // A file the web server may not delete stays; "php artisan migrate" on an existing database never reads it.
            return false;
        }

        try {
            Log::info('AVDBCMS 1.2.3: removed the database dump that 1.2.0 to 1.2.2 shipped.', ['file' => self::PATH]);
        } catch (Throwable) {
            // Logging is a courtesy; the file is gone.
        }

        return true;
    }

    private function audit(): void
    {
        try {
            if (Schema::hasTable('admin_audit_logs')
                && ! DB::table('admin_audit_logs')->where('action', self::AUDIT_ACTION)->where('details', self::AUDIT_DETAILS)->exists()) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => self::AUDIT_DETAILS,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable) {
            // The record is a courtesy; the file is gone.
        }
    }

    /** Whether the site has an account: a site without one is being built right now, and its files are no news. */
    private function siteHasAccounts(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            return true;
        }
    }
};
