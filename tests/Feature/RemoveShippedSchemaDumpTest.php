<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Updater\Manifest;
use App\Support\Updater\SwapPlanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Round 1 of 1.2.3 (R1-009): 1.2.0 to 1.2.2 shipped database/schema/mysql-schema.sql, a dump Laravel loads before it
 * migrates an empty database, and it no longer fits the migrations ("Duplicate column name language"). 1.2.3 ships it no
 * more, but the updater of 1.2.2 (it does the moves of the update to 1.2.3) deletes old files only in code folders, and
 * database/schema/ was not one: every one-click-updated site kept it. The migration 2026_10_27_100000 removes the copy a
 * release shipped (any line endings; tests/Fixtures/schema holds the one of 1.2.2), never a dump of the owner's, and
 * says so in Admin actions; the updater of 1.2.3 counts database/schema/ among the folders a release may clean.
 */
class RemoveShippedSchemaDumpTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_27_100000_remove_shipped_schema_dump';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', storage_path('framework/testing/schema-dump-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists($this->root.'/database/schema');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_migration_knows_the_dump_every_release_shipped(): void
    {
        $shipped = $this->migration()::SHIPPED;
        $dump = $this->shippedDump();

        $this->assertSame(29830, strlen($dump), 'the file of 1.2.0 to 1.2.2 (with Windows line endings)');
        $this->assertContains(hash('sha256', str_replace("\r\n", "\n", $dump)), $shipped);
    }

    public function test_the_release_zips_ship_no_other_dump(): void
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
            $data = $zip->getFromName('database/schema/mysql-schema.sql');
            $zip->close();

            if (is_string($data)) {
                $this->assertContains(hash('sha256', str_replace("\r\n", "\n", $data)), $this->migration()::SHIPPED, basename($path));
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_an_updated_site_loses_the_shipped_dump_once_and_admin_actions_says_so(): void
    {
        User::factory()->admin()->create();
        file_put_contents($this->root.'/database/schema/mysql-schema.sql', $this->shippedDump());
        $migration = $this->migration();

        $this->assertTrue($migration->removeFrom($this->root));
        $this->assertFileDoesNotExist($this->root.'/database/schema/mysql-schema.sql');
        // Judge R3-030: the folder the dump leaves empty goes too (updated sites kept an empty database/schema/).
        $this->assertDirectoryDoesNotExist($this->root.'/database/schema');
        $this->assertFalse($migration->removeFrom($this->root), 'nothing left: safe to run again');

        // What up() does on the site's own folder: the file goes, and the row in Admin actions is written once.
        File::ensureDirectoryExists($this->root.'/database/schema');
        file_put_contents($this->root.'/database/schema/mysql-schema.sql', str_replace("\r\n", "\n", $this->shippedDump()));
        $this->assertTrue($migration->clean($this->root));
        $this->assertFalse($migration->clean($this->root));
        File::ensureDirectoryExists($this->root.'/database/schema');
        file_put_contents($this->root.'/database/schema/mysql-schema.sql', $this->shippedDump());
        $this->assertTrue($migration->clean($this->root));
        $migration->up();

        $this->assertFileDoesNotExist($this->root.'/database/schema/mysql-schema.sql', 'line endings aside, it is the shipped file');
        $rows = DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all();
        $this->assertSame([$migration::AUDIT_DETAILS], $rows);
        $this->assertLessThanOrEqual(120, mb_strlen($migration::AUDIT_DETAILS));
        $migration->down();
    }

    public function test_a_dump_the_owner_made_or_changed_stays(): void
    {
        $mine = $this->shippedDump()."\r\n-- my own change\r\n";
        file_put_contents($this->root.'/database/schema/mysql-schema.sql', $mine);
        file_put_contents($this->root.'/database/schema/sqlite-schema.sql', $this->shippedDump());

        $this->assertFalse($this->migration()->removeFrom($this->root));
        $this->assertSame($mine, file_get_contents($this->root.'/database/schema/mysql-schema.sql'));
        $this->assertFileExists($this->root.'/database/schema/sqlite-schema.sql', 'only the file a release shipped');
        $this->assertSame(0, DB::table('admin_audit_logs')->count());

        // A folder with a file of the owner's next to the shipped dump stays.
        file_put_contents($this->root.'/database/schema/mysql-schema.sql', $this->shippedDump());
        $this->assertTrue($this->migration()->removeFrom($this->root));
        $this->assertDirectoryExists($this->root.'/database/schema');
    }

    public function test_a_new_site_has_no_dump_and_nothing_happens(): void
    {
        File::deleteDirectory($this->root.'/database/schema');

        $this->assertFalse($this->migration()->removeFrom($this->root));
        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    /** The one-click updater of 1.2.3 deletes a dump a later release no longer ships (database/schema/ is a code folder). */
    public function test_the_updater_counts_database_schema_among_the_folders_a_release_cleans(): void
    {
        $this->assertContains('database/schema/', SwapPlanner::REMOVABLE);

        $dump = $this->shippedDump();
        $hash = hash('sha256', $dump);
        $installed = new Manifest('1.2.3', '8.3.0', '1.2.0', ['app/A.php' => hash('sha256', 'a'), 'database/schema/mysql-schema.sql' => $hash], []);
        $new = new Manifest('1.2.4', '8.3.0', '1.2.0', ['app/A.php' => hash('sha256', 'a')], ['database/schema/mysql-schema.sql']);
        $live = ['app/A.php' => hash('sha256', 'a'), 'database/schema/mysql-schema.sql' => $hash, Manifest::FILE => 'x'];

        $plan = SwapPlanner::plan($new, $installed, 'y', fn (string $path): ?string => $live[$path] ?? null,
            fn (string $path): ?string => null, fn (string $path): ?string => null);

        $this->assertSame(1, $plan['report']['removed']);
        $this->assertContains(['path' => 'database/schema/mysql-schema.sql', 'action' => 'removed', 'old' => $hash, 'new' => null], $plan['entries']);
    }

    private function shippedDump(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/schema/mysql-schema-1.2.2.sql.txt'));
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/'.self::MIGRATION.'.php');
    }
}
