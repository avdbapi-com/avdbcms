<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Judge R3-024: 1.2.0 to 1.2.2 shipped 20 pictures of the old sign-in and error pages that 1.2.3 no longer has (R1-138:
 * the packages leave them out, the update manifest lists them as removed), but the updater of the installed release
 * deletes old files only in code folders, so every updated site kept them (about 9 MB served to anybody). The migration
 * 2026_10_29_100000 removes each picture only while it has exactly the bytes a release shipped (a picture the owner
 * replaced stays), then the folders that are empty then, and Tools > Logs > Admin actions says how many went and how
 * many the owner had changed. In the Finish of a one-click update they go into the update backup (Updater tests:
 * UpdateFinishFilesUndoTest); this test runs the migration on a folder of its own.
 */
class RemoveUnusedPicturesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_29_100000_remove_unused_sign_in_and_error_pictures';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', storage_path('framework/testing/pictures-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_migration_names_exactly_the_pictures_the_update_manifest_removes(): void
    {
        $shipped = $this->migration()::SHIPPED;

        $this->assertCount(20, $shipped);

        foreach (['', 'public/'] as $prefix) {
            foreach (['errors/403.jpg', 'errors/404.jpg', 'errors/410.jpg', 'errors/500.jpg', 'errors/503.jpg', 'pages/forgot.png', 'pages/login.png', 'pages/register.png', 'pages/reset.jpg', 'pages/subscriptions.png'] as $picture) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $shipped[$prefix.'images/'.$picture] ?? '', $prefix.'images/'.$picture);
            }
        }

        $this->assertArrayNotHasKey('images/pages/contact.png', $shipped, 'the contact page still shows it');
        $this->assertArrayNotHasKey('public/images/pages/contact.png', $shipped);

        // None of them is in the tree any more (the packages do not ship them, R1-138).
        foreach (array_keys($shipped) as $path) {
            $this->assertFileDoesNotExist(base_path($path), $path);
        }
    }

    public function test_the_release_zips_ship_these_bytes(): void
    {
        // Optional cross-check with real release zips: AVD_RELEASE_ZIPS="path/a.zip;path/b.zip".
        $zips = array_filter(explode(';', (string) getenv('AVD_RELEASE_ZIPS')), 'is_file');

        if ($zips === [] || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Set AVD_RELEASE_ZIPS to release zips to compare with.');
        }

        $checked = 0;

        foreach ($zips as $path) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true, $path);

            foreach ($this->migration()::SHIPPED as $picture => $hash) {
                $data = $zip->getFromName($picture);

                if (is_string($data)) {
                    $this->assertSame($hash, hash('sha256', $data), basename($path).': '.$picture);
                    $checked++;
                }
            }

            $zip->close();
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_an_updated_site_loses_the_shipped_pictures_and_the_emptied_folders_and_admin_actions_counts_them(): void
    {
        User::factory()->admin()->create();
        $pictures = $this->plant();
        File::put($this->root.'/images/pages/contact.png', 'contact');
        $migration = $this->migration();

        $this->assertTrue($migration->clean($this->root, $pictures));

        foreach (array_keys($pictures) as $path) {
            $this->assertFileDoesNotExist($this->root.'/'.$path);
        }

        $this->assertDirectoryDoesNotExist($this->root.'/images/errors', 'emptied: it goes');
        $this->assertDirectoryDoesNotExist($this->root.'/public/images/errors');
        $this->assertDirectoryExists($this->root.'/images/pages', 'contact.png is still there');
        $this->assertDirectoryDoesNotExist($this->root.'/public/images/pages', 'emptied too');
        $this->assertFileExists($this->root.'/images/pages/contact.png');

        // Safe to run again: nothing left, no second row.
        $this->assertFalse($migration->clean($this->root, $pictures));
        $this->assertSame(['1.2.3: removed 20 unused pictures of the sign-in and error pages (images/, public/images/)'],
            DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all());
        $migration->down();
    }

    public function test_a_picture_the_owner_replaced_stays_with_its_folder_and_is_counted(): void
    {
        User::factory()->admin()->create();
        $pictures = $this->plant();
        File::put($this->root.'/public/images/errors/404.jpg', 'my own 404 picture');
        File::put($this->root.'/images/errors/my-own.jpg', 'a picture of mine');

        $result = $this->migration()->removeFrom($this->root, $pictures);

        $this->assertCount(19, $result['removed']);
        $this->assertSame(['public/images/errors/404.jpg'], $result['changed']);
        $this->assertSame('my own 404 picture', file_get_contents($this->root.'/public/images/errors/404.jpg'));
        $this->assertFileExists($this->root.'/images/errors/my-own.jpg', 'a file of the owner stays');
        $this->assertDirectoryExists($this->root.'/images/errors');

        $this->assertSame('1.2.3: removed 19 unused pictures of the sign-in and error pages (images/, public/images/); 1 you changed was kept',
            $this->migration()::details(19, 1));
        $this->assertSame('1.2.3: removed 1 unused picture of the sign-in and error pages (images/, public/images/); 2 you changed were kept',
            $this->migration()::details(1, 2));
        $this->assertLessThanOrEqual(120, mb_strlen($this->migration()::details(19, 1)), 'the admin lists show 120 characters');
    }

    public function test_a_new_site_has_none_and_nothing_happens_and_the_test_suite_never_touches_the_project(): void
    {
        $this->assertFalse($this->migration()->clean($this->root));
        $this->assertSame(0, DB::table('admin_audit_logs')->count());

        // up() in the test process (RefreshDatabase migrates in the project folder) leaves the project alone: an empty
        // folder of the working tree stays where it is.
        $folder = base_path('public/images/errors');
        $empty = is_dir($folder) && array_diff(scandir($folder) ?: ['x'], ['.', '..']) === [];
        $this->migration()->up();

        if ($empty) {
            $this->assertDirectoryExists($folder);
        }

        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    /**
     * The 20 pictures with stand-in bytes (their SHA-256 in place of the real ones).
     *
     * @return array<string, string> path => SHA-256
     */
    private function plant(): array
    {
        $pictures = [];

        foreach (array_keys($this->migration()::SHIPPED) as $path) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$path));
            File::put($this->root.'/'.$path, 'picture of 1.2.2: '.$path);
            $pictures[$path] = hash('sha256', 'picture of 1.2.2: '.$path);
        }

        return $pictures;
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/'.self::MIGRATION.'.php');
    }
}
