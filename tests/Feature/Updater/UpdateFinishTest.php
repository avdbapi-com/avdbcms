<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\Updater;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * Finish and the clean-up after it (1.2.1, B1 of the 1.2.0 tests: Finish deleted the whole work folder, ~18,500
 * staged files and the 67 MB zip, inside its own request: 13-18 s, with the host's default time limit):
 *
 *  - Finish raises the time limit before the database step, also when no migration is pending;
 *  - the work folder leaves in one rename (into storage/app/updates/.trash) with everything it held; Cancel after the
 *    unpack does the same with the staging folder;
 *  - tidy() deletes the trash in slices of at most its seconds, and sweeps leftovers no plan needs (never the folder
 *    of a failed or cancelled plan: its step log and a zip a new try may use);
 *  - Tools > Updates asks for slices while something waits (status?tidy=1, operational tools on), and the scheduler
 *    runs updates:tidy only then;
 *  - all of that only between updates: while an update or undo runs, its own steps decide when the trash goes, and an
 *    undo's step that finds another clean-up holding the trash waits for it within its slice and tells the page to
 *    wait too (never steps back to back: hosts with request-rate limits would block the administrator).
 */
class UpdateFinishTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    /** Files added to the staging of the test release: a work folder that takes more than one slice to delete. */
    private const EXTRA = 1500;

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

    public function test_finish_raises_the_time_limit_and_moves_the_work_folder_out_without_deleting_a_file(): void
    {
        $ready = $this->driveToSwap();
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $this->stageExtraFiles();
        $staged = $this->countFiles($this->jobFolder());

        if (function_exists('set_time_limit')) {
            set_time_limit(25);
        }

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);

        if (function_exists('set_time_limit')) {
            $this->assertSame(Updater::FINISH_SECONDS, (int) ini_get('max_execution_time'), 'Finish gives itself time without a pending migration too');
        }

        // Nothing of the work folder was deleted in Finish: all of it waits in the trash.
        $this->assertDirectoryDoesNotExist($this->jobFolder());
        $trashed = glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $trashed);
        $this->assertSame($staged, $this->countFiles($trashed[0]));
        $this->assertTrue($this->updater($this->next)->tidyPending());

        // tidy() empties it in slices of at most its seconds.
        $slices = [];

        do {
            $tidy = $this->updater($this->next)->tidy(0.02);
            $slices[] = $tidy['deleted'];
        } while ($tidy['pending'] && count($slices) < 2000);

        $this->assertFalse($tidy['pending']);
        $this->assertGreaterThan(1, count($slices), 'more than one slice: '.json_encode($slices));
        $this->assertGreaterThanOrEqual($staged, array_sum($slices));
        $this->assertSame([], array_values(array_diff(scandir($this->box->paths()->trash()) ?: [], ['.', '..'])));
        $this->assertFalse($this->updater($this->next)->tidyPending());
    }

    public function test_cancel_after_the_unpack_moves_the_staged_files_out_in_one_rename(): void
    {
        $this->updater()->start($this->admin);
        $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'preparing');
        $this->stageExtraFiles();
        $staged = $this->countFiles($this->jobFolder().'/staging');

        $cancel = $this->updater()->cancel($this->admin);

        $this->assertSame('cancelled', $cancel['status']);
        $this->assertSame(['update.log'], $this->jobFiles(), 'only the step log stays in the work folder');
        $trashed = glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $trashed);
        $this->assertSame($staged, $this->countFiles($trashed[0].'/staging'));
        $this->assertFileExists($trashed[0].'/'.$this->github->zipName(), 'the zip went with it (Cancel keeps no zip)');
        $this->assertSiteUnchanged();
    }

    public function test_tidy_sweeps_leftovers_no_plan_needs_and_keeps_what_a_plan_needs(): void
    {
        $paths = $this->box->paths();
        // An update that failed: its work folder keeps the zip and the step log for a new try.
        $this->updater()->start($this->admin);
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
        $this->changePlan(['status' => 'failed', 'failure' => ['step' => 'verify', 'reason' => 'x', 'audited' => true]]);
        // Leftovers: the work folder of an older try, an undo folder, a backup folder without backup.json from long
        // ago (a delete that could not finish), and a fresh one (made a moment ago by a step: not touched).
        File::ensureDirectoryExists($paths->job('0.9.8').'/staging/app');
        File::put($paths->job('0.9.8').'/staging/app/A.php', 'a');
        File::ensureDirectoryExists($paths->job('undo-0.9.8'));
        File::put($paths->job('undo-0.9.8').'/live.json', '{}');
        File::ensureDirectoryExists($paths->backups('0.9.7-to-0.9.8-20260101000000').'/files');
        touch($paths->backups('0.9.7-to-0.9.8-20260101000000'), time() - 7200);
        File::ensureDirectoryExists($paths->backups('0.9.8-to-0.9.9-20260102000000').'/files');
        File::put($paths->work('notes.txt'), 'not a work folder');

        $this->assertTrue($this->updater()->tidyPending());
        $tidy = $this->updater()->tidy(5.0);

        $this->assertFalse($tidy['pending']);
        $this->assertDirectoryDoesNotExist($paths->job('0.9.8'));
        $this->assertDirectoryDoesNotExist($paths->job('undo-0.9.8'));
        $this->assertDirectoryDoesNotExist($paths->backups('0.9.7-to-0.9.8-20260101000000'));
        $this->assertDirectoryExists($paths->backups('0.9.8-to-0.9.9-20260102000000'));
        $this->assertFileExists($this->zipFile(), 'the failed plan keeps its zip');
        $this->assertFileExists($this->jobFolder().'/update.log');
        $this->assertFileExists($paths->work('notes.txt'));
        $this->assertFalse($this->updater()->tidyPending());
    }

    public function test_a_finished_plan_whose_work_folder_is_still_there_gets_it_swept_with_its_log_kept(): void
    {
        $this->updateToNext();
        $plan = $this->storedPlan();
        // The rename of Finish was refused (a file held open): the folder stayed where it was.
        $trashed = (glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: [])[0];
        rename($trashed, $this->jobFolder());
        File::delete($this->box->paths()->logs($plan['id'].'.log'));

        $this->assertTrue($this->updater($this->next)->tidyPending());
        $this->assertFalse($this->updater($this->next)->tidy(5.0)['pending']);

        $this->assertDirectoryDoesNotExist($this->jobFolder());
        $this->assertStringContainsString('is installed', (string) file_get_contents($this->box->paths()->logs($plan['id'].'.log')));
        $this->assertStringContainsString('is installed', implode("\n", $this->updater($this->next)->logLines()));
    }

    public function test_the_work_folder_of_a_finished_update_left_in_place_is_never_put_back_by_a_later_update(): void
    {
        $this->updateToNext();
        $installed = $this->box->snapshot();
        // Its folder could not leave (a rename refused, or a Finish of 1.2.0 cut off while it deleted): the journal of
        // its swap, every move ended, is still there when the next update starts and prunes the folders of others.
        $trashed = (glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: [])[0];
        rename($trashed, $this->jobFolder());
        $this->assertFileExists($this->jobFolder().'/journal.jsonl');

        $later = UpdaterSandbox::nextVersion($this->next);
        $files = ['config/app.php' => "<?php return ['v' => 3];\n"] + self::releaseFiles();
        $zip = $this->box->releaseZip($later, $files, [], UpdaterSandbox::manifest($later, $files, [], '8.3.0', $this->next));
        Http::swap(new HttpFactory());
        (new ReleaseServer($later, $zip))->install();

        $start = $this->updater($this->next)->start($this->admin);

        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertSame($later, $this->storedPlan()['version']);
        $this->assertSame($installed, $this->box->snapshot(), 'the finished update stays installed: nothing was put back');
        $this->assertDirectoryDoesNotExist($this->jobFolder(), 'its folder went to the trash');
    }

    public function test_the_page_asks_for_clean_up_slices_while_work_files_wait(): void
    {
        $this->app->instance(Updater::class, $this->updater($this->next));
        $this->updateToNext();
        $this->assertTrue($this->updater($this->next)->tidyPending());

        $html = $this->actingAs($this->admin)->get(route('admin.updates.index'))->assertOk()->getContent();
        $this->assertStringContainsString('"tidy":true', $html);

        // Without tidy=1 the status deletes nothing; with it, one slice.
        $this->actingAs($this->admin)->getJson(route('admin.updates.status'))->assertOk()->assertJsonPath('tidy', null);
        $this->assertTrue($this->updater($this->next)->tidyPending());
        $answer = $this->actingAs($this->admin)->getJson(route('admin.updates.status', ['tidy' => 1]))->assertOk()->json('tidy');

        $this->assertSame(false, $answer['pending']);
        $this->assertGreaterThan(0, $answer['deleted']);
        $this->assertFalse($this->updater($this->next)->tidyPending());
        $this->assertStringContainsString('"tidy":false', $this->actingAs($this->admin)->get(route('admin.updates.index'))->getContent());

        // With the operational tools off, nothing is deleted from the page.
        $this->makeTrash();
        config(['admin.operational_tools_enabled' => false]);
        $this->actingAs($this->admin)->getJson(route('admin.updates.status', ['tidy' => 1]))->assertOk()->assertJsonPath('tidy', null);
        $this->assertTrue($this->updater($this->next)->tidyPending());
    }

    public function test_the_scheduler_runs_updates_tidy_only_while_something_waits(): void
    {
        $this->app->instance(Updater::class, $this->updater());
        $events = array_values(array_filter($this->app->make(Kernel::class)->resolveConsoleSchedule()->events(),
            fn ($event): bool => str_contains((string) $event->command, 'updates:tidy')));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertFalse($events[0]->filtersPass($this->app), 'nothing waits: not even started');

        $this->makeTrash();
        $this->assertTrue($events[0]->filtersPass($this->app));

        $this->artisan('updates:tidy', ['--seconds' => 5])->assertExitCode(0);

        $this->assertFalse($this->updater()->tidyPending());
        $this->assertFalse($events[0]->filtersPass($this->app));
    }

    public function test_the_trash_is_tidied_between_updates_only(): void
    {
        $this->app->instance(Updater::class, $this->updater($this->next));
        $this->updateToNext();
        $events = array_values(array_filter($this->app->make(Kernel::class)->resolveConsoleSchedule()->events(),
            fn ($event): bool => str_contains((string) $event->command, 'updates:tidy')));
        $this->assertTrue($events[0]->filtersPass($this->app), 'the update\'s work files wait in the trash');

        // An undo runs: its own steps empty the trash before the site goes down. Nothing else deletes it meanwhile (the
        // slices a second Tools > Updates tab asks for, the scheduler, updates:tidy by hand), and all of them say why.
        $this->assertTrue($this->updater($this->next)->undo($this->admin)['ok']);

        $tidy = $this->actingAs($this->admin)->getJson(route('admin.updates.status', ['tidy' => 1]))->assertOk()->json('tidy');
        $this->assertSame(['pending' => true, 'deleted' => 0, 'busy' => false, 'running' => true], $tidy);
        $this->assertFalse($events[0]->filtersPass($this->app), 'the scheduler does not even start');
        $this->artisan('updates:tidy', ['--seconds' => 5])->expectsOutputToContain('an update or undo is running')->assertExitCode(0);
        $this->assertTrue($this->updater($this->next)->tidyPending(), 'nothing was deleted');

        // Once it is over (here: cancelled before it compared a file), the trash goes again.
        $this->assertSame('cancelled', $this->updater($this->next)->cancel($this->admin)['status']);
        $this->assertTrue($events[0]->filtersPass($this->app));
        $tidy = $this->actingAs($this->admin)->getJson(route('admin.updates.status', ['tidy' => 1]))->assertOk()->json('tidy');
        $this->assertSame([false, false], [$tidy['pending'], $tidy['running']]);
        $this->assertGreaterThan(0, $tidy['deleted']);
        $this->assertFalse($this->updater($this->next)->tidyPending());
    }

    public function test_an_undo_waits_for_another_clean_up_of_the_trash_instead_of_stepping_back_to_back(): void
    {
        $this->updateToNext();
        $this->assertTrue($this->updater($this->next)->tidyPending(), 'the update\'s work files wait in the trash');
        $this->assertTrue($this->updater($this->next)->undo($this->admin)['ok']);

        // Another clean-up holds the trash for the whole step (the scheduler's updates:tidy, or the page's own slice that
        // was still running when Undo was clicked). The step waits for it within its few seconds and then tells the page
        // to wait as well: it never answers at once, which made the page send its steps back to back.
        $lock = fopen($this->box->paths()->trashLock(), 'c');
        $this->assertTrue(flock($lock, LOCK_EX));

        try {
            $at = microtime(true);
            $busy = $this->updater($this->next)->step($this->admin);
            $took = microtime(true) - $at;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(['ok' => true, 'status' => 'preparing', 'next' => 'step', 'retry_after' => 2],
            array_intersect_key($busy, array_flip(['ok', 'status', 'next', 'retry_after'])), (string) $busy['message']);
        // Its slice less the last half second or so (a last try keeps at least half a second to delete in).
        $this->assertGreaterThanOrEqual(Updater::TIDY_SECONDS - 1.0, $took, 'it waited for the other clean-up');
        $this->assertLessThan(Updater::TIDY_SECONDS + 2.0, $took, 'a few seconds at most');
        $this->assertTrue($this->updater($this->next)->tidyPending(), 'nothing deleted while the other one held it');
        $this->assertSame('preparing', $this->storedPlan()['status']);
        $this->assertFileDoesNotExist($this->box->paths()->hook(), 'the site stays up');

        // The other clean-up ends a moment after the next step began: the step waits for it, then empties the trash
        // itself, in the same request, and goes on.
        $holder = $this->holdTrashLockElsewhere(1.0);

        try {
            $at = microtime(true);
            $next = $this->updater($this->next)->step($this->admin);
            $took = microtime(true) - $at;
        } finally {
            $holder->wait();
        }

        $this->assertTrue($next['ok'], (string) $next['message']);
        $this->assertArrayNotHasKey('retry_after', $next, 'it got the trash in the end: no reason to wait');
        $this->assertGreaterThan(0.5, $took, 'it waited for the other process');
        $this->assertFalse($this->updater($this->next)->tidyPending(), 'then it emptied the trash');

        $ready = $this->stepUntil($this->updater($this->next), fn (array $a): bool => $a['next'] !== 'step');
        $this->assertSame('swap', $ready['next'], (string) $ready['message']);
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * Another PHP process that holds the trash lock for $seconds (a clean-up of the scheduler); returns once it holds it.
     */
    private function holdTrashLockElsewhere(float $seconds): Process
    {
        $script = $this->box->path('hold-trash-lock.php');
        $held = $this->box->path('trash-lock-held');
        file_put_contents($script, "<?php\n\$h = fopen(\$argv[1], 'c');\nflock(\$h, LOCK_EX);\nfile_put_contents(\$argv[2], 'held');\n"
            ."usleep((int) ((float) \$argv[3] * 1000000));\nflock(\$h, LOCK_UN);\nfclose(\$h);\n");
        @unlink($held);

        $process = new Process([PHP_BINARY, $script, $this->box->paths()->trashLock(), $held, (string) $seconds]);
        $process->start();

        for ($i = 0; $i < 500 && ! is_file($held) && $process->isRunning(); $i++) {
            usleep(10_000);
        }

        $this->assertFileExists($held, 'the other process holds the trash lock: '.$process->getErrorOutput());

        return $process;
    }

    /** EXTRA more files in the staging folder of the update (a work folder like a real release's, smaller). */
    private function stageExtraFiles(): void
    {
        $folder = $this->jobFolder().'/staging/vendor/extra';

        for ($i = 0; $i < self::EXTRA; $i++) {
            $sub = $folder.'/d'.intdiv($i, 100);

            if ($i % 100 === 0) {
                File::ensureDirectoryExists($sub);
            }

            file_put_contents($sub.'/f'.$i.'.php', '<?php // '.$i);
        }
    }

    /** A folder of work files waiting in the trash. */
    private function makeTrash(): void
    {
        $entry = $this->box->paths()->trash('9.9.9-20261024000000-abcdef');
        File::ensureDirectoryExists($entry.'/staging/app');
        File::put($entry.'/staging/app/A.php', 'a');
        File::put($entry.'/avdbcms-update-9.9.9.zip', 'zip');
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
