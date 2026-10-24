<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\PendingMigrations;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;
use Throwable;

/**
 * What an update writes down (F8 16, 18 "Audit"): one row in Tools > Logs > Admin actions per event (update.started,
 * update.database_backup, update.files, update.finished, update.failed with its step and reason, update.undone,
 * update.cancelled with where it stopped), with the administrator who did it; the step log one line per entry; and no
 * swap token, update cookie or signed GitHub download address in any log line, stored file or admin-log row, also
 * when a dropped connection quotes the address (the text around it stays, its closing bracket too).
 */
class UpdateAuditTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    /** @var list<string> every log message of the run (with its context and exception chain) */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $text = $event->level.' '.$event->message.' '.json_encode(array_diff_key($event->context, ['exception' => 1]), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);

            for ($e = $event->context['exception'] ?? null; $e instanceof Throwable; $e = $e->getPrevious()) {
                $text .= "\n".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString();
            }

            $this->logged[] = $text;
        });
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_an_update_and_its_undo_write_one_row_per_event_with_the_administrator(): void
    {
        $this->updateToNext();
        $id = (int) $this->admin->getKey();
        $from = AppVersion::CURRENT;

        $rows = $this->auditRows();
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], array_column($rows, 'action'));
        $this->assertSame([$id, $id, $id, $id], array_column($rows, 'user_id'));
        $this->assertSame($from.' -> '.$this->next, $rows[0]['details']);
        $this->assertMatchesRegularExpression('/^backup_before_update_'.preg_quote($this->next, '/').'_\d{4}-\d\d-\d\d_\d{6}\.sql \(\d+\.\d\d MB, Pure-PHP\)$/', (string) $rows[1]['details']);
        // 7 shipped files replaced (an unchanged translation file is replaced whole), 2 added, 1 removed (the old built
        // asset stays for pages still cached in browsers).
        $this->assertMatchesRegularExpression('/^7 replaced, 2 added, 1 removed \(zip engine (ZipArchive|PharData), OPcache [a-z]+\)$/', (string) $rows[2]['details']);
        $this->assertSame($from.' -> '.$this->next.' (0 database changes)', $rows[3]['details']);

        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $this->assertSame('undone', $this->finishOn($this->updater())['status']);

        $last = array_slice($this->auditRows(), -1)[0];
        $this->assertSame(['update.undone', $this->next.' -> '.$from.' (files only)', $id], [$last['action'], $last['details'], $last['user_id']]);
        $this->assertCount(5, $this->auditRows(), 'nothing else was written');
    }

    public function test_a_failed_database_step_is_written_down_and_finish_can_be_clicked_again(): void
    {
        $ready = $this->driveToSwap();
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $broken = $this->pendingMigration('2099_03_03_000000_create_updater_audit_marker_table.php', "throw new RuntimeException('The marker table cannot be made.');");

        $failed = $this->finishOn($this->updater($this->next));

        $this->assertFalse($failed['ok']);
        $this->assertNull($failed['next']);
        $this->assertSame('swapped', $failed['status'], 'the new files stay: Home > Finish update is the way on');
        $this->assertSame('The update could not finish: RuntimeException: The marker table cannot be made. Your site keeps working. Try again, or ask your host to run "php artisan migrate".', $failed['message']);
        $this->assertSame($failed['message'], $this->storedPlan()['last_error']);
        $this->assertNotSame([], PendingMigrations::pending());
        $last = array_slice($this->auditRows(), -1)[0];
        $this->assertSame(['update.failed', 'finish: '.$failed['message'], (int) $this->admin->getKey()], [$last['action'], $last['details'], $last['user_id']]);

        // The migration is fixed (a corrected release, or the host's hand): Finish again completes the update.
        unlink($broken);
        $this->pendingMigration('2099_03_03_000001_create_updater_audit_marker_table.php', "Schema::create('updater_audit_marker', fn (Blueprint \$table) => \$table->id());");
        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(['update.started', 'update.database_backup', 'update.failed', 'update.files', 'update.finished'], array_column($this->auditRows(), 'action'));
        $this->assertStringEndsWith('(1 database changes)', (string) array_slice($this->auditRows(), -1)[0]['details']);
    }

    public function test_a_swap_the_hook_put_back_is_written_down_once_with_the_administrator(): void
    {
        $ready = $this->driveToSwap();
        // A staged file is damaged after it was checked: the hook puts every file back (it has no database).
        file_put_contents($this->jobFolder().'/staging/config/app.php', '<?php // damaged');

        $swap = $this->swapThroughHook($ready['token']);

        $this->assertSame('failed', $swap['step']);
        $this->assertSame(['update.started', 'update.database_backup'], array_column($this->auditRows(), 'action'), 'the hook writes no row itself');

        // The next look at the plan (any admin page) writes it, once.
        $this->updater()->plan();
        $this->updater()->plan();

        $last = array_slice($this->auditRows(), -1)[0];
        $this->assertSame('update.failed', $last['action']);
        $this->assertStringStartsWith('swap: The new copy of config/app.php is missing or damaged.', (string) $last['details']);
        $this->assertStringEndsWith('(files put back)', (string) $last['details']);
        $this->assertSame((int) $this->admin->getKey(), $last['user_id']);
        $this->assertCount(3, $this->auditRows());
        $this->assertSiteUnchanged('after the hook put the files back');
    }

    public function test_no_token_cookie_or_signed_download_address_reaches_a_log_or_a_stored_file(): void
    {
        // The first try of the zip drops: the client's error quotes the full signed address of the asset store.
        $this->github->dropRequests = [1];
        $start = $this->updater()->start($this->admin);
        $this->assertTrue($start['ok']);
        $dropped = $this->updater()->step($this->admin);
        $this->assertTrue($dropped['retry'], (string) $dropped['message']);
        $ready = $this->stepUntil($this->updater(), fn (array $a): bool => $a['next'] !== 'step') + ['cookie' => $start['cookie']];
        $this->assertSame('swap', $ready['next']);

        // The administrator's own hook page (the update cookie), then the swap and Finish.
        $page = $this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'text/html'], [], [Updater::COOKIE => $ready['cookie']]);
        $this->assertSame(200, $page['status']);
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        $secrets = [$ready['token'], $ready['cookie'], ReleaseServer::SIGNATURE, 'github-production-release-asset', 'sp=r&sv='];
        $this->assertNoSecretIn($secrets, 'after the update');

        // The dropped connection is in the step log, without its address.
        $log = (string) file_get_contents($this->box->paths()->backups($this->storedPlan()['backup_id']).'/update.log');
        $this->assertStringContainsString('The download from GitHub was interrupted. Try again: it continues where it stopped. [connection: cURL error 56: Recv failure: Connection was reset for <address>]', $log);
        $this->assertStringContainsString("download\tdownloading ".$this->github->zipName().' from github.com', $log);

        // Undo: a new token and a new cookie, the same rule.
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $this->assertSame('undone', $this->finishOn($this->updater())['status']);
        $this->assertNoSecretIn([...$secrets, $undo['token'], $undo['cookie']], 'after the undo');
    }

    public function test_a_cancelled_update_or_undo_is_written_down_with_where_it_stopped(): void
    {
        $id = (int) $this->admin->getKey();
        $this->updater()->start($this->admin);
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);

        $this->assertSame('cancelled', $this->updater()->cancel($this->admin)['status']);

        $this->assertSame(['update.started', 'update.cancelled'], array_column($this->auditRows(), 'action'));
        $this->assertSame([AppVersion::CURRENT.' -> '.$this->next.' (at verifying)', $id], [$this->auditRows()[1]['details'], $this->auditRows()[1]['user_id']]);

        // An undo called off while it compares the files, by another administrator once it stalled.
        $this->updateToNext();
        $new = $this->updater($this->next);
        $this->assertTrue($new->undo($this->admin)['ok']);
        $this->changePlan(['step_at' => time() - Updater::STALL_SECONDS - 1]);
        $second = $this->otherAdmin();

        $this->assertSame('cancelled', $new->cancel($second)['status']);

        $last = array_slice($this->auditRows(), -1)[0];
        $this->assertSame(['update.cancelled', $this->next.' -> '.AppVersion::CURRENT.' (undo, at preparing)', (int) $second->getKey()], [$last['action'], $last['details'], $last['user_id']]);
        $this->assertSame(2, array_count_values(array_column($this->auditRows(), 'action'))['update.cancelled'], 'one row per cancel');
    }

    public function test_an_address_in_a_connection_error_is_hidden_and_the_text_around_it_stays(): void
    {
        // curl's own wording: the address of its error page in brackets, then the signed address of the asset store.
        $this->github->dropRequests = [1];
        $this->github->dropMessage = 'cURL error 18: end of response with 8388608 bytes missing (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for :url';
        $this->assertTrue($this->updater()->start($this->admin)['ok']);

        $dropped = $this->updater()->step($this->admin);

        $this->assertTrue($dropped['retry']);
        $log = implode("\n", $this->updater()->logLines());
        $this->assertStringContainsString('[connection: cURL error 18: end of response with 8388608 bytes missing (see <address>) for <address>]', $log);
        $this->assertStringNotContainsString(ReleaseServer::SIGNATURE, $log);
        $this->assertStringNotContainsString('curl.haxx.se', $log);
    }

    public function test_each_step_log_entry_is_one_line_of_time_step_and_message(): void
    {
        // An administrator name that tries to start lines of its own in the log.
        $this->admin->forceFill(['username' => "owner\nINJECTED\tline\r"])->save();

        $this->updateToNext();

        $lines = preg_split('/\n/', rtrim((string) file_get_contents($this->box->paths()->backups($this->storedPlan()['backup_id']).'/update.log'), "\n"));
        $this->assertGreaterThan(10, count($lines));

        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\t[a-z]+\t[^\t\r]*$/', $line);
        }

        $this->assertStringContainsString('started by owner INJECTED line ', implode("\n", $lines));
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * None of $secrets is in a file under the site's storage/ (plan, state, step logs, backup.json, journal, the
     * dump), an admin-log row or a log message of this run.
     *
     * @param  list<string>  $secrets
     */
    private function assertNoSecretIn(array $secrets, string $when): void
    {
        $texts = [];
        $storage = $this->box->root.'/storage';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $texts[substr(str_replace('\\', '/', $file->getPathname()), strlen($storage) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        $this->assertArrayHasKey('app/updates/plan.json', $texts);
        $texts['admin log'] = (string) json_encode(DB::table('admin_audit_logs')->get(), JSON_UNESCAPED_SLASHES);
        $texts['log messages'] = implode("\n", $this->logged);

        foreach ($texts as $where => $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, $when.': '.$where.' holds a secret');
            }
        }
    }

    /** A migration of "the new version" in a folder of its own; the path of its file. */
    private function pendingMigration(string $name, string $up): string
    {
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        File::put($folder.'/'.$name, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
            ."use Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        {$up}\n    }\n};\n");
        app('migrator')->path($folder);
        PendingMigrations::forget();

        return $folder.'/'.$name;
    }
}
