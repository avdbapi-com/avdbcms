<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Up to 1.2.1 the release shipped three development seeders that nothing in AVDBCMS runs, but that anyone with a shell
 * could run by hand: UserSeeder (an admin "admin" with the password "admin" and members with the password
 * "password"), ModelSeeder (20 members with the password "password", fake videos and comments) and
 * FleetDataEnricherSeeder (20 fake members meant to have the password "password123", fake comments and playlists,
 * and new view counts for every video). 1.2.2 no longer ships them, and the one-click updater only deletes old files
 * in code folders, so this removes the copies a site still has.
 *
 * Only a file with exactly the content of a release (any line endings) is deleted; a seeder the owner changed or added
 * stays. No database change: safe to run again (the files are gone then), on 1.2.0 and 1.2.1 sites and on new sites.
 */
return new class extends Migration
{
    /** file name => SHA-256 of each shipped version, with line endings as "\n" (community packages, 1.2.0, 1.2.1) */
    public const SHIPPED = [
        'FleetDataEnricherSeeder.php' => [
            '89990f9a0c9670e91b7f1556b1e66a355787d0dbd3fe19613698f72f4233dedb',
            'c38949f128422823a1c34bfe33443af70cdbab3d2bf1dbef646e4709c1c13ba2',
        ],
        'UserSeeder.php' => [
            '5e51d590cdfee34595ba56b5ee59764faa46ab5c740ba7cc52c76290b59a0e55',
        ],
        'ModelSeeder.php' => [
            '59e977b306606431359d668e726e0ef597e73b18fee98c7a32b10774862af63e',
        ],
    ];

    public function up(): void
    {
        $folder = database_path('seeders');

        if (! is_dir($folder)) {
            return;
        }

        $removed = [];

        foreach (self::SHIPPED as $name => $hashes) {
            $path = $folder.DIRECTORY_SEPARATOR.$name;

            try {
                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                $content = @file_get_contents($path);

                if (! is_string($content) || ! in_array(hash('sha256', str_replace("\r\n", "\n", $content)), $hashes, true)) {
                    continue;
                }

                if (@unlink($path)) {
                    $removed[] = 'database/seeders/'.$name;
                }
            } catch (Throwable) {
                // A file the web server may not delete stays; it does nothing unless someone runs it by hand.
            }
        }

        if ($removed === []) {
            return;
        }

        try {
            Log::info('AVDBCMS 1.2.2: removed development seeders that older releases shipped.', ['files' => $removed]);
        } catch (Throwable) {
            // Logging is a courtesy; the files are gone.
        }
    }

    public function down(): void
    {
        // Nothing to put back: these seeders only created accounts with known passwords.
    }
};
