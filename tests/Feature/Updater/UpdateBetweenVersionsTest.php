<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\PendingMigrations;
use App\Support\Updater\Manifest;
use App\Support\Updater\PlanStore;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Updater\Support\Updater120;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * What passes between two versions in a one-click update (1.2.1, B1 of the 1.2.0 tests): the update from 1.2.0 runs
 * every step up to the swap in 1.2.0's code (its maintenance hook too) and Finish in the new code; Undo runs the new
 * code's steps and hook, then 1.2.0's Finish. Here 1.2.0's own updater (Updater120: the files it shipped, byte for
 * byte) plays the old side.
 *
 *  - Finish of the new code takes the plan.json, the work folder and state.json exactly as 1.2.0 left them: the update
 *    completes, every admin-log row once, and the work folder (staging + zip) leaves in ONE rename, never deleted in
 *    the Finish request; tidy() deletes it later in slices;
 *  - a Finish the host cut off (after its admin-log rows, or right after the database step) and clicked again writes
 *    every row once, with the right count of database changes;
 *  - an Undo prepared by the new code is finished by 1.2.0: every file back byte for byte, and no trash left behind
 *    (1.2.0 knows nothing of it).
 */
class UpdateBetweenVersionsTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_finish_takes_what_the_1_2_0_steps_left_and_moves_the_work_folder_out_in_one_rename(): void
    {
        $old = Updater120::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame(Updater120::name('Updater'), get_class($old));
        $ready = $this->oldToSwap($old);

        // The maintenance hook is 1.2.0's: its SwapEngine writes its log lines with date(), in php.ini's time zone.
        $hook = (string) file_get_contents($this->box->paths()->hook());
        $this->assertStringContainsString("date('Y-m-d H:i:s').\"\\tswap\\t\"", $hook);
        $this->assertStringNotContainsString('function stamp(', $hook);
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);

        // What 1.2.0 leaves for Finish, as it is.
        $plan = $this->storedPlan();
        $job = $this->jobFolder();
        $staged = $this->countFiles($job.'/staging');
        $this->assertSame('swapped', $plan['status']);
        $this->assertSame([], array_intersect(array_keys($plan), ['finish_steps', 'audit_begun', 'audit_rows', 'migrations']), 'nothing of the new code in the plan');
        // The unchanged files stay staged (on a real site nearly all ~18,500: only the changed ones moved in).
        $this->assertGreaterThan(0, $staged);
        $this->assertFileExists($this->zipFile());
        $this->assertFileExists($job.'/update.log');
        $this->assertNull($this->updater($this->next)->state(), 'no state.json yet: this is the first update');
        $this->assertSame('pending', PlanStore::read($this->box->paths()->backups($plan['backup_id']).'/backup.json')['status']);

        $this->addPendingMigration('2099_04_04_000000_create_updater_between_marker_table.php', 'updater_between_marker');
        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame(['ok' => true, 'status' => 'done', 'next' => 'reload'], array_intersect_key($done, ['ok' => 1, 'status' => 1, 'next' => 1]));
        $this->assertSame('AVDBCMS '.$this->next.' is installed.', $done['message']);
        $this->assertTrue(Schema::hasTable('updater_between_marker'), 'the database step ran');
        $this->assertSame(self::releaseFiles()['config/app.php'], $this->box->read('config/app.php'));
        $this->assertSame(self::releaseFiles()['app/Support/New.php'], $this->box->read('app/Support/New.php'));
        $this->assertNull($this->box->read('app/Support/Old.php'));
        $this->assertSame($this->next, Manifest::installed($this->box->paths()->manifest())->version);

        // Every admin-log row once.
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], array_column($this->auditRows(), 'action'));
        $this->assertSame(AppVersion::CURRENT.' -> '.$this->next.' (1 database changes)', $this->auditRows()[3]['details']);

        // state.json and the file backup, complete; the step log kept (1.2.0's lines and the new Finish line).
        $state = $this->updater($this->next)->state();
        $this->assertSame([$this->next, AppVersion::CURRENT, $plan['backup_id'], 'update'], [$state['installed_version'], $state['previous_version'], $state['backup_id'], $state['kind']]);
        $backup = PlanStore::read($this->box->paths()->backups($plan['backup_id']).'/backup.json');
        $this->assertSame('complete', $backup['status']);
        $log = implode("\n", $this->updater($this->next)->logLines(null, 500));
        $this->assertStringContainsString('started by owner', $log);
        $this->assertStringContainsString('database step done (1 migrations); AVDBCMS '.$this->next.' is installed', $log);
        $this->assertTrue($this->updater($this->next)->backups()[0]['undo'], 'Undo this update is offered');

        // B1: the work folder left its place in one rename, with all it held: nothing of it was deleted in Finish.
        $this->assertDirectoryDoesNotExist($job);
        $trashed = glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $trashed);
        $this->assertSame($staged, $this->countFiles($trashed[0].'/staging'));
        $this->assertFileExists($trashed[0].'/'.$this->github->zipName());

        // tidy() deletes it later, in slices.
        $slices = 0;

        do {
            $tidy = $this->updater($this->next)->tidy(0.005);
            $slices++;
        } while ($tidy['pending'] && $slices < 500);

        $this->assertFalse($tidy['pending']);
        $this->assertSame([], array_values(array_diff(scandir($this->box->paths()->trash()) ?: [], ['.', '..'])));
        $this->assertFalse($this->updater($this->next)->tidyPending());
    }

    public function test_a_finish_cut_off_after_its_admin_log_rows_writes_none_again(): void
    {
        $old = Updater120::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $before = $this->storedPlan();
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        $rows = $this->auditRows();
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], array_column($rows, 'action'));

        // The host killed that Finish right after its admin-log rows went in, before the plan could say so: the plan
        // still says migrating, and the work folder is where it was.
        $trashed = (glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: [])[0];
        rename($trashed, $this->jobFolder());
        PlanStore::write($this->box->paths()->plan(), $before + ['migrations' => 0, 'finish_steps' => ['recorded'], 'audit_begun' => true]);
        $this->changePlan(['status' => 'migrating']);

        $again = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $again['status'], (string) $again['message']);
        $this->assertSame($rows, $this->auditRows(), 'no admin-log row twice');
        $this->assertDirectoryDoesNotExist($this->jobFolder());
        $this->assertSame(['recorded'], $this->storedPlan()['finish_steps']);
        $this->assertSame(['update.files', 'update.finished'], $this->storedPlan()['audit_rows']);

        // And once more (the answer of the second one was lost too): nothing changes.
        $this->assertSame('done', $this->updater($this->next)->finish($this->admin)['status']);
        $this->assertSame($rows, $this->auditRows());
    }

    public function test_a_finish_cut_off_right_after_its_database_step_counts_the_changes_it_made(): void
    {
        $old = Updater120::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);

        // The killed Finish ran the migration and saved its count; nothing after that.
        $this->addPendingMigration('2099_05_05_000000_create_updater_cut_marker_table.php', 'updater_cut_marker');
        $this->assertTrue(PendingMigrations::run()['ok']);
        $this->changePlan(['status' => 'migrating', 'migrations' => 1]);

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], array_column($this->auditRows(), 'action'));
        $this->assertSame(AppVersion::CURRENT.' -> '.$this->next.' (1 database changes)', $this->auditRows()[3]['details']);
        $this->assertSame(1, $this->storedPlan()['migrations']);
    }

    public function test_an_undo_prepared_by_the_new_code_is_finished_by_1_2_0_and_leaves_no_trash(): void
    {
        $old = Updater120::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        $this->assertTrue($this->updater($this->next)->tidyPending(), 'the update\'s work files wait in the trash');

        // The new code's undo: its steps empty the trash before the site goes down (1.2.0 would never delete it).
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertFalse($this->updater($this->next)->tidyPending());
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);

        // 1.2.0 runs again and finishes the undo with its own code.
        $answer = ['next' => 'finish'];

        for ($i = 0; $i < 10 && $answer['next'] === 'finish'; $i++) {
            $answer = $old->finish($this->admin);
        }

        $this->assertSame('undone', $answer['status'], (string) ($answer['message'] ?? ''));
        $this->assertSame($this->withoutCompiledCaches($this->before), $this->box->snapshot(), 'every file as before the update');
        $this->assertSame(AppVersion::CURRENT, Manifest::installed($this->box->paths()->manifest())->version);
        $state = $old->state();
        $this->assertSame([AppVersion::CURRENT, $this->next, null], [$state['installed_version'], $state['previous_version'], $state['backup_id']]);
        $this->assertSame([], array_values(array_diff(is_dir($this->box->paths()->trash()) ? (scandir($this->box->paths()->trash()) ?: []) : [], ['.', '..'])), 'no trash left behind');
        $this->assertContains('update.undone', array_column($this->auditRows(), 'action'));
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * 1.2.0's start + steps until the swap is ready (the answer with the swap token), as its page walks them.
     *
     * @return array{token: string, cookie: string}
     */
    private function oldToSwap(object $old): array
    {
        $start = $old->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $start['cookie'] ?? '');

        for ($i = 0; $i < 40; $i++) {
            $answer = $old->step($this->admin);
            $this->assertTrue($answer['ok'], (string) $answer['message']);

            if ($answer['next'] !== 'step') {
                $this->assertSame('swap', $answer['next']);
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $answer['token'] ?? '');

                return ['token' => $answer['token'], 'cookie' => $start['cookie']];
            }
        }

        $this->fail('1.2.0 did not get to the swap in 40 steps.');
    }

    private function addPendingMigration(string $file, string $table): void
    {
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        File::put($folder.'/'.$file, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
            ."use Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n"
            ."        Schema::create('{$table}', fn (Blueprint \$table) => \$table->id());\n    }\n};\n");
        app('migrator')->path($folder);
        PendingMigrations::forget();
    }

    /** @param  array<string, string>  $tree */
    private function withoutCompiledCaches(array $tree): array
    {
        unset($tree['bootstrap/cache/config.php'], $tree['bootstrap/cache/packages.php']);

        return $tree;
    }

    private function countFiles(string $folder): int
    {
        if (! is_dir($folder)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() ? 1 : 0;
        }

        return $count;
    }
}
