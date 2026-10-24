<?php

namespace Tests\Feature\Updater\Support;

use App\Support\DatabaseBackup;
use App\Support\Updater\UpdatePaths;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The one-click updater exactly as AVDBCMS 1.2.0 shipped it, for the tests of what passes between two versions.
 *
 * A 1.2.0 -> 1.2.1 update runs every step up to the swap in 1.2.0's code (the maintenance hook it writes, with 1.2.0's
 * SwapEngine and MaintenanceHook inside, included) and Finish in 1.2.1's; Undo goes the other way round: 1.2.1's steps
 * and hook, then 1.2.0's Finish. So 1.2.1's finish() must take the plan, the work folder and state.json that 1.2.0's
 * steps wrote, and 1.2.1's undo must leave what 1.2.0's finish can read. These tests run the real old code for its
 * part: the 16 files of app/Support/Updater of the 1.2.0 release (tests/Feature/Updater/Fixtures/updater-1.2.0, kept as
 * .txt so nothing loads them by accident), each checked against the SHA-256 the 1.2.0 release manifest lists, loaded
 * once per PHP process under the namespace NS (their own App\Support\Updater references rewritten, also the one in
 * HookWriter's check of the files it embeds). Everything outside that folder (DatabaseBackup, PendingMigrations,
 * AuditLogController, AppVersion, ...) is the current code, as on a real site during the update.
 */
final class Updater120
{
    public const NS = 'Tests\\Feature\\Updater\\Frozen\\V120';

    /** The SHA-256 of each file in avdbcms-manifest.json of the 1.2.0 release (avdbcms-1.2.0.zip, 23 Oct 2026). */
    private const SHA256 = [
        'UpdateException.php' => '3314545de45bcca671d971319924fd0c2851bb00609c291e67d22c3a4a1ceaf1',
        'Checksum.php' => '1a80e65eb737aa315283976749eeb8b00e80887f0eba30dae282bf4d62aecbe7',
        'Downloader.php' => '8fe57913cc4cb64cd83ea16e5d1809bc7ac4aded27aa933d897339743288c233',
        'HookTexts.php' => 'c5cc3d7bbc52214cb6d930b2ac30c975360520a651757f16e9f2e1236bd9f8da',
        'HookWriter.php' => 'ecf962d9b646e9c19b4e13c26210130194c2f02043f0afcb4f2815d658663691',
        'MaintenanceHook.php' => 'a4ae521c6f41e64e2bcd9f8ffa95bade6ddece6d6923ce9082d81ad52f8ea7c3',
        'Manifest.php' => 'baa964510b2ea939c659a3f938198ec3a920a74cad3f9557fff0ed81113fc950',
        'PlanStore.php' => '0496d65f6848d544aacd18c3f67eeab68044634006c8bfd88e8ef14940571f17',
        'ReleaseCheck.php' => '6b8b3d2093a9e3269daa614ae4ec98584e3ac43be88041d1549c014e7572ff1a',
        'SwapEngine.php' => 'c83d94bc92953ee7c9ef9d9cfd5b5eefccf9cb20848e884780fde4b2bd725d9b',
        'SwapPlanner.php' => '71bec61382a689449da1c18fd000235b35c2ca3a18eb9341bf47113e165e9324',
        'TranslationMerge.php' => 'f0d864a7e5db15bdbc36d84b0391036f67607b7b4e7df46ba7f62c2aea4ac526',
        'Unpacker.php' => '43fbc1290800c731f92d680550677ab0d4c128e8e9f27793e0cdc52400ffd704',
        'UpdatePaths.php' => '7b57cd27a9afbbdcc9b4ebfc569e07acee6723b02eb0786ea4ebeb41b4aef48e',
        'Updater.php' => 'f1162dbc23b82924bac2a495470c484b5d2d0ee6ebcf21fac74ea7498311f168',
        'ZipEntries.php' => 'ec9362d4b07e3d057051aa04752f60a41a6a8f06118a10054bcbc371524fc0f4',
    ];

    /**
     * 1.2.0's Updater on the site of $paths, playing $version (the version its code is), with the test's database
     * backup.
     */
    public static function updater(UpdatePaths $paths, DatabaseBackup $database, string $version): object
    {
        self::load();

        $updater = self::NS.'\\Updater';
        $sitePaths = self::NS.'\\UpdatePaths';
        $check = self::NS.'\\ReleaseCheck';
        $downloader = self::NS.'\\Downloader';

        return new $updater(new $sitePaths($paths->root, $paths->storage), new $check(), $database, new $downloader(), $version);
    }

    /** The class name of 1.2.0's $class (e.g. "SwapEngine"). */
    public static function name(string $class): string
    {
        self::load();

        return self::NS.'\\'.$class;
    }

    private static function load(): void
    {
        if (class_exists(self::NS.'\\Updater', false)) {
            return;
        }

        $source = dirname(__DIR__).'/Fixtures/updater-1.2.0';
        // A folder that stays while this PHP runs: HookWriter reads SwapEngine.php and MaintenanceHook.php next to
        // itself whenever it writes a hook, in every test.
        $folder = str_replace('\\', '/', storage_path('framework/testing/updater-1.2.0-'.substr(hash('sha256', implode(',', self::SHA256)), 0, 12)));
        File::ensureDirectoryExists($folder);
        $namespace = self::NS;

        foreach (self::SHA256 as $file => $hash) {
            $code = (string) @file_get_contents($source.'/'.$file.'.txt');

            if (! hash_equals($hash, hash('sha256', $code))) {
                throw new RuntimeException('tests/Feature/Updater/Fixtures/updater-1.2.0/'.$file.'.txt is not the file AVDBCMS 1.2.0 shipped.');
            }

            // strtr() takes the longer key first: HookWriter's "namespace App\\Support\\Updater;" in a double-quoted
            // string, then every plain App\Support\Updater.
            $code = strtr($code, [
                'App\\\\Support\\\\Updater' => str_replace('\\', '\\\\', $namespace),
                'App\\Support\\Updater' => $namespace,
            ]);

            if (! is_file($folder.'/'.$file) || (string) file_get_contents($folder.'/'.$file) !== $code) {
                file_put_contents($folder.'/'.$file, $code);
            }
        }

        foreach (array_keys(self::SHA256) as $file) {
            require_once $folder.'/'.$file;
        }
    }
}
