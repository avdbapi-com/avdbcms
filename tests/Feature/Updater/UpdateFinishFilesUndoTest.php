<?php

namespace Tests\Feature\Updater;

use App\Models\User;
use App\Support\AppVersion;
use App\Support\PendingMigrations;
use App\Support\Updater\FinishFiles;
use App\Support\Updater\InstallData;
use App\Support\Updater\Manifest;
use App\Support\Updater\PlanStore;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Updater\Support\FakeDatabaseBackup;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\Updater122;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\Feature\Updater\Support\ZipBuilder;
use Tests\TestCase;

/**
 * What the Finish of the update to 1.2.3 removes or replaces outside the swap, and "Undo this update" (judges R3-029,
 * R3-024, R3-033). 1.2.2's updater runs the steps up to the swap (Updater122: the files it shipped) and leaves alone what
 * 1.2.2 shipped outside its code folders; 1.2.3's Finish then removes the old database dump, the development Docker files
 * and the 20 unused sign-in and error pictures (their migrations) and gives database/install/example.sql the new copy.
 * Up to RC2 those changes happened outside the update's file backup, so Undo left the tree different from 1.2.2's (the
 * dump and the Docker files gone, the new example.sql). Now each goes into the backup first (App\Support\Updater\
 * FinishFiles), and Undo brings the tree back byte for byte, also when Home's "Finish update" ran the database step and
 * after the tidy-up moved the old version's built assets into the backup 14 days after the update.
 */
class UpdateFinishFilesUndoTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    /** The 20 pictures, with stand-in bytes for the test (the migration knows the real ones by their SHA-256). */
    private const PICTURES = [
        'images/errors/403.jpg', 'images/errors/404.jpg', 'images/errors/410.jpg', 'images/errors/500.jpg', 'images/errors/503.jpg',
        'images/pages/forgot.png', 'images/pages/login.png', 'images/pages/register.png', 'images/pages/reset.jpg', 'images/pages/subscriptions.png',
        'public/images/errors/403.jpg', 'public/images/errors/404.jpg', 'public/images/errors/410.jpg', 'public/images/errors/500.jpg', 'public/images/errors/503.jpg',
        'public/images/pages/forgot.png', 'public/images/pages/login.png', 'public/images/pages/register.png', 'public/images/pages/reset.jpg', 'public/images/pages/subscriptions.png',
    ];

    private const DOCKER = ['docker-compose.yml', 'docker/Dockerfile', 'docker/nginx.conf', 'docker/php.ini', 'docker/policy.json'];

    private const DUMP = 'database/schema/mysql-schema.sql';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeFinishSite();
        InstallData::$shippedHashes = [InstallData::hash(self::exampleSql('1.2.2'))];
    }

    protected function tearDown(): void
    {
        InstallData::$shippedHashes = null;
        $this->removeSite();

        parent::tearDown();
    }

    public function test_undo_brings_back_byte_for_byte_what_finish_removed_or_replaced(): void
    {
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->updateWith($old);

        // Finish removed the leftovers of 1.2.2 and the empty folders they leave, and replaced the starter data.
        foreach ([self::DUMP, ...self::DOCKER, ...self::PICTURES] as $path) {
            $this->assertFileDoesNotExist($this->box->root.'/'.$path, $path);
        }

        foreach (['docker', 'images/errors', 'public/images/errors', 'database/schema'] as $folder) {
            $this->assertDirectoryDoesNotExist($this->box->root.'/'.$folder, $folder.' went with its last file');
        }

        $this->assertSame('contact', $this->box->read('images/pages/contact.png'), 'a picture the release still has stays');
        $this->assertSame(self::exampleSql('next'), $this->box->read(InstallData::PATH));

        // ... into the update's file backup, each with its line in backup.json.
        $state = PlanStore::read($this->box->paths()->state());
        $folder = $this->box->paths()->backups((string) $state['backup_id']);
        $entries = $this->entries($folder);

        foreach ([self::DUMP, ...self::DOCKER, ...self::PICTURES] as $path) {
            $this->assertArrayHasKey($path, $entries);
            $this->assertSame(['removed', hash('sha256', (string) $this->original($path)), null], [$entries[$path]['action'], $entries[$path]['old'], $entries[$path]['new']], $path);
            $this->assertSame($this->original($path), (string) @file_get_contents($folder.'/files/'.$path), $path.' kept in the backup');
        }

        $this->assertSame(['replaced', hash('sha256', self::exampleSql('1.2.2')), hash('sha256', self::exampleSql('next'))],
            [$entries[InstallData::PATH]['action'], $entries[InstallData::PATH]['old'], $entries[InstallData::PATH]['new']]);
        $this->assertSame(self::exampleSql('1.2.2'), file_get_contents($folder.'/files/'.InstallData::PATH));

        // Tools > Logs > Admin actions says how many pictures went.
        $this->assertContains('1.2.3: removed 20 unused pictures of the sign-in and error pages (images/, public/images/)',
            DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all());

        // Undo (this version's steps and hook, then 1.2.2 finishes it): the tree of 1.2.2 again, byte for byte.
        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot(), 'every file as before the update');
    }

    public function test_a_picture_the_owner_changed_stays_and_admin_actions_says_so(): void
    {
        $this->box->write(['public/images/pages/login.png' => 'my own sign-in picture']);
        $this->before = $this->box->snapshot();
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);

        $this->updateWith($old);

        $this->assertSame('my own sign-in picture', $this->box->read('public/images/pages/login.png'));
        $this->assertFileDoesNotExist($this->box->root.'/images/pages/login.png');
        $this->assertContains('1.2.3: removed 19 unused pictures of the sign-in and error pages (images/, public/images/); 1 you changed was kept',
            DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all());

        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot());
    }

    /** A Docker file the owner changed stays, and docker/ with it; Admin actions counts it (review UP-RV-03). */
    public function test_a_docker_file_the_owner_changed_stays_with_its_folder_and_admin_actions_counts_it(): void
    {
        $this->box->write(['docker/php.ini' => "; my own php.ini\nmemory_limit = 1G\n"]);
        $this->before = $this->box->snapshot();
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);

        $this->updateWith($old);

        $this->assertSame("; my own php.ini\nmemory_limit = 1G\n", $this->box->read('docker/php.ini'));
        $this->assertSame(['php.ini'], array_values(array_diff(scandir($this->box->root.'/docker') ?: [], ['.', '..'])), 'docker/ keeps only the changed file');
        $this->assertFileDoesNotExist($this->box->root.'/docker-compose.yml');
        $rows = DB::table('admin_audit_logs')->where('action', 'update.removed_files')->pluck('details')->all();
        $this->assertContains('1.2.3: removed 4 development Docker files of the upstream project (docker-compose.yml, docker/); 1 you changed was kept', $rows);
        $this->assertNotContains('1.2.3: removed the shipped development Docker files of the upstream project (docker-compose.yml, docker/)', $rows);

        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot());
    }

    public function test_home_finish_update_running_the_database_step_keeps_undo_exact_too(): void
    {
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $this->addFinishMigration();

        // Home's Finish update runs the database step while the one-click update waits for its Finish.
        $this->assertTrue(PendingMigrations::run()['ok']);
        $this->assertFileDoesNotExist($this->box->root.'/docker-compose.yml');

        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot());
    }

    /**
     * Update, Undo, update again: the migrations ran with the first update and never run twice, so every one-click Finish
     * removes the leftovers again (Updater::removeLeftovers(), the migrations' own rules; the dump and the Docker files
     * here, the real ones of 1.2.2), into the new update's backup: the second Undo is exact too.
     */
    public function test_an_update_made_again_after_undo_removes_the_leftovers_again_and_its_undo_is_exact(): void
    {
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->updateWith($old);
        $this->undoWith($old);
        $this->assertFileExists($this->box->root.'/'.self::DUMP, 'back after the undo');

        // The second update: no migration of this version is pending any more.
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        PendingMigrations::forget();
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        foreach ([self::DUMP, ...self::DOCKER] as $path) {
            $this->assertFileDoesNotExist($this->box->root.'/'.$path, $path.' removed again');
        }

        $this->assertContains('leftovers', $this->storedPlan()['finish_steps']);
        $state = PlanStore::read($this->box->paths()->state());
        $entries = $this->entries($this->box->paths()->backups((string) $state['backup_id']));
        // In the second update's backup, each with its line (gone now, the copy from before kept).
        foreach ([self::DUMP, 'docker-compose.yml'] as $path) {
            $this->assertArrayHasKey($path, $entries);
            $this->assertNull($entries[$path]['new']);
        }

        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot(), 'every file as before the first update');
    }

    public function test_the_old_built_assets_leave_14_days_after_the_update_and_undo_still_brings_them_back(): void
    {
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->updateWith($old);
        $kept = 'public/build/assets/app-111.js';
        $this->assertSame([$kept], PlanStore::read($this->box->paths()->state())['kept_assets'], 'kept for pages cached in browsers');
        $this->drainTrash();

        // Before 14 days: nothing to do.
        $this->ageState(13);
        $this->assertFalse($this->updater($this->next)->tidyPending());
        $this->updater($this->next)->tidy(1.0);
        $this->assertFileExists($this->box->root.'/'.$kept);

        // 14 days after: the tidy-up (Tools > Updates, the scheduler's updates:tidy) moves them into the update backup.
        $this->ageState(14);
        $this->assertTrue($this->updater($this->next)->tidyPending());
        $this->assertTrue($this->updater($this->next)->tidyDue(), 'the scheduler runs updates:tidy for it');
        $this->updater($this->next)->tidy(1.0);

        $this->assertFileDoesNotExist($this->box->root.'/'.$kept);
        $state = PlanStore::read($this->box->paths()->state());
        $this->assertArrayNotHasKey('kept_assets', $state);
        $this->assertFalse($this->updater($this->next)->tidyPending(), 'nothing left to do');
        $folder = $this->box->paths()->backups((string) $state['backup_id']);
        $this->assertSame("js 1\n", file_get_contents($folder.'/files/'.$kept));
        $this->assertSame('complete', PlanStore::read($folder.'/backup.json')['status'], 'the backup stays usable for Undo');
        $this->assertTrue($this->updater($this->next)->backups()[0]['undo']);

        // Undo puts them back with the rest.
        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot());
    }

    /**
     * A backup.json that cannot be read for a moment (another program holding it, a copy being written): the old built
     * assets wait for the next slice instead of being deleted without their copy in the backup (Undo would miss them).
     */
    public function test_the_old_built_assets_wait_while_the_update_backup_cannot_be_read(): void
    {
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->updateWith($old);
        $kept = 'public/build/assets/app-111.js';
        $this->drainTrash();
        $this->ageState(15);
        $state = PlanStore::read($this->box->paths()->state());
        $file = $this->box->paths()->backups((string) $state['backup_id']).'/backup.json';
        $bytes = (string) file_get_contents($file);
        file_put_contents($file, substr($bytes, 0, intdiv(strlen($bytes), 2)));

        $this->updater($this->next)->tidy(1.0);

        $this->assertFileExists($this->box->root.'/'.$kept, 'not deleted without its copy in the backup');
        $this->assertSame([$kept], PlanStore::read($this->box->paths()->state())['kept_assets'], 'still waiting');
        $this->assertTrue($this->updater($this->next)->tidyPending());

        // Readable again: the next slice moves them into the backup, and Undo brings them back.
        file_put_contents($file, $bytes);
        $this->updater($this->next)->tidy(1.0);

        $this->assertFileDoesNotExist($this->box->root.'/'.$kept);
        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot());
    }

    /**
     * A migration of Finish while plan.json or the update's backup.json cannot be read: whether a one-click update is
     * finishing cannot be told, so nothing is removed (the Finish step "leftovers" tries again); without any update
     * (no plan.json) the files are simply deleted, as after an upload by hand.
     */
    public function test_finish_removes_nothing_while_its_plan_or_backup_cannot_be_read(): void
    {
        $plan = $this->box->paths()->plan();
        File::ensureDirectoryExists(dirname($plan));
        $docker = 'docker-compose.yml';

        file_put_contents($plan, '{"kind": "update", "status": "migr');
        $this->assertSame([], FinishFiles::remove($this->box->root, [$docker]));
        $this->assertFileExists($this->box->root.'/'.$docker, 'plan.json half written: kept');

        $id = AppVersion::CURRENT.'-to-'.$this->next.'-20261029120000';
        PlanStore::write($plan, ['kind' => 'update', 'status' => 'migrating', 'backup_id' => $id]);
        File::ensureDirectoryExists($this->box->paths()->backups($id));
        file_put_contents($this->box->paths()->backups($id).'/backup.json', '{"status": "pend');
        $this->assertSame([], FinishFiles::remove($this->box->root, [$docker]));
        $this->assertFileExists($this->box->root.'/'.$docker, 'backup.json half written: kept');

        PlanStore::write($this->box->paths()->backups($id).'/backup.json', ['status' => 'pending', 'entries' => []]);
        $this->assertSame([$docker], FinishFiles::remove($this->box->root, [$docker]));
        $this->assertSame((string) $this->original($docker), (string) file_get_contents($this->box->paths()->backups($id).'/files/'.$docker), 'readable again: into the backup');

        @unlink($plan);
        $this->assertSame(['docker/php.ini'], FinishFiles::remove($this->box->root, ['docker/php.ini']));
        $this->assertFileDoesNotExist($this->box->root.'/docker/php.ini', 'no update at all: deleted');
    }

    /**
     * A file the swap itself put there that Finish removes (FinishFiles keeps its content in the line as "was"): when the
     * delete does not happen (a read-only file), Undo still finds it as the update left it and the tree comes back exact;
     * a later run of Finish deletes it.
     */
    public function test_a_file_the_swap_put_that_finish_could_not_delete_still_goes_back_with_undo(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can delete anything');
        }

        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        // The release added app/Support/New.php; a migration of Finish removes it, and its delete is refused once.
        $this->addFinishMigration("        \$file = \$root.'/app/Support/New.php';\n"
            ."        \$locked = PHP_OS_FAMILY === 'Windows' ? \$file : dirname(\$file);\n"
            ."        chmod(\$locked, PHP_OS_FAMILY === 'Windows' ? 0444 : 0555);\n"
            ."        \$GLOBALS['avdbcms_finish_files_test'] = \\App\\Support\\Updater\\FinishFiles::remove(\$root, ['app/Support/New.php']);\n"
            ."        chmod(\$locked, PHP_OS_FAMILY === 'Windows' ? 0644 : 0755);\n");
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        $this->assertSame([], $GLOBALS['avdbcms_finish_files_test'], 'the delete did not happen');
        $this->assertFileExists($this->box->root.'/app/Support/New.php');
        $state = PlanStore::read($this->box->paths()->state());
        $entry = $this->entries($this->box->paths()->backups((string) $state['backup_id']))['app/Support/New.php'];
        $this->assertSame([null, hash('sha256', "<?php // New 2\n")], [$entry['new'], $entry['was']], 'the line says it goes and what it was');

        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot(), 'every file as before the update');
        unset($GLOBALS['avdbcms_finish_files_test']);
    }

    public function test_a_later_run_of_finish_deletes_the_file_the_swap_put_and_undo_stays_exact(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can delete anything');
        }

        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $this->addFinishMigration("        \$file = \$root.'/app/Support/New.php';\n"
            ."        \$locked = PHP_OS_FAMILY === 'Windows' ? \$file : dirname(\$file);\n"
            ."        chmod(\$locked, PHP_OS_FAMILY === 'Windows' ? 0444 : 0555);\n"
            ."        \\App\\Support\\Updater\\FinishFiles::remove(\$root, ['app/Support/New.php']);\n"
            ."        chmod(\$locked, PHP_OS_FAMILY === 'Windows' ? 0644 : 0755);\n"
            ."        \$GLOBALS['avdbcms_finish_files_test'] = \\App\\Support\\Updater\\FinishFiles::remove(\$root, ['app/Support/New.php']);\n");
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        $this->assertSame(['app/Support/New.php'], $GLOBALS['avdbcms_finish_files_test'], 'the next run deleted it');
        $this->assertFileDoesNotExist($this->box->root.'/app/Support/New.php');

        $this->undoWith($old);
        $this->assertSame($this->before, $this->box->snapshot());
        unset($GLOBALS['avdbcms_finish_files_test']);
    }

    public function test_without_the_update_backup_the_old_built_assets_are_deleted_after_14_days(): void
    {
        $old = Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->updateWith($old);
        $state = PlanStore::read($this->box->paths()->state());
        File::deleteDirectory($this->box->paths()->backups((string) $state['backup_id']));
        $this->ageState(15);

        $this->updater($this->next)->tidy(1.0);

        $this->assertFileDoesNotExist($this->box->root.'/public/build/assets/app-111.js');
        $this->assertFileExists($this->box->root.'/public/build/assets/app-222.js', 'the installed version\'s own assets stay');
        $this->assertArrayNotHasKey('kept_assets', PlanStore::read($this->box->paths()->state()));
    }

    /* ----------------------------------------------------------------- helpers */

    /** 1.2.2's steps to the swap, the hook's swap, then this version's Finish with its file migrations. */
    private function updateWith(object $old): void
    {
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $this->addFinishMigration();
        $done = $this->finishOn($this->updater($this->next));
        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame($this->next, Manifest::installed($this->box->paths()->manifest())->version);
    }

    /** Undo on this version's code (its steps and hook), then 1.2.2 finishes it (the files of 1.2.2 are back). */
    private function undoWith(object $old): void
    {
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $answer = ['next' => 'finish'];

        for ($i = 0; $i < 10 && $answer['next'] === 'finish'; $i++) {
            $answer = $old->finish($this->admin);
        }

        $this->assertSame('undone', $answer['status'], (string) ($answer['message'] ?? ''));
    }

    /**
     * The database step of this version as Finish runs it on the sandbox's folder: the three file migrations of 1.2.3
     * (the pictures with the test's stand-in bytes), then $more (PHP code of a test, with $root).
     */
    private function addFinishMigration(string $more = ''): void
    {
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        $migrations = str_replace('\\', '/', base_path('database/migrations'));
        $pictures = var_export(array_combine(self::PICTURES, array_map(fn (string $path): string => hash('sha256', (string) $this->original($path)), self::PICTURES)), true);
        File::put($folder.'/2099_06_06_000000_sandbox_finish_files.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\n"
            ."return new class extends Migration\n{\n    public function up(): void\n    {\n"
            ."        \$root = '{$this->box->root}';\n"
            ."        (require '{$migrations}/2026_10_27_100000_remove_shipped_schema_dump.php')->clean(\$root);\n"
            ."        (require '{$migrations}/2026_10_27_140000_remove_shipped_docker_files.php')->clean(\$root);\n"
            ."        \$pictures = '{$migrations}/2026_10_29_100000_remove_unused_sign_in_and_error_pictures.php';\n"
            ."        if (is_file(\$pictures)) {\n            (require \$pictures)->clean(\$root, {$pictures});\n        }\n"
            .$more
            ."    }\n};\n");
        app('migrator')->path($folder);
        PendingMigrations::forget();
    }

    /** The old version's start + steps until the swap is ready. */
    private function oldToSwap(object $old): array
    {
        $start = $old->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);

        for ($i = 0; $i < 40; $i++) {
            $answer = $old->step($this->admin);
            $this->assertTrue($answer['ok'], (string) $answer['message']);

            if ($answer['next'] !== 'step') {
                $this->assertSame('swap', $answer['next']);

                return ['token' => $answer['token'], 'cookie' => $start['cookie'] ?? null];
            }
        }

        $this->fail('The old updater did not get to the swap in 40 steps.');
    }

    /**
     * makeSite() of UpdaterScenario with what 1.2.2 shipped outside its code folders and 1.2.3 no longer has (the dump,
     * the Docker files, the pictures: in the installed manifest, and in the release's "removed" list), the site's starter
     * data, and a release zip whose example.sql is the new one.
     */
    private function makeFinishSite(): void
    {
        config(['admin.operational_tools_enabled' => true, 'app.url' => 'http://localhost']);
        $this->box = new UpdaterSandbox();
        $this->next = UpdaterSandbox::nextVersion();
        File::ensureDirectoryExists($this->box->path('views'));
        config(['view.compiled' => $this->box->path('views')]);

        $leftovers = [];

        foreach ([self::DUMP, ...self::DOCKER, ...self::PICTURES] as $path) {
            $leftovers[$path] = (string) $this->original($path);
        }

        $leftovers['images/pages/contact.png'] = 'contact';
        $this->box->install(AppVersion::CURRENT, self::shippedFiles() + $leftovers, self::siteData() + [InstallData::PATH => self::exampleSql('1.2.2')]);
        $this->before = $this->box->snapshot();

        $files = self::releaseFiles() + ['images/pages/contact.png' => 'contact'];
        $removed = ['app/Support/Old.php', self::DUMP, ...self::DOCKER, ...self::PICTURES];
        ksort($files, SORT_STRING);

        $zip = new ZipBuilder();
        $zip->dir('app')->dir('storage/app/public');

        foreach ($files as $path => $content) {
            $zip->file((string) $path, $content);
        }

        $zip->file(Manifest::FILE, UpdaterSandbox::manifest($this->next, $files, $removed, '8.3.0', AppVersion::CURRENT))
            ->file('storage/app/.gitignore', "*\n!public/\n!.gitignore\n")
            ->file('storage/logs/.gitkeep', '')
            ->file(InstallData::PATH, self::exampleSql('next'));

        $this->github = (new ReleaseServer($this->next, $zip->save($this->box->path('release/'.AppVersion::updateAsset($this->next)))))->install();
        $this->database = new FakeDatabaseBackup($this->box->root.'/storage/app/backups');
        $this->admin = User::factory()->admin()->create(['username' => 'owner']);
    }

    /** The content 1.2.2 shipped for a leftover: the real dump and Docker files (tests/Fixtures), stand-in pictures. */
    private function original(string $path): ?string
    {
        return match (true) {
            $path === self::DUMP => (string) file_get_contents(base_path('tests/Fixtures/schema/mysql-schema-1.2.2.sql.txt')),
            in_array($path, self::DOCKER, true) => (string) file_get_contents(base_path('tests/Fixtures/docker-1.2.2/'.basename($path).'.txt')),
            in_array($path, self::PICTURES, true) => 'picture of 1.2.2: '.$path,
            default => null,
        };
    }

    /** A plausible starter data file (InstallData::plausible()). */
    private static function exampleSql(string $release): string
    {
        return "-- AVDBCMS {$release}\nCREATE TABLE `settings` (`id` int);\nINSERT INTO `settings` VALUES (1,'site_name','AVDBCMS {$release}','general',NULL,NULL);\n";
    }

    /** @return array<string, array<string, mixed>> path => its line in backup.json */
    private function entries(string $folder): array
    {
        $entries = [];

        foreach ((array) (PlanStore::read($folder.'/backup.json')['entries'] ?? []) as $entry) {
            $entries[(string) $entry['path']] = $entry;
        }

        return $entries;
    }

    /** state.json says the update finished $days days ago. */
    private function ageState(int $days): void
    {
        $state = (array) PlanStore::read($this->box->paths()->state());
        $state['updated_at'] = time() - $days * 86400 - 60;
        PlanStore::write($this->box->paths()->state(), $state);
    }

    /** The update's work files out of the trash first (the tidy-up's own business; not what these tests look at). */
    private function drainTrash(): void
    {
        for ($i = 0; $i < 200 && $this->updater($this->next)->tidy(0.2)['pending']; $i++) {
            if (! (new \App\Support\Updater\Trash($this->box->paths()))->pending()) {
                break;
            }
        }
    }
}
