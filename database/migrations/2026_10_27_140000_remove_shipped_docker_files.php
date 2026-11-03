<?php

use App\Support\Updater\FinishFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Up to 1.2.2 (and in the community packages before it) the release shipped docker-compose.yml and docker/ (Dockerfile,
 * nginx.conf, php.ini, policy.json): the development stack of the upstream project, not an AVDBCMS deployment. It cannot
 * start ("pull access denied for minio/minio"; a MariaDB health check with a mysqladmin that image no longer has), and
 * no document of the package uses it (containers: 1Panel, see the setup guide). 1.2.3 ships it no more, but the one-click
 * updater of the installed release deletes old files only in code folders (App\Support\Updater\SwapPlanner::REMOVABLE),
 * and an upload by hand deletes nothing, so this removes the copies an updated site still has.
 *
 * Only a file with exactly the content a release shipped (any line endings) is deleted; a file the owner made or changed
 * stays, and so does docker/ when anything else is left in it. Tools > Logs > Admin actions says what went, and how many
 * of the shipped files the owner had changed (they stay: review UP-RV-03). No database change: safe to run again (the
 * files are gone then), on 1.2.0 to 1.2.2 sites and on new sites (which have none).
 *
 * In the Finish of a one-click update the files go into the update's file backup (App\Support\Updater\FinishFiles), so
 * "Undo this update" brings them back (judge R3-029).
 */
return new class extends Migration
{
    /** SHA-256 of each file with line endings as "\n": the 1.2.0 to 1.2.2 packages, then the community packages. */
    public const SHIPPED = [
        'docker-compose.yml' => [
            '65c48f90d8decb905cab940a2db1c2cf6f1efe9eede2c0db69f4d60003748af4',
            '7da0ac988246793f9e9e5f86e5135240ed93c01351bd392b582164cdd81e659c',
        ],
        'docker/Dockerfile' => [
            '6c06f8e6fc9451d5fb5dee28be5223d1988cb677812db59381a10aafc6c28fab',
        ],
        'docker/nginx.conf' => [
            'ee149e29b2cc296f41bee8ef43123aa898b2d1b2229810e7f5b75619f50cb8f3',
            '3201a5cab9fd02f473c8fa88bff26578078149d628d73eccdfb8a72f0ad94c4b',
        ],
        'docker/php.ini' => [
            '98a51131145710d9fccc45b54ef74cd63295d962a999c1f2aef8517467c339c3',
        ],
        'docker/policy.json' => [
            'b332da37fca68c30c557a91bc12a95533f4288a094ed131c9143aea5c15325e4',
            '3e79496eb06952efa4374e6cd8c32d737ac80ebc4733d0d8a57ba50548d177ee',
        ],
    ];

    public const AUDIT_ACTION = 'update.removed_files';

    /** The admin lists show the details up to 120 characters. Every shipped file went (details()). */
    public const AUDIT_DETAILS = '1.2.3: removed the shipped development Docker files of the upstream project (docker-compose.yml, docker/)';

    public function up(): void
    {
        $this->clean(base_path());
    }

    /** The shipped files below $root go, and Tools > Logs > Admin actions says so (on a site with accounts). */
    public function clean(string $root): bool
    {
        $removed = $this->removeFrom($root);

        if ($removed === []) {
            return false;
        }

        if ($this->siteHasAccounts()) {
            $this->audit(self::details($removed, count($this->changedIn($root))));
        }

        return true;
    }

    /**
     * The Admin actions line (App\Support\AuditDetails shows it in the admin's language): where the files went from, and
     * how many of the shipped files the owner had changed, which stay with docker/ (review UP-RV-03; like the pictures
     * row of 2026_10_29_100000). AUDIT_DETAILS when every file there was went and none was changed.
     *
     * @param  list<string>  $removed  the paths that went (removeFrom())
     */
    public static function details(array $removed, int $changed): string
    {
        $places = array_values(array_filter([
            in_array('docker-compose.yml', $removed, true) ? 'docker-compose.yml' : null,
            array_filter($removed, static fn (string $path): bool => str_starts_with($path, 'docker/')) !== [] ? 'docker/' : null,
        ]));

        if ($changed <= 0) {
            return '1.2.3: removed the shipped development Docker files of the upstream project ('.implode(', ', $places).')';
        }

        $count = count($removed);

        return '1.2.3: removed '.$count.' development Docker '.($count === 1 ? 'file' : 'files').' of the upstream project ('.implode(', ', $places).'); '
            .$changed.' you changed '.($changed === 1 ? 'was' : 'were').' kept';
    }

    /**
     * The shipped files below $root whose content is not one a release shipped: the owner changed them (or made a file
     * of that name), so they stay.
     *
     * @return list<string>
     */
    public function changedIn(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $changed = [];

        foreach (self::SHIPPED as $relative => $hashes) {
            $path = $root.'/'.$relative;

            try {
                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                $content = @file_get_contents($path);

                if (is_string($content) && ! in_array(hash('sha256', str_replace("\r\n", "\n", $content)), $hashes, true)) {
                    $changed[] = $relative;
                }
            } catch (Throwable) {
                // A file that cannot be read is not counted.
            }
        }

        return $changed;
    }

    public function down(): void
    {
        // Nothing to put back: the files could not start and no document used them.
    }

    /**
     * Deletes the shipped files below $root, then docker/ when it is empty.
     *
     * @return list<string> the paths that went
     */
    public function removeFrom(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $shipped = [];

        foreach (self::SHIPPED as $relative => $hashes) {
            $path = $root.'/'.$relative;

            try {
                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                $content = @file_get_contents($path);

                if (! is_string($content) || ! in_array(hash('sha256', str_replace("\r\n", "\n", $content)), $hashes, true)) {
                    continue;
                }

                $shipped[] = $relative;
            } catch (Throwable) {
                // A file that cannot be read stays; nothing reads it.
            }
        }

        try {
            // A file the web server may not delete stays; nothing reads it.
            $removed = $shipped === [] ? [] : array_values(array_intersect($shipped, FinishFiles::remove($root, $shipped)));
        } catch (Throwable) {
            $removed = [];
        }

        $folder = $root.'/docker';

        try {
            if (! is_link($folder) && is_dir($folder) && array_diff(scandir($folder) ?: ['x'], ['.', '..']) === []) {
                @rmdir($folder);
            }
        } catch (Throwable) {
        }

        if ($removed !== []) {
            try {
                Log::info('AVDBCMS 1.2.3: removed the development Docker files that 1.2.0 to 1.2.2 shipped.', ['files' => $removed]);
            } catch (Throwable) {
                // Logging is a courtesy; the files are gone.
            }
        }

        return $removed;
    }

    /**
     * One Admin actions row each time the files are actually removed. removeFrom() only returns them when they were there
     * and are gone now, so a Finish that runs this twice (the migration, then removeLeftovers()) writes one row; but after
     * "Undo this update" put the files back and the update is run again, removing them again writes its own row, as the
     * unused-pictures migration does (J2-P1: the dump and the Docker files used to stay silent on that second removal).
     */
    private function audit(string $details): void
    {
        try {
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'details' => $details,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable) {
            // The record is a courtesy; the files are gone.
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
