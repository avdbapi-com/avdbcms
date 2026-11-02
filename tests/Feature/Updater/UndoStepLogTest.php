<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * The step log of an undo (Tools > Updates > Show details; R1B-019). When the write check refuses the undo (a folder
 * PHP may not write to, e.g. a root-owned public/build), nothing is put back, and the log must not say otherwise: it
 * said "491 files back, 273 removed, 0 left alone" - the plan, in the past tense, before the check - then "prepare
 * stopped", and after Continue the same line a second time. The plan now goes to the log once the checks let the undo
 * go ahead, worded as a plan ("to put back"); the files are put back by the swap after it.
 */
class UndoStepLogTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const PLAN = '/\tundo\t\d+ files? to put back, \d+ to remove, \d+ left alone \(changed after the update\)$/';

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

    public function test_a_refused_undo_logs_no_files_put_back_and_its_plan_once_when_it_goes_ahead(): void
    {
        $this->updateToNext();
        $installed = $this->box->snapshot();
        $new = $this->updater($this->next);

        $start = $new->undo($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);
        // The first step compares the files (it only reads); the write check comes with the step after it.
        $compared = $new->step($this->admin);
        $this->assertSame(['preparing', 'step'], [$compared['status'], $compared['next']], (string) $compared['message']);

        $folder = $this->box->root.'/public/build/assets';
        $this->denyWrites($folder);

        try {
            $refused = $new->step($this->admin);
        } finally {
            $this->allowWrites($folder);
        }

        $this->assertFalse($refused['ok']);
        $this->assertTrue($refused['retry']);
        $this->assertSame(['preparing', 'AVDBCMS cannot write to public/build/assets. Ask your host to give PHP write access to the site files, then try again.'],
            [$refused['status'], $refused['message']]);
        $this->assertSame($installed, $this->box->snapshot(), 'a refused undo puts nothing back');

        $log = $new->logLines(null, 500);
        $this->assertSame([], array_values(preg_grep('/\tundo\t.*( back,|to put back)/', $log)), 'nothing was put back, and the log says nothing of it: '.implode(' | ', $log));
        $this->assertCount(1, preg_grep('#\tprepare\tstopped: AVDBCMS cannot write to public/build/assets\.#', $log));

        // The host fixed the permissions; Continue: the files are compared again, the plan is logged once, as a plan.
        $ready = $this->stepUntil($new, fn (array $answer): bool => $answer['next'] !== 'step');
        $this->assertSame('swap', $ready['next'], (string) $ready['message']);
        $plans = array_values(preg_grep(self::PLAN, $new->logLines(null, 500)));
        $this->assertCount(1, $plans, implode(' | ', $new->logLines(null, 500)));

        // The swap puts the files back and the old code finishes the undo: the plan line is still there once, before
        // the swap's own lines, and nothing claims a second time that files went back.
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $done = $this->finishOn($this->updater());
        $this->assertSame('undone', $done['status'], (string) $done['message']);

        $final = $this->updater()->logLines(null, 500);
        $this->assertCount(1, preg_grep(self::PLAN, $final), implode(' | ', $final));
        $this->assertSame([], array_values(preg_grep('/\tundo\t\d+ files? back, /', $final)));
        $planAt = array_key_first(preg_grep(self::PLAN, $final));
        $swapAt = array_key_first(preg_grep('/\tswap\tthe site is in maintenance while \d+ files move$/', $final));
        $this->assertNotNull($swapAt, implode(' | ', $final));
        $this->assertLessThan($swapAt, $planAt, 'the plan comes before the swap that carries it out');
        $this->assertSame(AppVersion::CURRENT, $this->updater()->state()['installed_version']);
    }

    public function test_an_undo_that_goes_ahead_at_once_logs_its_plan_once(): void
    {
        $this->updateToNext();
        // The owner changed a file of the new version: the undo leaves it alone, and the plan says so.
        file_put_contents($this->box->root.'/config/app.php', "<?php return ['v' => 2, 'owner' => true];\n");

        $ready = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swap', $ready['next']);

        $plans = array_values(preg_grep(self::PLAN, $this->updater($this->next)->logLines(null, 500)));
        $this->assertCount(1, $plans);
        $this->assertStringEndsWith(', 1 left alone (changed after the update)', $plans[0]);
    }
}
