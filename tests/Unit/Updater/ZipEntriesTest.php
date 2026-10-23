<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\UpdateException;
use App\Support\Updater\ZipEntries;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\ZipBuilder;
use Tests\TestCase;

/**
 * The zip entry guard (F8 8, 18): one unsafe entry refuses the whole zip before anything is unpacked; the storage
 * placeholders and database/install/ are skipped, not refused.
 */
class ZipEntriesTest extends TestCase
{
    private UpdaterSandbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = new UpdaterSandbox();
    }

    protected function tearDown(): void
    {
        $this->box->delete();
        parent::tearDown();
    }

    public function test_a_release_zip_is_accepted_and_its_placeholders_skipped(): void
    {
        $zip = $this->base()
            ->dir('storage/app/public')
            ->file('storage/app/.gitignore', "*\n")
            ->file('storage/framework/cache/.gitkeep', '')
            ->file('database/install/example.sql', '-- sql')
            ->file('.env.example', 'APP_NAME=AVDBCMS')
            ->file('bootstrap/cache/.gitignore', "*\n")
            ->save($this->box->path('ok.zip'));

        $entries = ZipEntries::read($zip);
        $guard = ZipEntries::guard($entries);

        $this->assertSame(['app/Models/User.php', 'public/index.php', 'artisan', '.htaccess', 'avdbcms-manifest.json', '.env.example', 'bootstrap/cache/.gitignore'], $guard['files']);
        $this->assertSame(['app/', 'storage/app/public/', 'storage/app/.gitignore', 'storage/framework/cache/.gitkeep', 'database/install/example.sql'], $guard['skipped']);
        $this->assertSame(array_sum(array_map('strlen', ['<?php // user', '<?php // index', 'artisan', 'deny', '{}', 'APP_NAME=AVDBCMS', "*\n"])), $guard['size']);

        $user = $entries[1];
        $this->assertSame('app/Models/User.php', $user['name']);
        $this->assertFalse($user['dir']);
        $this->assertFalse($user['link']);
        $this->assertSame(8, $user['method']);
        $this->assertSame(strlen('<?php // user'), $user['size']);
        $this->assertTrue($entries[0]['dir']);
    }

    #[DataProvider('unsafeEntries')]
    public function test_one_unsafe_entry_refuses_the_whole_zip(callable $add, string $reason): void
    {
        $builder = $this->base();
        $add($builder);
        $zip = $builder->save($this->box->path('bad.zip'));

        try {
            ZipEntries::guard(ZipEntries::read($zip));
            $this->fail('The zip was accepted.');
        } catch (UpdateException $e) {
            $this->assertStringStartsWith('The update package is not safe to unpack (', $e->getMessage());
            $this->assertStringEndsWith('). Nothing on your site was changed.', $e->getMessage());
            $this->assertSame('refused zip entry: '.$reason, $e->detail);
            $this->assertFalse($e->retry);
            $this->assertSame('verify', $e->step);
        }
    }

    public static function unsafeEntries(): array
    {
        $file = fn (string $name): callable => fn (ZipBuilder $zip) => $zip->file($name, 'x');

        return [
            'absolute path' => [$file('/etc/cron.d/x'), 'an absolute path'],
            'parent folder' => [$file('../outside.php'), 'an empty, "." or ".." folder name'],
            'parent folder inside' => [$file('app/../../outside.php'), 'an empty, "." or ".." folder name'],
            'backslash' => [$file('app\\Models\\Evil.php'), 'a backslash'],
            'drive letter' => [$file('C:/Windows/evil.php'), 'a drive letter'],
            'NUL byte' => [$file("app/evil.php\0.txt"), 'a control character'],
            'line break' => [$file("app/evil\n.php"), 'a control character'],
            'symlink' => [fn (ZipBuilder $zip) => $zip->symlink('app/link', '/etc/passwd'), 'a symbolic link'],
            'unknown top folder' => [$file('scripts/deploy.sh'), 'a folder a release does not have'],
            'bin' => [$file('bin/linux-x64/ffmpeg'), 'a folder a release does not have'],
            '.env' => [$file('.env'), 'a site settings file'],
            '.env deeper' => [$file('config/.env'), 'a site settings file'],
            '.env.production' => [$file('.env.production'), 'a site settings file'],
            '.user.ini' => [$file('.user.ini'), 'a site settings file'],
            'public .user.ini' => [$file('public/.user.ini'), 'a site settings file'],
            'a real file in storage' => [$file('storage/app/installed.json'), 'a file inside storage/'],
            'a log in storage' => [$file('storage/logs/laravel.log'), 'a file inside storage/'],
            'compiled config' => [$file('bootstrap/cache/config.php'), 'a compiled cache file'],
            // Where the file system ignores case (Windows, macOS), these are the same files as the ones above.
            '.ENV in capitals' => [$file('.ENV'), 'a site settings file'],
            '.User.ini' => [$file('public/.USER.INI'), 'a site settings file'],
            'compiled config, Cache' => [$file('bootstrap/Cache/config.php'), 'a compiled cache file'],
            'compiled config, .PHP' => [$file('bootstrap/cache/services.PHP'), 'a compiled cache file'],
            'odd top file' => [$file('~backup.php'), 'an unexpected file at the top'],
            'encrypted' => [fn (ZipBuilder $zip) => $zip->raw('app/Secret.php', 'x', ['method' => 0, 'flags' => 1]), 'an encrypted entry'],
            'bzip2' => [fn (ZipBuilder $zip) => $zip->raw('app/Packed.php', 'x', ['method' => 12]), 'an unsupported compression'],
            'twice' => [$file('app/models/user.php'), 'a name that is in the zip twice'],
        ];
    }

    public function test_a_damaged_zip_cannot_be_read(): void
    {
        $zip = $this->base()->save($this->box->path('cut.zip'));
        $bytes = (string) file_get_contents($zip);

        foreach (['not a zip' => 'PK no zip at all, just some text', 'cut short' => substr($bytes, 0, (int) (strlen($bytes) / 2)), 'empty' => ''] as $label => $content) {
            file_put_contents($zip, $content);

            try {
                ZipEntries::read($zip);
                $this->fail($label.': read');
            } catch (UpdateException $e) {
                $this->assertSame('The update package is damaged: its list of files cannot be read. Nothing on your site was changed. Try again.', $e->getMessage(), $label);
                $this->assertTrue($e->retry, $label);
            }
        }
    }

    private function base(): ZipBuilder
    {
        return (new ZipBuilder())
            ->dir('app')
            ->file('app/Models/User.php', '<?php // user')
            ->file('public/index.php', '<?php // index')
            ->file('artisan', 'artisan', false)
            ->file('.htaccess', 'deny')
            ->file('avdbcms-manifest.json', '{}');
    }
}
