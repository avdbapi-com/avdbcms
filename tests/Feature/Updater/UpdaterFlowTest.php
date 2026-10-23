<?php

namespace Tests\Feature\Updater;

use App\Models\User;
use App\Support\AppVersion;
use App\Support\PendingMigrations;
use App\Support\Updater\Downloader;
use App\Support\Updater\Manifest;
use App\Support\Updater\MaintenanceHook;
use App\Support\Updater\PlanStore;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\SwapEngine;
use App\Support\Updater\TranslationMerge;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Updater\Support\FakeDatabaseBackup;
use Tests\Feature\Updater\Support\FakeGitHub;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The whole one-click update on a throwaway site root (F8 0, 18): GitHub faked, the database dump faked, the swap run
 * through the generated maintenance hook exactly as public/index.php would hand it a request (handle($server, $post,
 * $cookies)), Finish on "the new code" (an Updater that plays the next version, built from AppVersion::CURRENT).
 *
 *  - happy path, both zip engines: only changed files move; the backup holds the old ones and backup.json; site data,
 *    .env, uploads, bin/ and a custom language are untouched; bootstrap/cache cleared; the maintenance files gone;
 *    state.json written; the pending migration ran;
 *  - a failure at file N of the swap puts the tree back byte for byte;
 *  - a swap killed half-way goes on through the token (and Undo from the administrator's hook page puts it back);
 *  - Undo after Finish brings the old files back and leaves a file changed after the update alone (listed).
 */
class UpdaterFlowTest extends TestCase
{
    use RefreshDatabase;

    private UpdaterSandbox $box;

    private FakeDatabaseBackup $database;

    private FakeGitHub $github;

    private User $admin;

    private string $next;

    /** @var array<string, string> the site's files right before the update (path => SHA-256, without storage/) */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true, 'app.url' => 'http://localhost']);
        $this->box = new UpdaterSandbox();
        $this->next = UpdaterSandbox::nextVersion();

        // Finish runs `view:clear`: keep it away from the copy's own compiled views.
        File::ensureDirectoryExists($this->box->path('views'));
        config(['view.compiled' => $this->box->path('views')]);

        $this->box->install(AppVersion::CURRENT, $this->shipped(), self::DATA);
        $this->box->write($this->ownerEdits());
        // Every live file gets an old time stamp: a file that never moved still has it after the update.
        $this->age($this->box->root);
        $this->before = $this->box->snapshot();

        $this->github = (new FakeGitHub($this->next, $this->box->releaseZip($this->next, $this->release(), self::REMOVED)))->install();
        $this->database = new FakeDatabaseBackup($this->box->root.'/storage/app/backups');
        $this->admin = User::factory()->admin()->create(['username' => 'owner']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['avdbcms_update_hook_probe']);
        $this->box->delete();
        parent::tearDown();
    }

    public static function engines(): array
    {
        return ['ZipArchive' => ['ZipArchive'], 'PharData' => ['PharData']];
    }

    #[DataProvider('engines')]
    public function test_an_update_replaces_only_what_changed_and_never_touches_the_site_data(string $engine): void
    {
        if ($engine === 'ZipArchive' && ! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('This PHP has no zip extension; the PharData run covers the unpack.');
        }

        $old = $this->updater();
        $ready = $this->toSwap($old, $engine);

        // Before the swap: nothing live changed but the new built assets and the copy next to the kept .htaccess.
        $this->assertFileExists($this->box->paths()->hook());
        $this->assertFileExists($this->box->paths()->down());
        $this->assertSame("js 2\n", $this->box->read('public/build/assets/app-222.js'));
        $this->assertSame($this->release()['public/.htaccess'], $this->box->read('public/.htaccess.avdbcms-'.$this->next));
        $this->assertSame($this->shipped()['config/app.php'], $this->box->read('config/app.php'));
        $plan = $old->plan();
        $this->assertSame('swapping', $plan['status']);
        $this->assertSame($engine, $plan['engine']);
        $this->assertSame(['name' => $plan['database_backup']['name'], 'size' => $plan['database_backup']['size'], 'method' => 'Pure-PHP'], $plan['database_backup']);
        $this->assertStringStartsWith('backup_before_update_'.$this->next.'_', $plan['database_backup']['name']);
        $this->assertSame(hash('sha256', $ready['token']), $plan['swap']['token_hash'], 'only the hash of the token is kept');
        $this->assertStringNotContainsString($ready['token'], (string) file_get_contents($this->box->paths()->plan()));
        $this->assertStringNotContainsString($ready['cookie'], (string) file_get_contents($this->box->paths()->hook()));

        // A visitor meanwhile.
        $visitor = $this->hook()->handle(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost'], [], []);
        $this->assertSame(503, $visitor['status']);
        $this->assertSame('60', $visitor['headers']['Retry-After']);

        $swap = $this->swapThroughHook($ready['token']);

        $this->assertSame(['ok' => true, 'step' => 'swapped', 'next' => 'finish'], array_intersect_key($swap, ['ok' => 1, 'step' => 1, 'next' => 1]));
        $this->assertSame($swap['total'], $swap['done']);
        $this->assertSame(0, $swap['wait']);
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());
        $this->assertFileDoesNotExist($this->box->root.'/bootstrap/cache/config.php');
        $this->assertFileDoesNotExist($this->box->root.'/bootstrap/cache/packages.php');
        $this->assertFileExists($this->box->root.'/bootstrap/cache/.gitignore');

        // The swap was the last thing the old version did; the new code (the next version) finishes.
        $this->addPendingMigration();
        $this->assertNotSame([], PendingMigrations::pending());
        $new = $this->updater($this->next);
        $done = $this->finish($new);

        $this->assertTrue($done['ok']);
        $this->assertSame('done', $done['status']);
        $this->assertSame('reload', $done['next']);
        $this->assertSame('AVDBCMS '.$this->next.' is installed.', $done['message']);
        $this->assertTrue(Schema::hasTable('updater_flow_marker'), 'the pending migration ran');
        $this->assertSame([], PendingMigrations::pending(), 'Home has no "Finish the update" any more');

        // The tree: exactly the release where it changed, the owner's files and the site data where they belong.
        $this->assertSame($this->expectedAfter(), $this->box->snapshot());
        $this->assertNeverMoved(['index.php', 'app/Http/Kernel.php', 'routes/web.php', 'lang/vi/auth.php', 'database/migrations/2026_01_01_000000_create_things.php',
            'public/.htaccess', 'public/build/assets/app-111.js', 'public/build/assets/app-111.css', 'bootstrap/cache/.gitignore', ...array_keys($this->withoutCompiledCaches(self::DATA))]);
        $this->assertSame(self::DATA['storage/app/installed.json'], $this->box->read('storage/app/installed.json'));
        $this->assertSame(self::DATA['storage/app/public/x.jpg'], $this->box->read('storage/app/public/x.jpg'));

        // The merged translations: the owner's text, plus the release's new key.
        $this->assertSame(['Bye' => 'Tạm biệt', 'Hello' => 'Chào bạn', 'New' => 'Mới', 'Old key' => 'Khóa cũ'], TranslationMerge::decode((string) $this->box->read('lang/vi.json')));

        // The report the page shows.
        $plan = $new->plan();
        $this->assertSame(10, $plan['report']['replaced']);
        $this->assertSame(4, $plan['report']['added']);
        $this->assertSame(2, $plan['report']['removed']);
        $this->assertSame(6, $plan['report']['unchanged']);
        $this->assertSame([['path' => 'public/.htaccess', 'copy' => 'public/.htaccess.avdbcms-'.$this->next]], $plan['report']['kept']);
        $this->assertSame([['path' => 'lang/vi.json', 'added' => 1, 'kept' => 1]], $plan['report']['merged']);
        $this->assertSame(['app/Models/User.php'], $plan['report']['overwritten']);
        $this->assertArrayNotHasKey('swap', $plan);

        // The file backup: the old copies of what moved, backup.json, the journal and the step log.
        $backups = $new->backups();
        $this->assertCount(1, $backups);
        $this->assertSame(AppVersion::CURRENT, $backups[0]['from']);
        $this->assertSame($this->next, $backups[0]['to']);
        $this->assertTrue($backups[0]['undo']);
        $this->assertSame($plan['database_backup']['name'], $backups[0]['database_backup']);
        $folder = $this->box->paths()->backups($backups[0]['id']);
        $this->assertSame($backups[0]['id'], $plan['backup_id']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+-to-\d+\.\d+\.\d+-\d{14}$/', $backups[0]['id']);
        $this->assertSame($this->expectedBackup(), $this->box->snapshot([], $folder.'/files'));
        $this->assertFileExists($folder.'/journal.jsonl');
        $this->assertFileExists($folder.'/update.log');
        $backup = PlanStore::read($folder.'/backup.json');
        $this->assertSame('complete', $backup['status']);
        $this->assertSame(array_sum(array_map(fn (string $path): int => (int) filesize($folder.'/files/'.$path), array_keys($this->expectedBackup()))), $backup['size']);
        $actions = array_count_values(array_column($backup['entries'], 'action'));
        $this->assertSame(['added' => 4, 'merged' => 1, 'removed' => 2, 'replaced' => 11, 'sibling' => 1], $this->sorted($actions), 'replaced = 10 files + the manifest');

        // The work files are gone; state.json says what is installed.
        $this->assertDirectoryDoesNotExist($this->box->paths()->job($this->next));
        $state = $new->state();
        $this->assertSame($this->next, $state['installed_version']);
        $this->assertSame(AppVersion::CURRENT, $state['previous_version']);
        $this->assertSame($backups[0]['id'], $state['backup_id']);
        $this->assertSame((int) $this->admin->getKey(), $state['admin_id']);
        $this->assertSame(['public/build/assets/app-111.css', 'public/build/assets/app-111.js'], $state['kept_assets']);
        $this->assertEqualsWithDelta(time(), $state['updated_at'], 60);

        // The admin log and the step log.
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], $this->audit());
        $log = implode("\n", $new->logLines(null, 200));
        $this->assertStringContainsString('started by owner', $log);
        $this->assertStringContainsString('unpacking with '.$engine, $log);
        $this->assertStringContainsString('is installed', $log);
        $this->assertStringNotContainsString($ready['token'], $log);
        $this->assertStringNotContainsString('release-assets', $log);

        // The swap address once more (a retry of the page): nothing changes.
        $after = $this->box->snapshot();
        $again = $new->swap($this->admin);
        $this->assertFalse($again['ok']);
        $this->assertNull($again['next']);
        $this->assertSame($after, $this->box->snapshot());
    }

    public function test_a_failure_at_file_n_of_the_swap_puts_the_tree_back_byte_for_byte(): void
    {
        $old = $this->updater();
        $ready = $this->toSwap($old);
        $job = $this->box->paths()->job($this->next);
        $ops = (new SwapEngine((array) PlanStore::read($job.'/job.json')))->ops();
        $n = array_search('config/app.php', array_column($ops, 'path'), true);
        $this->assertGreaterThan(5, $n, 'files before it move first');

        // The staged copy of file N is damaged after it was checked (a disk error, another process).
        file_put_contents($job.'/staging/config/app.php', '<?php // damaged');

        $swap = $this->swapThroughHook($ready['token']);

        $this->assertFalse($swap['ok']);
        $this->assertSame('failed', $swap['step']);
        $this->assertNull($swap['next']);
        $this->assertSame('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.', $swap['message']);
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());
        $this->assertSame($this->withoutCompiledCaches($this->before), $this->box->snapshot(), 'byte-identical (the compiled caches are only cleared)');
        $this->assertNeverMoved(['config/app.php', 'index.php', ...array_keys($this->withoutCompiledCaches(self::DATA))]);

        // The next look at the plan (any admin page) writes the audit row the hook could not, and clears the work.
        $plan = $old->plan();
        $this->assertSame('failed', $plan['status']);
        $this->assertTrue($plan['rolled_back']);
        $this->assertSame('swap', $plan['failure']['step']);
        $this->assertStringContainsString('config/app.php is missing or damaged', $plan['failure']['reason']);
        $this->assertSame('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.', Updater::label($plan));
        $this->assertSame(['update.started', 'update.database_backup', 'update.failed'], $this->audit());
        $this->assertStringContainsString('(files put back)', (string) DB::table('admin_audit_logs')->where('action', 'update.failed')->value('details'));
        $this->assertSame([], $old->backups());
        $this->assertDirectoryDoesNotExist($job.'/staging');
        $this->assertFileExists($job.'/'.AppVersion::updateAsset($this->next), 'the checked zip stays for a new try');
        $this->assertFileExists($job.'/update.log');

        // A new try starts clean and reuses the zip (no second download).
        $downloads = count(array_filter($this->github->requests, fn (array $r): bool => str_ends_with($r['url'], '.zip')));
        $retry = $old->start($this->admin);
        $this->assertTrue($retry['ok']);
        $this->assertSame('verifying', $retry['status']);
        $this->assertSame($downloads, count(array_filter($this->github->requests, fn (array $r): bool => str_ends_with($r['url'], '.zip'))));
    }

    public function test_a_swap_killed_half_way_goes_on_through_the_token(): void
    {
        $old = $this->updater();
        $ready = $this->toSwap($old);
        $this->killSwapHalfWay();

        $swap = $this->swapThroughHook($ready['token']);

        $this->assertSame('swapped', $swap['step']);
        $done = $this->finish($this->updater($this->next));
        $this->assertSame('done', $done['status']);
        $this->assertSame($this->expectedAfter(), $this->box->snapshot(), 'the same tree as an update that never stopped');
    }

    public function test_the_administrators_hook_page_finishes_or_undoes_a_stopped_swap(): void
    {
        $old = $this->updater();
        $ready = $this->toSwap($old);
        $this->killSwapHalfWay();
        $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost', 'HTTP_ACCEPT' => 'text/html'];
        $cookies = [Updater::COOKIE => $ready['cookie']];

        // Without the cookie: the visitors' page. With it: what happened, and Finish / Undo.
        $this->assertSame(503, $this->hook()->handle($server, [], [])['status']);
        $page = $this->hook()->handle($server, [], $cookies);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('The update stopped before it finished.', $page['body']);
        $this->assertStringContainsString('Finish the update', $page['body']);
        $this->assertStringContainsString('Undo the update', $page['body']);
        $this->assertStringContainsString('href="'.AppVersion::HELP_URL.'"', $page['body']);
        $this->assertStringNotContainsString($ready['cookie'], $page['body']);
        preg_match('/name="form_token" value="([0-9a-f]{64})"/', $page['body'], $match);
        $this->assertNotEmpty($match);

        // The Updates page polling for JSON (it lost its token) is told to reload.
        $json = $this->hook()->handle(['HTTP_ACCEPT' => 'application/json'] + $server, [], $cookies);
        $this->assertSame('reload', json_decode($json['body'], true)['next']);

        // A forged form (no Origin of this site, or a wrong form token) changes nothing.
        $post = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'localhost'];
        $snapshot = $this->box->snapshot();
        $this->hook()->handle($post + ['HTTP_ORIGIN' => 'https://evil.example'], ['avdbcms_update' => 'undo', 'form_token' => $match[1]], $cookies);
        $this->hook()->handle($post + ['HTTP_ORIGIN' => 'http://localhost'], ['avdbcms_update' => 'undo', 'form_token' => str_repeat('0', 64)], $cookies);
        $this->assertSame($snapshot, $this->box->snapshot());
        $this->assertSame('swapping', $old->plan()['status']);

        // Undo: every file goes back.
        $undo = $this->hook()->handle($post + ['HTTP_ORIGIN' => 'http://localhost'], ['avdbcms_update' => 'undo', 'form_token' => $match[1]], $cookies);

        $this->assertSame(200, $undo['status']);
        $this->assertStringContainsString('The update was stopped', $undo['body']);
        $this->assertSame($this->withoutCompiledCaches($this->before), $this->box->snapshot());
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $plan = $old->plan();
        $this->assertSame('failed', $plan['status']);
        $this->assertSame(MaintenanceHook::STOPPED, $plan['failure']['reason']);
    }

    public function test_undo_after_finish_brings_the_old_files_back_and_leaves_later_changes_alone(): void
    {
        $this->updateToNext();
        $marker = Schema::hasTable('updater_flow_marker');
        // The owner changes a file of the new version after the update.
        file_put_contents($this->box->root.'/config/app.php', "<?php return ['v' => 2, 'owner' => true];\n");

        $new = $this->updater($this->next);
        $start = $new->undo($this->admin);

        // The undo hands out the cookie first; its steps compare the files, then make the swap ready.
        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertSame(['preparing', 'step'], [$start['status'], $start['next']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $start['cookie'] ?? '');
        $undo = $this->stepUntil($new, fn (array $a): bool => $a['next'] !== 'step');

        $this->assertTrue($undo['ok'], (string) $undo['message']);
        $this->assertSame('swap', $undo['next']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $undo['token']);
        $this->assertArrayNotHasKey('cookie', $undo);
        $plan = $new->plan();
        $this->assertSame('undo', $plan['kind']);
        $this->assertSame(AppVersion::CURRENT, $plan['version']);
        $this->assertSame($this->next, $plan['from']);
        $this->assertSame(['config/app.php'], $plan['report']['kept']);

        $swap = $this->swapThroughHook($undo['token']);
        $this->assertSame('swapped', $swap['step']);
        // The old code runs again and finishes the undo.
        $done = $this->finish($this->updater());

        $this->assertSame('undone', $done['status']);
        $this->assertSame('Your files are back to AVDBCMS '.AppVersion::CURRENT.'. The database stays as it is now.', $done['message']);
        $expected = $this->withoutCompiledCaches($this->before);
        $expected['config/app.php'] = hash('sha256', "<?php return ['v' => 2, 'owner' => true];\n");
        $this->assertSame($expected, $this->box->snapshot(), 'every file as before the update, but the one changed after it');
        $this->assertSame($marker, Schema::hasTable('updater_flow_marker'), 'the database is not rolled back');
        $state = $this->updater()->state();
        $this->assertSame(AppVersion::CURRENT, $state['installed_version']);
        $this->assertNull($state['backup_id']);
        $this->assertSame([], $this->updater()->backups(), 'the backup went back into the site');
        $this->assertContains('update.undone', $this->audit());
        $this->assertFileDoesNotExist($this->box->paths()->hook());

        // The file the undo left alone: its copy from before the update is the only one there is, so it stays (and only
        // it); the folder is named on the page and goes after the next finished update.
        $folder = $this->updater()->plan()['kept_backup'] ?? null;
        $this->assertIsString($folder);
        $kept = $this->box->paths()->backups($folder);
        $this->assertSame(['config/app.php' => $this->before['config/app.php']], $this->box->snapshot([], $kept.'/files'));
        $backup = PlanStore::read($kept.'/backup.json');
        $this->assertSame(['undone', ['config/app.php']], [$backup['status'], $backup['kept']]);
    }

    public function test_a_database_backup_that_fails_half_way_starts_again_from_its_first_table(): void
    {
        $old = $this->updater();
        $old->start($this->admin);
        $this->database->failOnTable = 1;

        $answer = $this->stepUntil($old, fn (array $a): bool => ($a['retry'] ?? false) === true);

        $this->assertSame('backing-up', $answer['status']);
        $this->assertStringStartsWith('The database backup did not work: Lost connection', $answer['message']);
        $this->assertArrayNotHasKey('db', $old->plan(), 'the half dump is not continued');
        $this->assertSame([], glob($this->box->root.'/storage/app/backups/*.sql'), 'and its file is gone');

        $this->database->failOnTable = null;
        $ready = $this->stepUntil($old, fn (array $a): bool => $a['next'] !== 'step');

        $this->assertSame('swap', $ready['next']);
        $dumps = glob($this->box->root.'/storage/app/backups/*.sql');
        $this->assertCount(1, $dumps);
        $dump = (string) file_get_contents($dumps[0]);
        $this->assertStringStartsWith('-- AVDBCMS test dump', $dump);
        $this->assertSame(3, substr_count($dump, 'DROP TABLE IF EXISTS'), 'all three tables, once each');
        $this->assertStringEndsWith(FakeDatabaseBackup::FOOTER."\n", $dump);
    }

    public function test_a_hook_that_went_missing_in_the_swap_is_written_again_with_a_new_token(): void
    {
        $old = $this->updater();
        $ready = $this->toSwap($old);
        $this->killSwapHalfWay();
        // Somebody deleted the maintenance files: the page's next swap POST reaches Laravel's swap route.
        unlink($this->box->paths()->hook());
        unlink($this->box->paths()->down());

        $again = $old->swap($this->admin);

        $this->assertTrue($again['ok']);
        $this->assertSame('swap', $again['next']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $again['token']);
        $this->assertNotSame($ready['token'], $again['token']);
        $this->assertArrayNotHasKey('cookie', $again, 'the update cookie stays the same');
        $this->assertFileExists($this->box->paths()->down());

        // The old token is no good any more; the new one finishes the swap.
        $stale = $this->hook()->handle(['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost'],
            ['avdbcms_update' => 'swap', 'token' => $ready['token']], []);
        $this->assertSame(503, $stale['status']);
        $this->assertSame('swapped', $this->swapThroughHook($again['token'])['step']);
        $this->assertSame('done', $this->finish($this->updater($this->next))['status']);
        $this->assertSame($this->expectedAfter(), $this->box->snapshot());
    }

    public function test_an_update_cancelled_before_its_swap_leaves_the_site_as_it_was(): void
    {
        $stale = $this->box->paths()->job('0.9.9');
        File::ensureDirectoryExists($stale);
        File::put($stale.'/avdbcms-update-0.9.9.zip', 'a zip of a try long ago');
        $old = $this->updater();
        $old->start($this->admin);
        $this->assertDirectoryDoesNotExist($stale, 'the work folder of another version is pruned');
        $preparing = $this->stepUntil($old, fn (array $a): bool => $a['status'] === 'preparing');
        $this->assertSame('step', $preparing['next']);

        $cancel = $old->cancel($this->admin);

        $this->assertTrue($cancel['ok']);
        $this->assertSame('cancelled', $cancel['status']);
        $this->assertSame('reload', $cancel['next']);
        $this->assertSame('The update was cancelled. Nothing on your site was changed.', $cancel['message']);
        $this->assertSame($this->before, $this->box->snapshot());
        $job = $this->box->paths()->job($this->next);
        $this->assertSame(['update.log'], array_values(array_diff(scandir($job), ['.', '..'])), 'only the step log stays');
        $this->assertCount(1, glob($this->box->root.'/storage/app/backups/backup_before_update_*.sql'), 'the finished database backup stays in Tools > Backups');
        $this->assertFalse(Updater::active($old->plan()));

        // Nothing to cancel any more.
        $this->assertFalse($old->cancel($this->admin)['ok']);
    }

    public function test_a_site_without_an_installed_manifest_is_sent_the_manual_way(): void
    {
        unlink($this->box->root.'/'.Manifest::FILE);

        $answer = $this->updater()->start($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame('This site has no list of its AVDBCMS files (avdbcms-manifest.json), so it cannot be updated from here. Upload the update zip with your file manager, then click Finish update on Home.', $answer['message']);
        $this->assertArrayHasKey('label', $answer);
        $this->assertArrayHasKey('progress', $answer);
        $this->assertNull($this->updater()->plan());
    }

    /* ------------------------------------------------------------------ the site */

    /** Site data no release ships: never written, deleted or backed up. */
    private const DATA = [
        '.env' => "APP_KEY=base64:c2VjcmV0IGtleSBmb3IgdGVzdHM=\nDB_PASSWORD=not-a-real-one\n",
        '.user.ini' => "memory_limit=512M\n",
        'storage/app/installed.json' => '{"installed_at":"2026-10-23T12:00:00Z"}',
        'storage/app/public/x.jpg' => 'jpeg bytes',
        'public/uploads/x' => 'an upload',
        'lang/xx.json' => '{"Hello": "Custom language"}',
        'bin/ffmpeg' => 'ffmpeg binary',
        'database/install/my-own-backup.sql' => '-- the owner keeps a backup here',
        'bootstrap/cache/config.php' => "<?php return ['cached' => true];\n",
        'bootstrap/cache/packages.php' => "<?php return [];\n",
    ];

    /** What the release build lists as removed (paths of the installed version it no longer has). */
    private const REMOVED = ['app/Support/Old.php', 'vendor/pkg/src/Gone.php', 'public/build/assets/app-111.css', 'public/build/assets/app-111.js'];

    /** @return array<string, string> the files of the installed version (CURRENT) */
    private function shipped(): array
    {
        return [
            '.htaccess' => "Options -Indexes\nRewriteEngine On\n",
            'public/.htaccess' => "RewriteEngine On\nRewriteRule ^ index.php [L]\n",
            'public/index.php' => "<?php // public/index.php 1\n",
            'index.php' => "<?php // index.php 1\n",
            'artisan' => "#!/usr/bin/env php\n<?php // artisan 1\n",
            'app/Models/User.php' => "<?php // User 1\n",
            'app/Http/Kernel.php' => "<?php // Kernel 1\n",
            'app/Support/Old.php' => "<?php // Old 1\n",
            'config/app.php' => "<?php return ['v' => 1];\n",
            'routes/web.php' => "<?php // routes 1\n",
            'vendor/autoload.php' => "<?php // autoload 1\n",
            'vendor/pkg/src/A.php' => "<?php // A 1\n",
            'vendor/pkg/src/Gone.php' => "<?php // Gone 1\n",
            'public/build/manifest.json' => '{"app.js":"assets/app-111.js"}',
            'public/build/assets/app-111.js' => "js 1\n",
            'public/build/assets/app-111.css' => "css 1\n",
            'lang/en.json' => TranslationMerge::encode(['Hello' => 'Hello', 'Bye' => 'Bye']),
            'lang/vi.json' => TranslationMerge::encode(['Hello' => 'Xin chào', 'Bye' => 'Tạm biệt', 'Old key' => 'Khóa cũ']),
            'lang/vi/auth.php' => "<?php return ['failed' => 'Sai'];\n",
            'database/migrations/2026_01_01_000000_create_things.php' => "<?php // m1\n",
            'resources/views/home.blade.php' => "home 1\n",
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        ];
    }

    /** @return array<string, string> what the owner changed after installing */
    private function ownerEdits(): array
    {
        return [
            'public/.htaccess' => $this->shipped()['public/.htaccess']."# added by the host panel\n",
            'lang/vi.json' => TranslationMerge::encode(['Hello' => 'Chào bạn', 'Bye' => 'Tạm biệt', 'Old key' => 'Khóa cũ']),
            'app/Models/User.php' => "<?php // User 1 + a fix of the owner\n",
        ];
    }

    /** @return array<string, string> the files of the release (the next version) */
    private function release(): array
    {
        $shipped = $this->shipped();

        return [
            '.htaccess' => $shipped['.htaccess']."# 2\n",
            'public/.htaccess' => $shipped['public/.htaccess']."# 2\n",
            'public/index.php' => "<?php // public/index.php 2\n",
            'index.php' => $shipped['index.php'],
            'artisan' => "#!/usr/bin/env php\n<?php // artisan 2\n",
            'app/Models/User.php' => "<?php // User 2\n",
            'app/Http/Kernel.php' => $shipped['app/Http/Kernel.php'],
            'app/Support/New.php' => "<?php // New 2\n",
            'config/app.php' => "<?php return ['v' => 2];\n",
            'routes/web.php' => $shipped['routes/web.php'],
            'vendor/autoload.php' => "<?php // autoload 2\n",
            'vendor/pkg/src/A.php' => "<?php // A 2\n",
            'vendor/pkg/src/B.php' => "<?php // B 2\n",
            'public/build/manifest.json' => '{"app.js":"assets/app-222.js"}',
            'public/build/assets/app-222.js' => "js 2\n",
            'public/build/assets/app-222.css' => "css 2\n",
            'lang/en.json' => TranslationMerge::encode(['Hello' => 'Hello', 'Bye' => 'Bye', 'New' => 'New']),
            'lang/vi.json' => TranslationMerge::encode(['Hello' => 'Xin chào', 'Bye' => 'Tạm biệt', 'New' => 'Mới']),
            'lang/vi/auth.php' => $shipped['lang/vi/auth.php'],
            'database/migrations/2026_01_01_000000_create_things.php' => $shipped['database/migrations/2026_01_01_000000_create_things.php'],
            'resources/views/home.blade.php' => "home 2\n",
            'bootstrap/cache/.gitignore' => $shipped['bootstrap/cache/.gitignore'],
        ];
    }

    /** @return array<string, string> the site after the update (path => SHA-256, without storage/) */
    private function expectedAfter(): array
    {
        $tree = $this->withoutCompiledCaches($this->before);
        $release = $this->release();

        foreach ($release as $path => $content) {
            $tree[$path] = hash('sha256', $content);
        }

        unset($tree['app/Support/Old.php'], $tree['vendor/pkg/src/Gone.php']);
        // Kept: the owner's public/.htaccess, with the release's copy next to it; the old built assets for cached pages.
        $tree['public/.htaccess'] = $this->before['public/.htaccess'];
        $tree['public/.htaccess.avdbcms-'.$this->next] = hash('sha256', $release['public/.htaccess']);
        $tree['public/build/assets/app-111.js'] = $this->before['public/build/assets/app-111.js'];
        $tree['public/build/assets/app-111.css'] = $this->before['public/build/assets/app-111.css'];
        $tree['lang/vi.json'] = hash('sha256', TranslationMerge::encode(['Hello' => 'Chào bạn', 'Bye' => 'Tạm biệt', 'Old key' => 'Khóa cũ', 'New' => 'Mới']));
        $tree[Manifest::FILE] = hash('sha256', UpdaterSandbox::manifest($this->next, $release, self::REMOVED));
        ksort($tree, SORT_STRING);

        return $tree;
    }

    /** @return array<string, string> the old copies in the file backup */
    private function expectedBackup(): array
    {
        $old = [];

        foreach (['.htaccess', 'public/index.php', 'artisan', 'app/Models/User.php', 'app/Support/Old.php', 'config/app.php', 'vendor/autoload.php',
            'vendor/pkg/src/A.php', 'vendor/pkg/src/Gone.php', 'public/build/manifest.json', 'lang/en.json', 'lang/vi.json',
            'resources/views/home.blade.php', Manifest::FILE] as $path) {
            $old[$path] = $this->before[$path];
        }

        ksort($old, SORT_STRING);

        return $old;
    }

    /* ---------------------------------------------------------------- driving */

    private function updater(?string $version = null): Updater
    {
        return new Updater($this->box->paths(), new ReleaseCheck(), $this->database, new Downloader(), $version ?? AppVersion::CURRENT);
    }

    /** start + steps until the swap is ready; the answer that carries the token and the cookie. */
    private function toSwap(Updater $updater, ?string $engine = null): array
    {
        $start = $updater->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);
        $this->assertSame('step', $start['next']);
        // The cookie of the maintenance page comes with the start, long before the site goes down.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $start['cookie'] ?? '');

        if ($engine !== null) {
            $plan = PlanStore::read($this->box->paths()->plan());
            $plan['engine'] = $engine;
            PlanStore::write($this->box->paths()->plan(), $plan);
        }

        $ready = $this->stepUntil($updater, fn (array $a): bool => $a['next'] !== 'step');

        $this->assertTrue($ready['ok'], (string) $ready['message']);
        $this->assertSame('swapping', $ready['status']);
        $this->assertSame('swap', $ready['next']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ready['token']);
        $this->assertArrayNotHasKey('cookie', $ready, 'the answer that takes the site down (it may be lost) carries no cookie');
        $this->assertNotSame($ready['token'], $start['cookie']);

        return $ready + ['cookie' => $start['cookie']];
    }

    /** @param  callable(array): bool  $until */
    private function stepUntil(Updater $updater, callable $until): array
    {
        for ($i = 0; $i < 40; $i++) {
            $answer = $updater->step($this->admin);

            foreach (['ok', 'status', 'next', 'label', 'progress', 'message'] as $key) {
                $this->assertArrayHasKey($key, $answer);
            }

            if ($until($answer)) {
                return $answer;
            }

            $this->assertSame('step', $answer['next'], 'a step answer that is not the end goes on: '.json_encode($answer));
        }

        $this->fail('The update did not get there in 40 steps.');
    }

    /** The generated hook, loaded the way public/index.php loads it (without answering). */
    private function hook(): object
    {
        $this->assertFileExists($this->box->paths()->hook(), 'the maintenance hook is in place');
        $GLOBALS['avdbcms_update_hook_probe'] = true;

        try {
            return include $this->box->paths()->hook();
        } finally {
            unset($GLOBALS['avdbcms_update_hook_probe']);
        }
    }

    /** The Updates page's swap POSTs, through the hook, until the swap answers something else than "swap". */
    private function swapThroughHook(string $token): array
    {
        for ($i = 0; $i < 20; $i++) {
            $result = $this->hook()->handle(
                ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
                ['avdbcms_update' => 'swap', 'token' => $token],
                [],
            );
            $this->assertSame('application/json; charset=utf-8', $result['headers']['Content-Type']);
            $answer = json_decode($result['body'], true);

            if (($answer['next'] ?? null) !== 'swap') {
                return $answer;
            }
        }

        $this->fail('The swap did not end in 20 requests.');
    }

    private function finish(Updater $updater): array
    {
        for ($i = 0; $i < 10; $i++) {
            $answer = $updater->finish($this->admin);

            if ($answer['next'] !== 'finish') {
                return $answer;
            }
        }

        $this->fail('Finish did not end in 10 requests.');
    }

    /** The whole update to the next version (for the tests that start after it). */
    private function updateToNext(): void
    {
        $ready = $this->toSwap($this->updater());
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $this->addPendingMigration();
        $this->assertSame('done', $this->finish($this->updater($this->next))['status']);
    }

    /**
     * A swap request killed by the host: the first moves done, the next one cut off right after its old file went into
     * the backup (its journal says "begin"), the rest never started.
     */
    private function killSwapHalfWay(): void
    {
        $job = (array) PlanStore::read($this->box->paths()->job($this->next).'/job.json');
        $ops = (new SwapEngine($job))->ops();
        $k = array_search('app/Models/User.php', array_column($ops, 'path'), true);
        (new SwapEngine(['ops' => array_slice($ops, 0, $k)] + $job))->forward(INF);

        file_put_contents($job['journal'], json_encode(['i' => $k, 's' => 'begin', 't' => time()])."\n", FILE_APPEND);
        File::ensureDirectoryExists(dirname($job['backup'].'/files/app/Models/User.php'));
        rename($this->box->root.'/app/Models/User.php', $job['backup'].'/files/app/Models/User.php');

        $this->assertNotSame($this->expectedAfter(), $this->box->snapshot());
        $this->assertNull($this->box->read('app/Models/User.php'));
    }

    /** A migration of "the new version": pending until Finish runs it. */
    private function addPendingMigration(): void
    {
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        File::put($folder.'/2099_01_01_000000_create_updater_flow_marker_table.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('updater_flow_marker', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('updater_flow_marker');
                }
            };
            PHP);
        app('migrator')->path($folder);
        PendingMigrations::forget();
    }

    /* ----------------------------------------------------------------- helpers */

    private function age(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                touch($file->getPathname(), 1_000_000_000);
            }
        }
    }

    /** @param  list<string>  $paths */
    private function assertNeverMoved(array $paths): void
    {
        foreach ($paths as $path) {
            clearstatcache(true, $this->box->root.'/'.$path);
            $this->assertSame(1_000_000_000, filemtime($this->box->root.'/'.$path), $path.' was moved or rewritten');
        }
    }

    /** @param  array<string, string>  $tree */
    private function withoutCompiledCaches(array $tree): array
    {
        unset($tree['bootstrap/cache/config.php'], $tree['bootstrap/cache/packages.php']);

        return $tree;
    }

    /** @return list<string> */
    private function audit(): array
    {
        return DB::table('admin_audit_logs')->where('action', 'like', 'update.%')->orderBy('id')->pluck('action')->all();
    }

    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
