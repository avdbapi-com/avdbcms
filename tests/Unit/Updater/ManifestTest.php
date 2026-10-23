<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Manifest;
use App\Support\Updater\UpdateException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * avdbcms-manifest.json (updater.md amendment 1, F8 5, 18): a valid list of the package's files, never a path the
 * updater must not write; requires_php and min_from refuse a package before anything on the site changes.
 */
class ManifestTest extends TestCase
{
    public function test_a_valid_manifest_is_read(): void
    {
        $json = UpdaterSandbox::manifest('1.0.1', ['app/A.php' => 'a', 'public/index.php' => 'i', 'bin/ffmpeg' => 'x', 'storage/app/.gitignore' => '*'], ['app/Old.php']);
        $manifest = Manifest::parse($json);

        $this->assertSame('1.0.1', $manifest->version);
        $this->assertSame('8.3.0', $manifest->requiresPhp);
        $this->assertSame('1.2.0', $manifest->minFrom);
        $this->assertSame(['app/A.php' => hash('sha256', 'a'), 'public/index.php' => hash('sha256', 'i')], $manifest->files);
        $this->assertSame(['app/Old.php'], $manifest->removed);
        $this->assertSame(hash('sha256', 'a'), $manifest->hash('app/A.php'));
        $this->assertNull($manifest->hash('app/B.php'));
    }

    #[DataProvider('brokenManifests')]
    public function test_a_broken_manifest_is_refused(string $json): void
    {
        try {
            Manifest::parse($json);
            $this->fail('The manifest was accepted.');
        } catch (UpdateException $e) {
            $this->assertSame('The update package has no valid list of its files. Nothing on your site was changed.', $e->getMessage());
            $this->assertFalse($e->retry);
        }
    }

    public static function brokenManifests(): array
    {
        $hash = hash('sha256', 'x');
        $base = ['product' => 'AVDBCMS', 'version' => '1.0.1', 'requires_php' => '8.3.0', 'min_from' => '1.0.0', 'files' => ['app/A.php' => $hash], 'removed' => []];
        $with = fn (array $change): array => [(string) json_encode(array_replace($base, $change))];
        $file = fn (string $path): array => $with(['files' => [$path => $hash]]);

        return [
            'not JSON' => ['{"product":'],
            'another product' => $with(['product' => 'JavCMS']),
            'version with v' => $with(['version' => 'v1.0.1']),
            'pre-release version' => $with(['version' => '1.0.1-beta']),
            'requires_php missing' => $with(['requires_php' => null]),
            'min_from not a version' => $with(['min_from' => 'any']),
            'no files' => $with(['files' => []]),
            'files as a list' => $with(['files' => [$hash]]),
            'hash not hex' => $with(['files' => ['app/A.php' => str_repeat('z', 64)]]),
            'hash with a line break' => $with(['files' => ['app/A.php' => $hash."\n"]]),
            'version with a line break' => $with(['version' => "1.0.1\n"]),
            'parent folder' => $file('app/../.env'),
            'absolute' => $file('/etc/passwd'),
            'backslash' => $file('app\\A.php'),
            'drive letter' => $file('C:/A.php'),
            'empty part' => $file('app//A.php'),
            '.env' => $file('.env'),
            '.env.local' => $file('config/.env.local'),
            '.user.ini' => $file('public/.user.ini'),
            'storage' => $file('storage/app/installed.json'),
            'bin' => $file('bin/linux-x64/ffmpeg'),
            'compiled cache' => $file('bootstrap/cache/config.php'),
            'uploads' => $file('public/uploads/x.jpg'),
            'installer SQL' => $file('database/install/example.sql'),
            // The same files where the file system ignores case (Windows, macOS).
            '.ENV' => $file('.ENV'),
            '.USER.INI' => $file('public/.USER.INI'),
            'compiled cache, Cache' => $file('bootstrap/Cache/config.php'),
            'Uploads' => $file('public/Uploads/x.jpg'),
            'Storage' => $file('Storage/app/installed.json'),
            'removed path outside' => $with(['removed' => ['../x']]),
        ];
    }

    public function test_requires_php_above_the_running_php_is_refused(): void
    {
        $manifest = Manifest::parse(UpdaterSandbox::manifest('1.0.1', ['app/A.php' => 'a'], [], '99.0.0'));

        try {
            $manifest->assertInstallable('1.0.0', '8.3.30');
            $this->fail('A package for a newer PHP was accepted.');
        } catch (UpdateException $e) {
            $this->assertSame('AVDBCMS 1.0.1 needs PHP 99.0.0 or newer, and this server runs PHP 8.3.30. Ask your host to switch the site to a newer PHP, then try again.', $e->getMessage());
            $this->assertFalse($e->retry);
        }
    }

    public function test_min_from_above_the_installed_version_is_refused(): void
    {
        $manifest = Manifest::parse(UpdaterSandbox::manifest('1.2.0', ['app/A.php' => 'a'], [], '8.3.0', '1.1.0'));

        try {
            $manifest->assertInstallable('1.0.0', '8.3.30');
            $this->fail('A package for a newer starting version was accepted.');
        } catch (UpdateException $e) {
            $this->assertSame('AVDBCMS 1.2.0 can only be installed over 1.1.0 or newer, and this site has 1.0.0. Install 1.1.0 first.', $e->getMessage());
        }

        $manifest->assertInstallable('1.1.0', '8.3.30');
        $manifest->assertInstallable('1.1.5', '8.3.0');
        $this->addToAssertionCount(2);
    }

    public function test_a_package_that_is_not_newer_is_refused(): void
    {
        $manifest = Manifest::parse(UpdaterSandbox::manifest('1.2.1', ['app/A.php' => 'a']));

        $this->expectExceptionMessage('This package is AVDBCMS 1.2.1, which is not newer than your 1.2.1.');
        $manifest->assertInstallable('1.2.1', '8.3.30');
    }

    public function test_the_installed_manifest_is_null_when_missing_or_broken(): void
    {
        $box = new UpdaterSandbox();

        try {
            $this->assertNull(Manifest::installed($box->root.'/'.Manifest::FILE));
            $box->write([Manifest::FILE => '{"broken"']);
            $this->assertNull(Manifest::installed($box->root.'/'.Manifest::FILE));
            $box->write([Manifest::FILE => UpdaterSandbox::manifest('1.0.0', ['app/A.php' => 'a'])]);
            $this->assertSame('1.0.0', Manifest::installed($box->root.'/'.Manifest::FILE)?->version);
        } finally {
            $box->delete();
        }
    }

    public function test_the_places_an_update_never_writes(): void
    {
        foreach (['.env', 'config/.env.backup', '.user.ini', 'storage/app/installed.json', 'bin/ffmpeg', 'bootstrap/cache/packages.php',
            'database/install/my-backup.sql', 'public/storage/x', 'public/uploads/a.jpg', 'public/thumbnails/a.jpg', 'public/avatars/a.png', 'storage', 'bin',
            '.ENV', '.Env.Local', '.USER.INI', 'bootstrap/Cache/config.php', 'bootstrap/cache/routes-v7.PHP', 'public/Uploads/x.jpg', 'STORAGE/app/x', 'Bin/ffmpeg'] as $path) {
            $this->assertTrue(Manifest::preserved($path), $path);
        }

        foreach (['.env.example', 'bootstrap/cache/.gitignore', 'app/Models/User.php', 'public/index.php', 'public/build/assets/app.js', 'database/migrations/x.php'] as $path) {
            $this->assertFalse(Manifest::preserved($path), $path);
        }
    }
}
