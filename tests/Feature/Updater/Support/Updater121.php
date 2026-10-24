<?php

namespace Tests\Feature\Updater\Support;

use App\Support\DatabaseBackup;
use App\Support\Updater\UpdatePaths;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The one-click updater exactly as AVDBCMS 1.2.1 shipped it (the version the owner's sites run when they update to
 * 1.2.2), for the tests of what passes between two versions; see Updater120 for how it works. A 1.2.1 -> 1.2.2 update
 * runs every step up to the swap in 1.2.1's code (its maintenance hook with 1.2.1's SwapEngine and MaintenanceHook
 * inside) and Finish in 1.2.2's; an Undo from 1.2.2 back to 1.2.1 runs 1.2.2's steps and hook, then 1.2.1's Finish.
 * The 17 files of app/Support/Updater of the 1.2.1 release (tests/Feature/Updater/Fixtures/updater-1.2.1, as .txt),
 * each checked against the SHA-256 the 1.2.1 release manifest lists, loaded under the namespace NS.
 */
final class Updater121
{
    public const NS = 'Tests\\Feature\\Updater\\Frozen\\V121';

    /** The SHA-256 of each file in avdbcms-manifest.json of the 1.2.1 release (avdbcms-1.2.1.zip, 24 Oct 2026). */
    private const SHA256 = [
        'UpdateException.php' => '3314545de45bcca671d971319924fd0c2851bb00609c291e67d22c3a4a1ceaf1',
        'Checksum.php' => '1a80e65eb737aa315283976749eeb8b00e80887f0eba30dae282bf4d62aecbe7',
        'Downloader.php' => 'fea020553b91d80683dc64fdb324b6a4204e036e508e6643a286e840719fbfb3',
        'HookTexts.php' => 'c5cc3d7bbc52214cb6d930b2ac30c975360520a651757f16e9f2e1236bd9f8da',
        'HookWriter.php' => 'ecf962d9b646e9c19b4e13c26210130194c2f02043f0afcb4f2815d658663691',
        'MaintenanceHook.php' => '218fa58d3d70fa791962b31800ac85093490ed6338ceca017c6e5c3a819840b2',
        'Manifest.php' => 'baa964510b2ea939c659a3f938198ec3a920a74cad3f9557fff0ed81113fc950',
        'PlanStore.php' => '0496d65f6848d544aacd18c3f67eeab68044634006c8bfd88e8ef14940571f17',
        'ReleaseCheck.php' => '6b8b3d2093a9e3269daa614ae4ec98584e3ac43be88041d1549c014e7572ff1a',
        'SwapEngine.php' => '31e87598c4d3af3c1905caaf82fc54a23e0af3f7d2398594ffcb306c766ffa05',
        'SwapPlanner.php' => '71bec61382a689449da1c18fd000235b35c2ca3a18eb9341bf47113e165e9324',
        'TranslationMerge.php' => 'f0d864a7e5db15bdbc36d84b0391036f67607b7b4e7df46ba7f62c2aea4ac526',
        'Trash.php' => '0cfbd184f84adc478320163da8cc4c26b94405ca1642b034b7f239de6886988e',
        'Unpacker.php' => '43fbc1290800c731f92d680550677ab0d4c128e8e9f27793e0cdc52400ffd704',
        'UpdatePaths.php' => '4783268d221a497e5a9bc9f85adba137f1fe2bd4b90bf30a9b064721cb07bfef',
        'Updater.php' => 'ccecf89f4b6a00b9a32e498a2d1bba29355a8965dd6a13de9b06b171f8ceb83c',
        'ZipEntries.php' => 'ec9362d4b07e3d057051aa04752f60a41a6a8f06118a10054bcbc371524fc0f4',
    ];

    /** 1.2.1's Updater on the site of $paths, playing $version (the version its code is), with the test's database backup. */
    public static function updater(UpdatePaths $paths, DatabaseBackup $database, string $version): object
    {
        self::load();

        $updater = self::NS.'\\Updater';
        $sitePaths = self::NS.'\\UpdatePaths';
        $check = self::NS.'\\ReleaseCheck';
        $downloader = self::NS.'\\Downloader';

        return new $updater(new $sitePaths($paths->root, $paths->storage), new $check(), $database, new $downloader(), $version);
    }

    /** The class name of 1.2.1's $class (e.g. "SwapEngine"). */
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

        $source = dirname(__DIR__).'/Fixtures/updater-1.2.1';
        // A folder that stays while this PHP runs: HookWriter reads SwapEngine.php and MaintenanceHook.php next to
        // itself whenever it writes a hook, in every test.
        $folder = str_replace('\\', '/', storage_path('framework/testing/updater-1.2.1-'.substr(hash('sha256', implode(',', self::SHA256)), 0, 12)));
        File::ensureDirectoryExists($folder);
        $namespace = self::NS;

        foreach (self::SHA256 as $file => $hash) {
            $code = (string) @file_get_contents($source.'/'.$file.'.txt');

            if (! hash_equals($hash, hash('sha256', $code))) {
                throw new RuntimeException('tests/Feature/Updater/Fixtures/updater-1.2.1/'.$file.'.txt is not the file AVDBCMS 1.2.1 shipped.');
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
