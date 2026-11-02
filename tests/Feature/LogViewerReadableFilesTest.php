<?php

namespace Tests\Feature;

use App\Enums\ExportStatus;
use App\Logs\ReadableLogViewer;
use App\Models\Export;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Opcodes\LogViewer\Facades\LogViewer;
use Opcodes\LogViewer\LogViewerService;
use Tests\TestCase;

/**
 * Tools > Logs never answers 500 for a log file PHP cannot read (R1BR-02, Linux hosting).
 *
 * config/log-viewer.php takes the web server's logs (/var/log/nginx/*, /var/log/httpd/*) for hosts where the site may
 * read them. On a normal Linux host PHP runs as the site's own user: it may list /var/log/nginx but not open its files
 * (root:adm 0640), and a search that reached one answered 500 ("Could not open /var/log/nginx/access.log for reading";
 * AdminFilterParametersTest failed twice in the Linux suite). Folders outside the site that PHP cannot read are left out
 * without being touched, files it cannot open are not listed, the site's own logs are listed and searched as before.
 *
 * A file PHP cannot read is made with chmod 000; where that does not take (Windows, or a root process, which reads
 * anything), that part is skipped: it runs on the Linux box as the site's user.
 */
class LogViewerReadableFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $outside = '';

    /** @var list<string> files a Windows rule closed (opened again at the end) */
    private array $denied = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);
        $this->outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r1br02-'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($this->outside);
    }

    protected function tearDown(): void
    {
        foreach ($this->denied as $path) {
            @exec('icacls '.escapeshellarg($path).' /reset 2>&1');
        }

        $this->denied = [];
        @chmod($this->outside.DIRECTORY_SEPARATOR.'closed', 0755);

        foreach (File::allFiles($this->outside, true) as $file) {
            @chmod($file->getPathname(), 0644);
        }

        File::deleteDirectory($this->outside);

        parent::tearDown();
    }

    public function test_the_log_viewer_is_the_readable_one(): void
    {
        $this->assertInstanceOf(ReadableLogViewer::class, app('log-viewer'));
        $this->assertInstanceOf(ReadableLogViewer::class, app(LogViewerService::class));
    }

    public function test_patterns_outside_the_site_count_only_where_php_may_read_their_folder(): void
    {
        $separator = DIRECTORY_SEPARATOR;

        // the site's own logs (relative to storage/logs, as the package reads them)
        $this->assertTrue(ReadableLogViewer::reachable('*.log'));
        $this->assertTrue(ReadableLogViewer::reachable('**/*.log'));

        // an absolute folder that is there (only Linux paths start with the separator), and one that is not
        if ($separator === '/') {
            $this->assertTrue(ReadableLogViewer::reachable($this->outside.'/*'));
            $this->assertTrue(ReadableLogViewer::reachable($this->outside.'/php-fpm.log'));
        }

        $this->assertFalse(ReadableLogViewer::reachable($separator.'avd-no-such-folder-'.Str::random(6).$separator.'*'));
        $this->assertFalse(ReadableLogViewer::reachable($separator.'avd-no-such-folder-'.Str::random(6).$separator.'php-fpm.log'));

        $this->assertFalse(ReadableLogViewer::readable($this->outside.DIRECTORY_SEPARATOR.'missing.log'));
        File::put($this->outside.DIRECTORY_SEPARATOR.'here.log', "[2026-10-28 10:00:00] local.INFO: here\n");
        $this->assertTrue(ReadableLogViewer::readable($this->outside.DIRECTORY_SEPARATOR.'here.log'));
    }

    public function test_the_shipped_web_server_folders_never_break_a_search(): void
    {
        $this->assertContains('Nginx', config('log-viewer.include_files'), 'the shipped list still offers the web server logs');
        File::ensureDirectoryExists(storage_path('logs'));

        $admin = User::factory()->admin()->create();

        foreach (['/admin/logs/api/folders', '/admin/logs/api/files', '/admin/logs/api/logs?query=x',
                     '/admin/logs/api/logs?query=x&exclude_file_types=laravel'] as $address) {
            $this->actingAs($admin)->get($address)->assertOk();
        }
    }

    public function test_a_file_php_cannot_open_is_left_out_and_the_others_are_searched(): void
    {
        $open = $this->outside.DIRECTORY_SEPARATOR.'open.log';
        $closed = $this->outside.DIRECTORY_SEPARATOR.'closed.log';
        File::put($open, "[2026-10-28 10:00:00] local.ERROR: findme in the open log\n");
        File::put($closed, "[2026-10-28 10:00:00] local.ERROR: findme in the closed log\n");
        chmod($closed, 0000);
        clearstatcache();

        if (is_readable($closed) || DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('This process can read a chmod 000 file (Windows, or root): the Linux box runs this part as the site user.');
        }

        // an absolute pattern, like /var/log/nginx/* of the shipped list
        config(['log-viewer.include_files' => ['*.log', $this->outside.'/*' => 'Web server']]);
        LogViewer::clearFileCache();
        $admin = User::factory()->admin()->create();

        $files = $this->actingAs($admin)->getJson('/admin/logs/api/files')->assertOk()->json();
        $names = array_column($files, 'name');
        $this->assertContains('open.log', $names);
        $this->assertNotContains('closed.log', $names);

        // the search reads every listed file: 200 and the readable file's entry, never the 500 of round 1b
        $this->actingAs($admin)->getJson('/admin/logs/api/logs?query=findme')->assertOk()->assertSee('findme in the open log');

        // a folder PHP may not read is not even looked into
        $folder = $this->outside.DIRECTORY_SEPARATOR.'closed';
        File::ensureDirectoryExists($folder);
        File::put($folder.DIRECTORY_SEPARATOR.'inside.log', "[2026-10-28 10:00:00] local.ERROR: inside\n");
        chmod($folder, 0000);
        clearstatcache();
        $this->assertFalse(ReadableLogViewer::reachable($folder.'/*'));
        config(['log-viewer.include_files' => ['*.log', $folder.'/*' => 'Closed']]);
        LogViewer::clearFileCache();
        $this->actingAs($admin)->getJson('/admin/logs/api/logs?query=x')->assertOk();
    }

    /**
     * The menu's own Tools > Logs page (Site logs, LogViewerController) follows the same rule (judge R2CD-RV-02): a log
     * of storage/logs PHP may not read (root's cron with umask 077, another user of the server) is not listed, and
     * opening or downloading it by its address says so. It answered 500: the whole page when that log was the newest
     * one (file_get_contents ... Permission denied), its link and Download otherwise.
     *
     * The file is closed with chmod 000 (Linux, as the site's user) or with a Windows rule that denies reading; where
     * neither takes (a root process reads anything), the test is skipped.
     */
    public function test_the_site_logs_page_leaves_out_a_log_php_cannot_read(): void
    {
        $storage = $this->outside.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'logs');
        File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'app');
        File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache');
        $older = $storage.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel-2026-10-26.log';
        $closed = $storage.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel-2026-10-27.log';
        File::put($older, "[2026-10-26 10:00:00] local.INFO: the readable log\n");
        File::put($closed, "[2026-10-27 10:00:00] local.INFO: the closed log\n");
        touch($older, time() - 3600);
        touch($closed, time());

        if (! $this->closeForPhp($closed)) {
            $this->markTestSkipped('This process can read a closed file (root): the Linux box runs this part as the site user.');
        }

        $this->app->useStoragePath($storage);
        $admin = User::factory()->admin()->create();

        // the newest log is the closed one: the page opens the newest readable one and says one file is not shown
        $page = $this->actingAs($admin)->get(route('admin.logs.index'))->assertOk();
        $page->assertSee('laravel-2026-10-26.log');
        $page->assertSee('the readable log');
        $page->assertDontSee('laravel-2026-10-27.log');
        $page->assertSee(trans_choice('1 log file is not shown: the site has no permission to read it.|:count log files are not shown: the site has no permission to read them.', 1, ['count' => 1]));

        // its address: the page says the site may not read it (no 500, nothing of the file)
        $this->actingAs($admin)->get(route('admin.logs.index', ['file' => 'laravel-2026-10-27.log']))
            ->assertOk()
            ->assertSee(__('The site has no permission to read the log file :file.', ['file' => 'laravel-2026-10-27.log']))
            ->assertDontSee('the closed log');

        // Download: back to the page with the same words
        $this->actingAs($admin)->get(route('admin.logs.download', 'laravel-2026-10-27.log'))
            ->assertRedirect(route('admin.logs.index'))
            ->assertSessionHasErrors(['log' => __('The site has no permission to read the log file :file.', ['file' => 'laravel-2026-10-27.log'])]);

        // the readable one downloads as before; an address that is not a name of the folder changes nothing
        $this->actingAs($admin)->get(route('admin.logs.download', 'laravel-2026-10-26.log'))->assertOk();
        $this->actingAs($admin)->get(route('admin.logs.index', ['file' => ['x']]))->assertOk()->assertSee('laravel-2026-10-26.log');
    }

    /**
     * The same for Download on Backups and Exports (judge R2CD-RV-02, looked around): a database backup or an export the
     * site may not read (root's cron, a queue worker run as root with umask 077) goes back to its list with the reason,
     * where it answered 500 ("File must be readable", or a download that failed half way).
     */
    public function test_a_backup_or_an_export_php_cannot_read_goes_back_to_its_list_with_the_reason(): void
    {
        $storage = $this->outside.DIRECTORY_SEPARATOR.'storage';
        File::ensureDirectoryExists($storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups');
        $backup = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'backup_2026-10-27.sql';
        File::put($backup, "-- a backup\n");
        Storage::fake(config('filesystems.default'));
        Storage::disk()->put('exports/members-2026-10-27.csv', "id,name\n");
        $export = Export::query()->create(['file' => 'members-2026-10-27.csv', 'size' => 8, 'status' => ExportStatus::COMPLETED]);

        if (! $this->closeForPhp($backup) || ! $this->closeForPhp(Storage::disk()->path('exports/members-2026-10-27.csv'))) {
            $this->markTestSkipped('This process can read a closed file (root): the Linux box runs this part as the site user.');
        }

        $this->app->useStoragePath($storage);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.backups.download', 'backup_2026-10-27.sql'))
            ->assertRedirect(route('admin.backups.index'))
            ->assertSessionHasErrors(['backup' => __('The site has no permission to read the backup :file.', ['file' => 'backup_2026-10-27.sql'])]);

        $this->actingAs($admin)->get(route('admin.exports.download', $export))
            ->assertRedirect(route('admin.exports.index'))
            ->assertSessionHasErrors(['export' => __('The site has no permission to read the export :file.', ['file' => 'members-2026-10-27.csv'])]);
    }

    /** Closes $path for this PHP process; whether PHP now finds it unreadable. */
    private function closeForPhp(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $user = getenv('USERNAME');

            if (! function_exists('exec') || ! is_string($user) || $user === '') {
                return false;
            }

            @exec('icacls '.escapeshellarg($path).' /deny '.escapeshellarg($user.':(R)').' 2>&1', $output, $code);
            $this->denied[] = $path;
        } else {
            @chmod($path, 0000);
        }

        clearstatcache();

        return ! is_readable($path) && @file_get_contents($path) === false;
    }
}
