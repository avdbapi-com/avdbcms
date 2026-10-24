<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\Updater\PlanStore;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * What Tools > Updates says around a run (1.2.1, B3-B5 and the wording found in the 1.2.0 tests):
 *
 *  - B3: after an Undo, "Show details" still shows the undo's step log (kept before its work folder goes);
 *  - after an Undo, "Last change: <date>, the update to AVDBCMS X was undone" (not "Last updated ..., from AVDBCMS X",
 *    which read as if the site had been updated), also from a state.json an older version wrote; and the empty list
 *    of update backups says the undo used the last one (not "No update backups yet.");
 *  - during an Undo the panel says "Keep this page open until it says your files are back.";
 *  - B4: while the database backup has not listed its tables, "Backing up the database..." (no "table 0 of 0");
 *  - B5: the result card of the run before carries the mark the script hides it by when a new run starts.
 */
class UpdatesPageTextsTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const UNDO_KEEP = 'Keep this page open until it says your files are back.';

    private const UPDATE_KEEP = 'Keep this page open until it says the update is installed.';

    private const NO_BACKUPS_AFTER_UNDO = 'No update backups. The undo used the last one to put your files back.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2']);
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_after_an_undo_show_details_still_has_the_undos_step_log(): void
    {
        $this->undoTheUpdate();

        $lines = implode("\n", $this->updater()->logLines());
        $this->assertStringContainsString('undo of '.AppVersion::CURRENT.' -> '.$this->next.' started by owner', $lines);
        $this->assertStringContainsString("\tswap\tall ", $lines);
        $this->assertStringContainsString("\tfinish\tthe files of ".AppVersion::CURRENT.' are back', $lines);
        $this->assertDirectoryDoesNotExist($this->box->paths()->job('undo-'.$this->next), 'its work folder went');

        $html = $this->page();
        $this->assertMatchesRegularExpression('~<section[^>]*data-ad-updates-result[^>]*>.*?<pre data-ad-updates-log-lines>[^<]*the files of '.preg_quote(AppVersion::CURRENT, '~').' are back[^<]*</pre>~s', $html);
    }

    public function test_an_undo_whose_swap_was_put_back_keeps_its_step_log_too(): void
    {
        $this->updateToNext();
        $undo = $this->undoToSwap($this->updater($this->next));
        // A copy the undo needs is damaged after it was checked: the hook puts the new version's files back.
        $backup = $this->box->paths()->backups($this->storedPlan()['backup_id']);
        file_put_contents($backup.'/files/config/app.php', '<?php // damaged');

        $this->assertSame('failed', $this->swapThroughHook($undo['token'])['step']);
        $plan = $this->updater($this->next)->plan();

        $this->assertSame('failed', $plan['status']);
        $this->assertDirectoryDoesNotExist($this->box->paths()->job('undo-'.$this->next));
        $this->assertStringContainsString('every file is back as it was', implode("\n", $this->updater($this->next)->logLines()));
    }

    public function test_after_an_undo_the_page_says_the_update_was_undone_and_why_there_is_no_backup(): void
    {
        $this->undoTheUpdate();

        $html = $this->page();

        $this->assertStringContainsString('<dt>Last change</dt>', $html);
        $this->assertMatchesRegularExpression('~<dd>\d{1,2} \w+ \d{4}, \d\d:\d\d, the update to AVDBCMS '.preg_quote($this->next, '~').' was undone</dd>~', $html);
        $this->assertStringNotContainsString('<dt>Last updated</dt>', $html);
        $this->assertStringNotContainsString('from AVDBCMS '.$this->next, $html);
        $this->assertStringContainsString(self::NO_BACKUPS_AFTER_UNDO, $html);
        $this->assertStringNotContainsString('No update backups yet.', $html);
    }

    public function test_a_state_that_1_2_0_wrote_after_an_undo_reads_the_same(): void
    {
        // 1.2.0's finishUndo writes no kind: the versions tell (the one before is newer than the one installed).
        PlanStore::write($this->box->paths()->state(), ['installed_version' => AppVersion::CURRENT, 'previous_version' => $this->next,
            'updated_at' => time() - 600, 'backup_id' => null, 'admin_id' => (int) $this->admin->getKey()]);

        $html = $this->page();
        $this->assertStringContainsString('<dt>Last change</dt>', $html);
        $this->assertStringContainsString('the update to AVDBCMS '.$this->next.' was undone', $html);
        $this->assertStringContainsString(self::NO_BACKUPS_AFTER_UNDO, $html);

        // An update (the version before is older): as before.
        PlanStore::write($this->box->paths()->state(), ['installed_version' => AppVersion::CURRENT, 'previous_version' => '0.9.9',
            'updated_at' => time() - 600, 'backup_id' => null, 'admin_id' => (int) $this->admin->getKey(), 'kind' => 'update']);

        $html = $this->page();
        $this->assertStringContainsString('<dt>Last updated</dt>', $html);
        $this->assertStringContainsString(', from AVDBCMS 0.9.9', $html);
        $this->assertStringContainsString('No update backups yet.', $html);
        $this->assertStringNotContainsString(self::NO_BACKUPS_AFTER_UNDO, $html);
    }

    public function test_an_undo_in_progress_says_to_keep_the_page_open_until_the_files_are_back(): void
    {
        $this->updateToNext();
        $this->assertTrue($this->updater($this->next)->undo($this->admin)['ok']);

        $html = $this->page($this->next);

        $this->assertMatchesRegularExpression('~<p class="ad-small ad-muted" data-ad-updates-keep>'.preg_quote(self::UNDO_KEEP, '~').'</p>~', $html);
        // Both texts are in the page's boot data: the script sets the right one for a run it starts.
        $boot = json_decode((string) preg_replace('~^.*<script type="application/json" id="ad-updates-boot">(.*?)</script>.*$~s', '$1', $html), true);
        $this->assertSame([self::UPDATE_KEEP, self::UNDO_KEEP], [$boot['texts']['keep_update'], $boot['texts']['keep_undo']]);

        // An update in progress: the update's words.
        $this->updater($this->next)->cancel($this->admin);
        $this->changePlan(['kind' => 'update', 'status' => 'downloading', 'version' => '9.9.9', 'download' => ['done' => 0, 'total' => 10]]);
        $this->assertMatchesRegularExpression('~data-ad-updates-keep>'.preg_quote(self::UPDATE_KEEP, '~').'</p>~', $this->page($this->next));
    }

    public function test_the_database_backup_says_no_count_before_it_has_its_tables(): void
    {
        $this->updater()->start($this->admin);
        $answer = $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'backing-up');

        // The answer of the last unpack step: the backup has not listed its tables yet.
        $this->assertSame('Backing up the database...', $answer['label']);
        $this->assertNull($answer['progress']);

        $next = $this->updater()->step($this->admin);
        $this->assertMatchesRegularExpression('/^Backing up the database: table \d of 3$/', (string) $next['label']);
    }

    public function test_the_result_card_of_the_last_run_can_be_hidden_by_the_script(): void
    {
        $this->updateToNext();

        $html = $this->page($this->next);

        $this->assertMatchesRegularExpression('~<section class="ad-panel ad-console-block ad-upd-result" aria-labelledby="ad-upd-result-t" data-ad-updates-result>~', $html);
        $this->assertStringContainsString('AVDBCMS '.$this->next.' is installed.', $html);
    }

    /* ----------------------------------------------------------------- helpers */

    /** The update to the next version, then its undo on the new code, finished on the old one. */
    private function undoTheUpdate(): void
    {
        $this->updateToNext();
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $this->assertSame('undone', $this->finishOn($this->updater())['status']);
        $this->assertSame([], $this->updater()->backups());
    }

    /** Tools > Updates on the code of $version (the version installed by then). */
    private function page(?string $version = null): string
    {
        $this->app->instance(Updater::class, $this->updater($version));

        return (string) $this->actingAs($this->admin)->get(route('admin.updates.index'))->assertOk()->getContent();
    }
}
