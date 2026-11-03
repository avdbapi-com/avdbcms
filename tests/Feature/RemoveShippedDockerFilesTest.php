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
 * Round 1b R1B-009: 1.2.0 to 1.2.2 shipped docker-compose.yml and docker/, the upstream project's development stack (it
 * cannot start: "pull access denied for minio/minio"; no document of the package uses it, containers go through 1Panel).
 * 1.2.3 ships it no more (the release build leaves it out), and because the updater of the installed release deletes old
 * files only in code folders, the migration 2026_10_27_140000 removes the copies a release shipped (any line endings;
 * tests/Fixtures/docker-1.2.2 holds those of 1.2.2), never a file the owner made or changed, and says so in Admin actions.
 */
class RemoveShippedDockerFilesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_27_140000_remove_shipped_docker_files';

    private const FILES = ['docker-compose.yml', 'docker/Dockerfile', 'docker/nginx.conf', 'docker/php.ini', 'docker/policy.json'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', storage_path('framework/testing/docker-files-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists($this->root.'/docker');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_migration_knows_the_files_of_1_2_2(): void
    {
        foreach (self::FILES as $file) {
            $this->assertContains(hash('sha256', str_replace("\r\n", "\n", $this->shipped($file))), $this->migration()::SHIPPED[$file], $file);
        }
    }

    public function test_the_release_zips_ship_no_other_version(): void
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

            foreach (self::FILES as $file) {
                $data = $zip->getFromName($file);

                if (is_string($data)) {
                    $this->assertContains(hash('sha256', str_replace("\r\n", "\n", $data)), $this->migration()::SHIPPED[$file], basename($path).': '.$file);
                    $checked++;
                }
            }

            $zip->close();
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_an_updated_site_loses_the_shipped_files_once_and_admin_actions_says_so(): void
    {
        User::factory()->admin()->create();
        $this->plant();
        $migration = $this->migration();

        $this->assertSame(self::FILES, $migration->removeFrom($this->root));
        foreach (self::FILES as $file) {
            $this->assertFileDoesNotExist($this->root.'/'.$file);
        }
        $this->assertDirectoryDoesNotExist($this->root.'/docker', 'the empty folder goes too');
        $this->assertSame([], $migration->removeFrom($this->root), 'nothing left: safe to run again');

        // What up() does on the site's folder: the files go (Windows line endings too) and Admin actions says so. J2-P1:
        // a row each time they are actually removed. Gone already writes nothing; but after "Undo this update" put them
        // back and the update is run again, removing them again writes its own row, as the unused-pictures migration does
        // (the Docker files and the schema dump used to stay silent on that second removal).
        $this->plant(true);
        $this->assertTrue($migration->clean($this->root), 'first removal: a row');
        $this->assertFalse($migration->clean($this->root), 'nothing left: no row');
        $this->plant();
        $this->assertTrue($migration->clean($this->root), 'put back by an undo and removed again: another row (J2-P1)');
        $migration->up();

        $rows = DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all();
        $this->assertSame([$migration::AUDIT_DETAILS, $migration::AUDIT_DETAILS], $rows, 'one row per actual removal (J2-P1)');
        $this->assertLessThanOrEqual(120, mb_strlen($migration::AUDIT_DETAILS));
        $migration->down();
    }

    public function test_a_file_the_owner_made_or_changed_stays_and_so_does_its_folder(): void
    {
        $this->plant();
        $mine = $this->shipped('docker-compose.yml')."\n  # my own service\n";
        file_put_contents($this->root.'/docker-compose.yml', $mine);
        file_put_contents($this->root.'/docker/my-entrypoint.sh', "#!/bin/sh\n");

        $removed = $this->migration()->removeFrom($this->root);

        $this->assertSame(['docker/Dockerfile', 'docker/nginx.conf', 'docker/php.ini', 'docker/policy.json'], $removed);
        $this->assertSame($mine, file_get_contents($this->root.'/docker-compose.yml'));
        $this->assertFileExists($this->root.'/docker/my-entrypoint.sh');
        $this->assertDirectoryExists($this->root.'/docker');
    }

    /**
     * Admin actions says what went and counts the shipped files the owner changed, which stay with docker/ (review
     * UP-RV-03: the row always said "(docker-compose.yml, docker/)" removed).
     */
    public function test_admin_actions_counts_the_files_the_owner_changed_which_stay(): void
    {
        User::factory()->admin()->create();
        $this->plant();
        file_put_contents($this->root.'/docker/php.ini', "; my own php.ini\nmemory_limit = 1G\n");
        $migration = $this->migration();

        $this->assertTrue($migration->clean($this->root));

        $this->assertSame("; my own php.ini\nmemory_limit = 1G\n", file_get_contents($this->root.'/docker/php.ini'));
        $this->assertSame(['php.ini'], array_values(array_diff(scandir($this->root.'/docker') ?: [], ['.', '..'])), 'docker/ keeps the changed file');
        $this->assertSame(['docker/php.ini'], $migration->changedIn($this->root));
        $this->assertSame(['1.2.3: removed 4 development Docker files of the upstream project (docker-compose.yml, docker/); 1 you changed was kept'],
            DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all());

        // The places named are those the files went from; every file went and none was changed: the line RC1/RC2 wrote.
        $this->assertSame($migration::AUDIT_DETAILS, $migration::details(self::FILES, 0));
        $this->assertSame('1.2.3: removed the shipped development Docker files of the upstream project (docker-compose.yml)', $migration::details(['docker-compose.yml'], 0));
        $this->assertSame('1.2.3: removed the shipped development Docker files of the upstream project (docker/)', $migration::details(['docker/Dockerfile', 'docker/php.ini'], 0));
        $this->assertSame('1.2.3: removed 1 development Docker file of the upstream project (docker/); 2 you changed were kept', $migration::details(['docker/policy.json'], 2));

        foreach ([$migration::details(self::FILES, 0), $migration::details(array_slice(self::FILES, 0, 4), 1), $migration::details(['docker-compose.yml'], 4)] as $details) {
            $this->assertLessThanOrEqual(120, mb_strlen($details), $details);
        }
    }

    public function test_a_new_site_has_none_and_nothing_happens(): void
    {
        File::deleteDirectory($this->root.'/docker');

        $this->assertFalse($this->migration()->clean($this->root));
        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    private function plant(bool $windowsLineEndings = false): void
    {
        File::ensureDirectoryExists($this->root.'/docker');

        foreach (self::FILES as $file) {
            $content = str_replace("\r\n", "\n", $this->shipped($file));
            file_put_contents($this->root.'/'.$file, $windowsLineEndings ? str_replace("\n", "\r\n", $content) : $content);
        }
    }

    private function shipped(string $file): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/docker-1.2.2/'.basename($file).'.txt'));
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/'.self::MIGRATION.'.php');
    }
}
