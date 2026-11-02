<?php

use App\Support\Updater\FinishFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Up to 1.2.2 the release shipped 20 pictures that no page of 1.2.3 shows (the old sign-in and error pages: 403, 404,
 * 410, 500 and 503.jpg in images/errors/, forgot, login, register, subscriptions.png and reset.jpg in images/pages/, each
 * also under public/images/; about 9 MB, served to anybody who asks). 1.2.3 ships them no more (its manifest lists them
 * as removed), but the one-click updater of the installed release deletes old files only in code folders
 * (App\Support\Updater\SwapPlanner::REMOVABLE) and an upload by hand deletes nothing, so they stayed on every updated
 * site (judge R3-024). This removes them.
 *
 * Only a picture with exactly the bytes a release shipped goes (1.2.0, 1.2.1 and 1.2.2 shipped the same 20 files, and so
 * did the community packages before them); a picture the owner replaced stays. images/errors/ and public/images/errors/
 * go when nothing else is left in them (images/pages/ keeps contact.png). In the Finish of a one-click update the
 * pictures go into the update's file backup (App\Support\Updater\FinishFiles), so "Undo this update" brings them back.
 * Tools > Logs > Admin actions says how many went and how many the owner had changed. No database change: safe to run
 * again (the pictures are gone then), on 1.2.0 to 1.2.2 sites and on new sites (which have none).
 */
return new class extends Migration
{
    /** SHA-256 of each picture as 1.2.0, 1.2.1, 1.2.2 and the community packages shipped it (the same bytes in all). */
    public const SHIPPED = [
        'images/errors/403.jpg' => 'f178354cbe1bfab72c600ecab39c5f36e250f7d2d1478d79e3d73c448b49b236',
        'images/errors/404.jpg' => 'e42ca1f2baabfc6778494aa0dbfd0e18a21107ee25acdef9ffa69ee4962ecb7e',
        'images/errors/410.jpg' => 'e42ca1f2baabfc6778494aa0dbfd0e18a21107ee25acdef9ffa69ee4962ecb7e',
        'images/errors/500.jpg' => '0c64a502cdc1a5a169755aba72b35f43499761181ade10bb6cd825e6c4676b52',
        'images/errors/503.jpg' => '78975a76e77ea4f5a221b8d760f443e99c8e9ef0ae5dd0be6c8dc928bded435d',
        'images/pages/forgot.png' => 'b45a38d8ae1a92006e7872426cfe80e059550934f99073288cf2a7056b1b111e',
        'images/pages/login.png' => '859787e97641356a5e5c604c62f72314094cd231746d6c07e838355f734432ac',
        'images/pages/register.png' => '7a7ba879cefb347fa91171b9ce922628e833fc90bf2c71a29525b159771a1bbd',
        'images/pages/reset.jpg' => '71f3e3ea7b2f00f496adb6c1d3e4c4e50ef53f92f8d65bfa329754661916c2ad',
        'images/pages/subscriptions.png' => '3da335680a0ea791fcf1fce3ebeec816ab7b47e445aab83abb067b5af1a0a79c',
        'public/images/errors/403.jpg' => 'f178354cbe1bfab72c600ecab39c5f36e250f7d2d1478d79e3d73c448b49b236',
        'public/images/errors/404.jpg' => 'e42ca1f2baabfc6778494aa0dbfd0e18a21107ee25acdef9ffa69ee4962ecb7e',
        'public/images/errors/410.jpg' => 'e42ca1f2baabfc6778494aa0dbfd0e18a21107ee25acdef9ffa69ee4962ecb7e',
        'public/images/errors/500.jpg' => '0c64a502cdc1a5a169755aba72b35f43499761181ade10bb6cd825e6c4676b52',
        'public/images/errors/503.jpg' => '78975a76e77ea4f5a221b8d760f443e99c8e9ef0ae5dd0be6c8dc928bded435d',
        'public/images/pages/forgot.png' => 'b45a38d8ae1a92006e7872426cfe80e059550934f99073288cf2a7056b1b111e',
        'public/images/pages/login.png' => '859787e97641356a5e5c604c62f72314094cd231746d6c07e838355f734432ac',
        'public/images/pages/register.png' => '7a7ba879cefb347fa91171b9ce922628e833fc90bf2c71a29525b159771a1bbd',
        'public/images/pages/reset.jpg' => '71f3e3ea7b2f00f496adb6c1d3e4c4e50ef53f92f8d65bfa329754661916c2ad',
        'public/images/pages/subscriptions.png' => '3da335680a0ea791fcf1fce3ebeec816ab7b47e445aab83abb067b5af1a0a79c',
    ];

    /**
     * The folders of these pictures: each goes when nothing is left in it (the errors/ folders held only these; pages/
     * keeps the contact page's contact.png, so it only goes where an owner deleted that too).
     */
    public const FOLDERS = ['images/errors', 'public/images/errors', 'images/pages', 'public/images/pages'];

    public const AUDIT_ACTION = 'update.removed_files';

    public function up(): void
    {
        // The test suite migrates a database in the project folder: its files are not a site's to remove (the tests run
        // removeFrom() on a folder of their own).
        if (app()->runningUnitTests()) {
            return;
        }

        $this->clean(base_path());
    }

    public function down(): void
    {
        // Nothing to put back: no page shows them (Undo of a one-click update brings them back from its file backup).
    }

    /**
     * The pictures below $root go, and Tools > Logs > Admin actions says how many (on a site with accounts).
     *
     * @param  array<string, string>|null  $pictures  tests: other pictures and their SHA-256 (null: SHIPPED)
     */
    public function clean(string $root, ?array $pictures = null): bool
    {
        $result = $this->removeFrom($root, $pictures);

        if ($result['removed'] === []) {
            return false;
        }

        if ($this->siteHasAccounts()) {
            $this->audit(self::details(count($result['removed']), count($result['changed'])));
        }

        return true;
    }

    /**
     * Removes the shipped pictures below $root (into the update's file backup during a one-click Finish), then the
     * emptied folders.
     *
     * @param  array<string, string>|null  $pictures  tests: other pictures and their SHA-256 (null: SHIPPED)
     * @return array{removed: list<string>, changed: list<string>} the paths that went; the pictures the owner changed (they stay)
     */
    public function removeFrom(string $root, ?array $pictures = null): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $shipped = [];
        $changed = [];

        foreach ($pictures ?? self::SHIPPED as $relative => $hash) {
            $path = $root.'/'.$relative;

            try {
                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                $actual = @hash_file('sha256', $path);

                if (! is_string($actual)) {
                    continue;
                }

                if (hash_equals($hash, $actual)) {
                    $shipped[] = $relative;
                } else {
                    $changed[] = $relative;
                }
            } catch (Throwable) {
                // A picture that cannot be read stays; no page shows it.
            }
        }

        try {
            $removed = $shipped === [] ? [] : array_values(array_intersect($shipped, FinishFiles::remove($root, $shipped)));
        } catch (Throwable) {
            // A picture the web server may not delete stays; no page shows it.
            $removed = [];
        }

        foreach (self::FOLDERS as $relative) {
            $folder = $root.'/'.$relative;

            try {
                if (! is_link($folder) && is_dir($folder) && array_diff(scandir($folder) ?: ['x'], ['.', '..']) === []) {
                    @rmdir($folder);
                }
            } catch (Throwable) {
            }
        }

        if ($removed !== []) {
            try {
                Log::info('AVDBCMS 1.2.3: removed the unused sign-in and error pictures that 1.2.0 to 1.2.2 shipped.', ['files' => $removed, 'kept_changed' => $changed]);
            } catch (Throwable) {
                // Logging is a courtesy; the pictures are gone.
            }
        }

        return ['removed' => $removed, 'changed' => $changed];
    }

    /** The Admin actions line (App\Support\AuditDetails shows it in the admin's language). */
    public static function details(int $removed, int $changed): string
    {
        return '1.2.3: removed '.$removed.' unused '.($removed === 1 ? 'picture' : 'pictures').' of the sign-in and error pages (images/, public/images/)'
            .($changed > 0 ? '; '.$changed.' you changed '.($changed === 1 ? 'was' : 'were').' kept' : '');
    }

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
            // The record is a courtesy; the pictures are gone.
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
