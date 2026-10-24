<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\Updater\Manifest;
use App\Support\Updater\PlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Updater\Support\Updater121;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * What passes between 1.2.1 and this version in a one-click update (1.2.2): the owner's sites run 1.2.1, whose own
 * updater (Updater121: the files it shipped, byte for byte) runs every step up to the swap, its maintenance hook too;
 * Finish is this version's. Undo goes the other way: this version's steps and hook, then 1.2.1's Finish.
 *
 *  - this Finish takes the plan, the work folder and state.json exactly as 1.2.1 left them: the update completes, every
 *    admin-log row once, the work folder leaves in one rename;
 *  - a swap of 1.2.1 that reported OPcache "stale" is cleared again by this Finish before it completes (H7);
 *  - an Undo prepared by this version (its hook, which can stand by after its token ran out) is finished by 1.2.1:
 *    every file back byte for byte.
 */
class UpdateFrom121Test extends TestCase
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

    public function test_finish_takes_what_the_1_2_1_steps_left(): void
    {
        $old = Updater121::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame(Updater121::name('Updater'), get_class($old));
        $ready = $this->oldToSwap($old);

        // The maintenance hook is 1.2.1's (its namespace is the frozen one), without the standing-by of this version.
        $hook = (string) file_get_contents($this->box->paths()->hook());
        $this->assertStringContainsString('function stamp(', $hook);
        $this->assertStringNotContainsString('function standingBy(', $hook);
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);

        $plan = $this->storedPlan();
        $job = $this->jobFolder();
        $this->assertSame('swapped', $plan['status']);
        $this->assertSame([], array_intersect(array_keys($plan), ['finish_steps', 'audit_begun', 'audit_rows', 'migrations', 'lapsed_at']), 'nothing of Finish in the plan yet');
        $this->assertDirectoryExists($job.'/staging');

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame(['ok' => true, 'status' => 'done', 'next' => 'reload'], array_intersect_key($done, ['ok' => 1, 'status' => 1, 'next' => 1]));
        $this->assertSame(self::releaseFiles()['config/app.php'], $this->box->read('config/app.php'));
        $this->assertNull($this->box->read('app/Support/Old.php'));
        $this->assertSame($this->next, Manifest::installed($this->box->paths()->manifest())->version);
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], array_column($this->auditRows(), 'action'));
        $state = $this->updater($this->next)->state();
        $this->assertSame([$this->next, AppVersion::CURRENT, $plan['backup_id'], 'update'], [$state['installed_version'], $state['previous_version'], $state['backup_id'], $state['kind']]);
        $this->assertSame('complete', PlanStore::read($this->box->paths()->backups($plan['backup_id']).'/backup.json')['status']);
        $this->assertDirectoryDoesNotExist($job, 'the work folder left in one rename');
        $this->assertCount(1, glob($this->box->paths()->trash($this->next.'-*'), GLOB_ONLYDIR) ?: []);
        $this->assertTrue($this->updater($this->next)->backups()[0]['undo'], 'Undo this update is offered');
    }

    public function test_finish_clears_opcache_again_when_the_1_2_1_swap_said_it_was_stale(): void
    {
        $old = Updater121::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $this->changePlan(['settle' => array_replace((array) $this->storedPlan()['settle'], ['opcache' => 'stale', 'wait' => 0])]);

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertNotSame('stale', $this->storedPlan()['settle']['opcache']);
        $this->assertStringContainsString('OPcache was stale after the swap; cleared again', implode("\n", $this->updater($this->next)->logLines(null, 500)));
    }

    public function test_an_undo_prepared_by_this_version_is_finished_by_1_2_1(): void
    {
        $old = Updater121::updater($this->box->paths(), $this->database, AppVersion::CURRENT);
        $this->assertSame('swapped', $this->swapThroughHook($this->oldToSwap($old)['token'])['step']);
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        // This version's undo: its steps and hook (the one that can stand by), then 1.2.1 finishes it.
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertStringContainsString('function standingBy(', (string) file_get_contents($this->box->paths()->hook()));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);

        $answer = ['next' => 'finish'];

        for ($i = 0; $i < 10 && $answer['next'] === 'finish'; $i++) {
            $answer = $old->finish($this->admin);
        }

        $this->assertSame('undone', $answer['status'], (string) ($answer['message'] ?? ''));
        $before = $this->before;
        unset($before['bootstrap/cache/config.php'], $before['bootstrap/cache/packages.php']);
        $this->assertSame($before, $this->box->snapshot(), 'every file as before the update');
        $this->assertSame(AppVersion::CURRENT, Manifest::installed($this->box->paths()->manifest())->version);
        $state = $old->state();
        $this->assertSame([AppVersion::CURRENT, $this->next, 'undo'], [$state['installed_version'], $state['previous_version'], $state['kind']]);
        $this->assertContains('update.undone', array_column($this->auditRows(), 'action'));
    }

    /**
     * 1.2.1's start + steps until the swap is ready (the answer with the swap token), as its page walks them.
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

        $this->fail('1.2.1 did not get to the swap in 40 steps.');
    }
}
