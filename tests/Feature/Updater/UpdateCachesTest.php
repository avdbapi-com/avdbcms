<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\PlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * What the new (or, after Undo, the old) code must not inherit from the version before it (F8 12, 13): Blade
 * recompiles a template only when it is newer than its compiled copy, and a move keeps the file's time, so the swap
 * deletes the compiled views and moved files get the time of the move; long-running queue workers are told to restart.
 * Both ways: an update without database changes (Finish update then clears nothing itself), and its undo.
 */
class UpdateCachesTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const RESTART = 'illuminate:queue:restart';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
        parent::tearDown();
    }

    public function test_no_compiled_view_of_the_other_version_survives_the_swap_or_its_undo(): void
    {
        $views = str_replace('\\', '/', (string) config('view.compiled'));
        $template = 'resources/views/home.blade.php';
        $ready = $this->driveToSwap();
        $this->assertSame($views, PlanStore::read($this->jobFolder().'/job.json')['views'], 'the hook knows where the compiled views are');

        // A page rendered while the update downloaded: compiled from the old template, newer than the staged new one.
        touch($this->jobFolder().'/staging/'.$template, time() - 86400);
        file_put_contents($views.'/'.sha1('home old').'.php', '<?php /* compiled from home 1 */');

        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);

        $this->assertSame([], glob($views.'/*.php'), 'the swap deleted the compiled views');
        clearstatcache();
        $this->assertGreaterThanOrEqual(time() - 60, filemtime($this->box->root.'/'.$template), 'the new template has the time of the move');

        Cache::forget(self::RESTART);
        file_put_contents($views.'/'.sha1('home new').'.php', '<?php /* compiled from home 2 */');
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        $this->assertNotNull(Cache::get(self::RESTART), 'queue:restart: long-running workers load the new code');
        $this->assertSame([], glob($views.'/*.php'), 'Finish clears the views too, also with no database change to run');

        // Undo: the old template comes back from the backup (with its old time), the views compiled from the new go.
        touch($this->box->paths()->backups($this->storedPlan()['backup_id']).'/files/'.$template, time() - 86400);
        file_put_contents($views.'/'.sha1('home new again').'.php', '<?php /* compiled from home 2 */');
        Cache::forget(self::RESTART);
        $undo = $this->undoToSwap($this->updater($this->next));

        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);

        $this->assertSame(self::shippedFiles()[$template], $this->box->read($template));
        $this->assertSame([], glob($views.'/*.php'), 'the undo deleted the compiled views of the new templates');
        clearstatcache();
        $this->assertGreaterThanOrEqual(time() - 60, filemtime($this->box->root.'/'.$template), 'the old template has the time it came back');
        $this->assertSame('undone', $this->finishOn($this->updater())['status']);
        $this->assertNotNull(Cache::get(self::RESTART));
    }
}
