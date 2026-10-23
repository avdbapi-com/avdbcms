<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * One update at a time (F8 14, 18 "Locks"): while a plan runs, another administrator's start, step, cancel and undo
 * are refused with "An update to X.Y.Z is being installed by :name since :time."; a plan with no step for 30 minutes
 * is stalled, and then any administrator may continue it (it becomes theirs) or cancel it while no file has moved; two
 * steps at the same moment (another PHP process holding storage/app/updates/update.lock) never run together: the
 * second answers "busy" (HTTP 409 for the page's script), moves nothing, and a status poll is never blocked.
 */
class UpdateLockTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const BUSY = 'Another step of the update is running. Wait a moment.';

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

    public function test_a_second_update_is_refused_while_one_runs(): void
    {
        $first = $this->updater()->start($this->admin);
        $this->assertTrue($first['ok'], (string) $first['message']);
        $plan = $this->storedPlan();
        $second = $this->otherAdmin();
        $busy = 'An update to '.$this->next.' is being installed by owner since '
            .Carbon::createFromTimestamp($plan['started_at'])->setTimezone(config('app.timezone'))->translatedFormat('H:i').'.';

        foreach (['start' => fn () => $this->updater()->start($second), 'step' => fn () => $this->updater()->step($second),
            'cancel' => fn () => $this->updater()->cancel($second), 'undo' => fn () => $this->updater()->undo($second)] as $action => $call) {
            $answer = $call();

            $this->assertFalse($answer['ok'], $action);
            $this->assertSame($busy, $answer['message'], $action);
            $this->assertNull($answer['next'], $action);
        }

        // The plan is still the first administrator's, untouched; nothing was downloaded for the others.
        $this->assertSame($plan, $this->storedPlan());
        $this->assertSame([], $this->github->zipRanges());

        // Its owner clicking "Update" again gets the running plan, not a second one.
        $again = $this->updater()->start($this->admin);
        $this->assertTrue($again['ok']);
        $this->assertSame(['downloading', 'step'], [$again['status'], $again['next']]);
        $this->assertSame($plan['id'], $this->storedPlan()['id']);

        // The page of the other administrator says the same and offers only "Show progress".
        $this->app->instance(Updater::class, $this->updater());
        $this->actingAs($second)->postJson(route('admin.updates.start'), ['password' => 'password'])->assertOk()->assertJson(['ok' => false, 'message' => $busy]);
        $this->actingAs($second)->getJson(route('admin.updates.status'))->assertOk()
            ->assertJsonPath('plan.mine', false)->assertJsonPath('plan.active', true)->assertJsonPath('plan.busy', $busy);
        $this->assertSame(['update.started'], array_column($this->auditRows(), 'action'));
    }

    public function test_a_stalled_update_can_be_continued_by_another_administrator_and_becomes_theirs(): void
    {
        $this->updater()->start($this->admin);
        $stalledAt = time() - Updater::STALL_SECONDS - 60;
        $this->changePlan(['step_at' => $stalledAt]);
        $second = $this->otherAdmin();

        $refused = $this->updater()->start($second);

        $this->assertFalse($refused['ok']);
        $this->assertSame('An update to '.$this->next.' stopped '.Carbon::createFromTimestamp($stalledAt)->diffForHumans().'. Continue it or cancel it first.', $refused['message']);

        $continued = $this->updater()->step($second);

        // Taking over is an answer of its own: it hands the new owner the cookie of the maintenance page (theirs from
        // now on), before any later step can take the site down.
        $this->assertTrue($continued['ok'], (string) $continued['message']);
        $this->assertSame(['downloading', 'step'], [$continued['status'], $continued['next']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $continued['cookie'] ?? '');
        $this->assertSame(hash('sha256', $continued['cookie']), $this->storedPlan()['cookie']['hash']);
        $this->assertSame([], $this->github->zipRanges(), 'nothing else happened in that answer');

        $went = $this->updater()->step($second);

        $this->assertTrue($went['ok'], (string) $went['message']);
        $this->assertSame('verifying', $went['status'], 'the download went on');
        $this->assertArrayNotHasKey('cookie', $went);
        $plan = $this->storedPlan();
        $this->assertSame([(int) $second->getKey(), 'second'], [$plan['admin_id'], $plan['admin_name']]);
        $this->assertEqualsWithDelta(time(), $plan['step_at'], 5);
        $log = implode("\n", $this->updater()->logLines());
        $this->assertStringContainsString('stalled since '.date('Y-m-d H:i:s', $stalledAt).'; continued by second', $log);

        // Now the first administrator is the one who waits.
        $waits = $this->updater()->step($this->admin);
        $this->assertFalse($waits['ok']);
        $this->assertStringStartsWith('An update to '.$this->next.' is being installed by second since ', (string) $waits['message']);
    }

    public function test_a_stalled_update_can_be_cancelled_by_another_administrator_and_nothing_changes(): void
    {
        $this->updater()->start($this->admin);
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
        $this->changePlan(['step_at' => time() - Updater::STALL_SECONDS - 1]);

        $cancel = $this->updater()->cancel($this->otherAdmin());

        $this->assertTrue($cancel['ok'], (string) $cancel['message']);
        $this->assertSame(['cancelled', 'reload', 'The update was cancelled. Nothing on your site was changed.'], [$cancel['status'], $cancel['next'], $cancel['message']]);
        $this->assertSame(['update.log'], $this->jobFiles(), 'the downloaded zip is gone, the step log stays');
        $this->assertStringContainsString('cancelled by second', implode("\n", $this->updater()->logLines()));
        $this->assertFalse(Updater::active($this->storedPlan()));
        $this->assertSiteUnchanged();

        // A new update may start now.
        $this->assertTrue($this->updater()->start($this->admin)['ok']);
    }

    public function test_an_undo_still_comparing_files_can_be_cancelled_and_the_update_stays_undoable(): void
    {
        $this->updateToNext();
        $new = $this->updater($this->next);
        $backup = $this->storedPlan()['backup_id'];
        $site = $this->box->snapshot();

        $this->assertSame(['preparing', 'step'], array_values(array_intersect_key($new->undo($this->admin), ['status' => 1, 'next' => 1])));
        $this->assertSame('step', $new->step($this->admin)['next'], 'it compares the files with the backup');

        $cancelled = $new->cancel($this->admin);

        $this->assertTrue($cancelled['ok']);
        $this->assertSame(['cancelled', 'The undo was cancelled. Your site keeps the new version.'], [$cancelled['status'], $cancelled['message']]);
        $this->assertSame($site, $this->box->snapshot(), 'nothing moved');
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertDirectoryExists($this->box->paths()->backups($backup).'/files', 'the update\'s own backup is not an undo\'s to delete');
        $this->assertTrue($new->backups()[0]['undo'], 'and Undo this update is offered again');
    }

    public function test_an_update_whose_files_are_moving_cannot_be_cancelled_even_when_stalled(): void
    {
        $this->driveToSwap();
        $this->changePlan(['step_at' => time() - Updater::STALL_SECONDS - 1]);

        foreach ([$this->otherAdmin(), $this->admin] as $admin) {
            $answer = $this->updater()->cancel($admin);

            $this->assertFalse($answer['ok']);
            $this->assertSame('Only an update that has not started to replace files can be cancelled. Finish it or undo it instead.', $answer['message']);
        }

        $this->assertSame('swapping', $this->storedPlan()['status']);
        $this->assertFileExists($this->box->paths()->hook(), 'the swap keeps its maintenance hook (Finish or Undo from there)');
    }

    public function test_two_steps_at_the_same_moment_one_answers_busy_and_moves_nothing(): void
    {
        $this->updater()->start($this->admin);
        $planBefore = $this->storedPlan();
        $lock = $this->holdUpdateLock();

        try {
            $busy = $this->updater()->step($this->admin);

            $this->assertSame(['ok' => false, 'busy' => true, 'status' => 'downloading', 'next' => null, 'label' => null, 'progress' => null,
                'retry_after' => 3, 'message' => self::BUSY], $busy);
            $this->assertSame([], $this->github->zipRanges(), 'the busy step downloaded nothing');
            $this->assertSame($planBefore, $this->storedPlan());

            // Every other action waits the same way; the page's script gets 409 and asks again after retry_after.
            foreach (['start', 'finish', 'cancel', 'undo'] as $action) {
                $answer = $this->updater()->{$action}($this->admin);
                $this->assertSame([true, self::BUSY], [$answer['busy'] ?? false, $answer['message']], $action);
            }

            $this->app->instance(Updater::class, $this->updater());
            $this->actingAs($this->admin)->postJson(route('admin.updates.step'))->assertStatus(409)->assertJson(['ok' => false, 'busy' => true, 'retry_after' => 3, 'message' => self::BUSY]);

            // How the update stands can always be read.
            $this->actingAs($this->admin)->getJson(route('admin.updates.status'))->assertOk()->assertJsonPath('plan.status', 'downloading');
        } finally {
            $this->releaseUpdateLock($lock);
        }

        $this->assertSame('verifying', $this->updater()->step($this->admin)['status'], 'the lock is free again: the step runs');
    }

    public function test_a_step_running_in_another_php_process_makes_this_one_wait(): void
    {
        $this->updater()->start($this->admin);
        $holder = $this->box->path('hold-lock.php');
        file_put_contents($holder, "<?php\n\$h = fopen(\$argv[1], 'c');\nif (! flock(\$h, LOCK_EX)) { exit(1); }\necho \"locked\\n\";\nsleep(30);\n");
        $process = new Process([PHP_BINARY, '-n', $holder, $this->box->paths()->lock()], $this->box->base, null, null, 60);
        $process->start();

        try {
            $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked'));

            $busy = $this->updater()->step($this->admin);

            $this->assertTrue($busy['busy'] ?? false, json_encode($busy));
            $this->assertSame(self::BUSY, $busy['message']);
            $this->assertSame([], $this->github->zipRanges());
        } finally {
            $process->stop(0);
        }

        // The system frees the lock of an ended process (on Windows not always at the same instant): the step runs.
        $try = 0;

        do {
            $answer = $this->updater()->step($this->admin);
        } while (($answer['busy'] ?? false) === true && ++$try < 50 && usleep(100_000) === null);

        $this->assertSame('verifying', $answer['status'], 'the other process ended: the step runs');
    }
}
