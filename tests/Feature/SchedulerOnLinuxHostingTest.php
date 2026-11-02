<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Models\User;
use App\Support\AppendLog;
use App\Support\LaravelSchedulerCron;
use App\Support\ShellRunner;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The scheduler on Linux hosting (judge round 1b):
 *  - R1B-004: the cron line's lock is inside the site (storage/framework) and named from a hash of the site's whole
 *    folder: two sites in folders of the same name (public_html of two accounts) never share it (1.2.x: /tmp/<folder
 *    name>-scheduler.lock); a line this site wrote in 1.2.x is replaced in its place, once, and no other crontab line
 *    changes; the status counts only the system cron's runs as "running";
 *  - R1B-005: the logs are appended to, cut to their newest lines when big, never world-writable; the page requests'
 *    runs have their own log (the cron's log is never wiped by them);
 *  - R1B-006: the page requests never start the scheduler while the system cron runs it (the cron's runs write the
 *    stamp they check), and the two member mails run once per minute whoever starts the scheduler (onOneServer);
 *  - R1B-015: the page requests start it only on an installed site; no task is scheduled while the installer is open
 *    again or before the installation.
 * Second pass (the review's findings): a line the owner switched off or wrote himself stays as it is (BS-RV-01); a
 * rewrite is read back at a later run, the sites of one account take turns (BS-RV-02); the background-run test cannot
 * interleave its lines (BS-RV-03); an installed site without the installer's lock still runs its tasks after visits
 * (BS-RV-04); the Windows task runs PHP itself (BS-RV-06).
 * Each test has a storage folder of its own (the copy's real one stays untouched). The parts that need Unix (file
 * modes, a crontab command, background processes) are skipped on Windows and run on Linux.
 */
class SchedulerOnLinuxHostingTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    /** @var array<string, mixed> */
    private array $envBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = str_replace('\\', '/', storage_path('framework/testing/scheduler-'.uniqid()));
        foreach (['app', 'framework', 'logs'] as $folder) {
            File::ensureDirectoryExists($this->storage.'/'.$folder);
        }
        $this->app->useStoragePath($this->storage);

        // (the variable's name written out: these tests also run on the code before the fix, to show what it did)
        foreach (['INSTALLER_ALLOW_REINSTALL', 'APP_INSTALLED', 'AVDB_SCHEDULER_SOURCE'] as $key) {
            $this->envBefore[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', null);
        $this->setEnv('AVDB_SCHEDULER_SOURCE', null);

        // The account's real crontab is never asked here: its check counts as done (the crontab tests use their own).
        $account = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'user';
        $lock = storage_path('framework/scheduler-'.substr(sha1(base_path()), 0, 12).'.lock');
        file_put_contents($this->storage.'/framework/scheduler-cron-line-'.$account.'.json', json_encode([
            'key' => sha1($this->marker().'|'.$lock), 'result' => 'absent', 'at' => time(),
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->envBefore as $key => [$env, $server, $process]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            putenv($process === false ? $key : $key.'='.$process);
        }

        Carbon::setTestNow();
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ R1B-004: the lock

    public function test_the_cron_lock_is_inside_the_site_and_named_from_its_whole_folder(): void
    {
        $lock = LaravelSchedulerCron::lockPath();
        $this->assertStringStartsWith(str_replace('\\', '/', storage_path('framework')), str_replace('\\', '/', $lock));
        $this->assertStringEndsWith('scheduler-'.substr(sha1(base_path()), 0, 12).'.lock', $lock);
        $this->assertStringNotContainsString(basename(base_path()).'-scheduler.lock', $lock, 'not the folder name of 1.2.x');

        // Two accounts, one folder name: two locks (1.2.x: both /tmp/public_html-scheduler.lock).
        $base = base_path();
        $locks = [];

        try {
            foreach (['/home/alice/public_html', '/home/bob/public_html'] as $folder) {
                $this->app->setBasePath($folder);
                $locks[] = LaravelSchedulerCron::lockPath();
            }
        } finally {
            $this->app->setBasePath($base);
        }

        $this->assertNotSame($locks[0], $locks[1]);
    }

    public function test_the_cron_line_and_the_panel_command_take_the_lock_inside_the_site(): void
    {
        $this->skipOnWindows('The cron line with its flock lock is the Unix line.');

        $line = LaravelSchedulerCron::lineFor(PHP_BINARY);
        $this->assertStringStartsWith('* * * * * /usr/bin/flock -n -o '.escapeshellarg(LaravelSchedulerCron::lockPath()).' /bin/sh -c ', $line);
        $this->assertStringNotContainsString("'/tmp/", $line);
        $this->assertStringContainsString('artisan schedule:run >> ', $line);

        // Root's cron of a panel: the account is switched first, the lock is then the account's (never a root file in the site).
        $panel = LaravelSchedulerCron::panelLineFor(PHP_BINARY);
        $this->assertStringStartsWith('/bin/su -s /bin/sh ', $panel);
        $this->assertStringContainsString(escapeshellarg(substr($line, strlen('* * * * * '))), $panel);
    }

    // ------------------------------------------------------------------ R1B-004: the crontab of 1.2.x

    public function test_only_this_sites_block_of_the_crontab_is_replaced_and_in_its_place(): void
    {
        $marker = $this->marker();
        $old = $this->oldLine('/home/alice/public_html', '/usr/bin/php8.3');
        $crontab = "MAILTO=owner@example.test\n# the owner's backup\n*/5 * * * * /usr/local/bin/backup.sh  \n\n"
            ."# >>> {$marker}\n{$old}\n# <<< {$marker}\n"
            ."# >>> AVDBClip Laravel Scheduler 0123456789ab\n* * * * * another site\n# <<< AVDBClip Laravel Scheduler 0123456789ab\n"
            ."0 3 * * * /usr/bin/php /home/alice/other.php\n";
        $new = '* * * * * the new line';

        $result = $this->privately('withBlock', $crontab, $marker, $new);

        $this->assertSame(str_replace($old, $new, $crontab), $result, 'the block changes where it is; every other byte stays');
        $this->assertSame([$new], $this->privately('planBlock', $result, null, false)['lines']);

        // A second block of this site (only a fault writes one) goes; a start marker without its end is replaced alone.
        $twice = "# >>> {$marker}\n{$old}\n# <<< {$marker}\n1 1 * * * mine\n# >>> {$marker}\n{$old}\n# <<< {$marker}\n";
        $this->assertSame("# >>> {$marker}\n{$new}\n# <<< {$marker}\n1 1 * * * mine\n", $this->privately('withBlock', $twice, $marker, $new));
        $open = "# >>> {$marker}\n2 2 * * * kept\n";
        $this->assertSame("# >>> {$marker}\n{$new}\n# <<< {$marker}\n2 2 * * * kept\n", $this->privately('withBlock', $open, $marker, $new));

        // No block: added at the end, as before.
        $this->assertSame("1 1 * * * mine\n\n# >>> {$marker}\n{$new}\n# <<< {$marker}\n", $this->privately('withBlock', "1 1 * * * mine\n", $marker, $new));
        $this->assertSame("# >>> {$marker}\n{$new}\n# <<< {$marker}\n", $this->privately('withBlock', '', $marker, $new));
        $this->assertSame('absent', $this->privately('planBlock', "1 1 * * * mine\n", null, false)['state']);

        // The PHP binary of the older line stays the line's.
        $this->assertSame('/usr/bin/php8.3', $this->privately('phpOfLine', $old));
        $this->assertSame('/opt/my php/bin/php', $this->privately('phpOfLine', $this->oldLine("/home/o'neil/public_html", '/opt/my php/bin/php')));
    }

    public function test_an_older_line_of_this_site_is_replaced_once_and_nothing_is_ever_added(): void
    {
        $this->skipOnWindows('A crontab command is a Unix one.');
        [$file, $calls] = $this->fakeCrontab();
        foreach (glob($this->storage.'/framework/scheduler-cron-line-*.json') as $state) {
            unlink($state);
        }
        $marker = $this->marker();
        $old = $this->oldLine(base_path(), PHP_BINARY);
        $others = "MAILTO=\"\"\n*/10 * * * * /usr/local/bin/keep-me.sh >/dev/null 2>&1   \n";
        file_put_contents($file, $others."# >>> {$marker}\n{$old}\n# <<< {$marker}\n0 4 * * * /usr/bin/true\n");

        $this->assertSame('old', LaravelSchedulerCron::crontabState()['state']);
        $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine());

        $crontab = (string) file_get_contents($file);
        $this->assertStringStartsWith($others."# >>> {$marker}\n", $crontab, 'the lines before it are untouched');
        $this->assertStringEndsWith("# <<< {$marker}\n0 4 * * * /usr/bin/true\n", $crontab, 'the lines after it are untouched');
        $this->assertSame([LaravelSchedulerCron::lineFor(PHP_BINARY)], $this->privately('planBlock', $crontab, null, false)['lines']);
        $this->assertStringNotContainsString("'/tmp/", $crontab, 'no lock in /tmp any more');
        $this->assertSame('current', LaravelSchedulerCron::crontabState()['state']);

        // Once: the next run asks nothing (no crontab command), whatever the crontab says now.
        $count = count(file($calls));
        $this->assertNull(LaravelSchedulerCron::migrateCronLine());
        $this->assertCount($count, file($calls));

        // Without a block of this site (the owner removed it, a panel's cron runs the site): nothing is added.
        file_put_contents($file, $others);
        foreach (glob($this->storage.'/framework/scheduler-cron-line-*.json') as $state) {
            unlink($state);
        }
        $this->assertSame('absent', LaravelSchedulerCron::migrateCronLine());
        $this->assertSame($others, file_get_contents($file));
        $this->assertSame('absent', LaravelSchedulerCron::crontabState()['state']);

        // A host without a crontab command: noted, and asked again only after an hour (not every minute).
        $path = getenv('PATH');
        $this->setEnv('PATH', $this->storage.'/no-bin');
        foreach (glob($this->storage.'/framework/scheduler-cron-line-*.json') as $state) {
            unlink($state);
        }
        $this->assertSame('unavailable', LaravelSchedulerCron::migrateCronLine());
        $this->assertNull(LaravelSchedulerCron::migrateCronLine());
        $this->setEnv('PATH', $path);

        // The installer's and the import page's ensureInstalled(): the current line, in place, once.
        file_put_contents($file, $others."# >>> {$marker}\n{$old}\n# <<< {$marker}\n");
        $installed = LaravelSchedulerCron::ensureInstalled(PHP_BINARY);
        $this->assertTrue($installed['changed']);
        $this->assertSame($others."# >>> {$marker}\n".LaravelSchedulerCron::lineFor(PHP_BINARY)."\n# <<< {$marker}\n", file_get_contents($file));
        $this->assertFalse(LaravelSchedulerCron::ensureInstalled(PHP_BINARY)['changed'], 'already installed');
    }

    public function test_a_line_the_owner_switched_off_or_wrote_himself_stays_as_it_is(): void
    {
        // Judge round 1b BS-RV-01: the owner switched the scheduler off by putting "#" in front of the site's line (the
        // markers kept); the update must not switch it on again, nor the installer's / import page's ensureInstalled().
        $this->skipOnWindows('A crontab command is a Unix one.');
        [$file] = $this->fakeCrontab();
        $marker = $this->marker();
        $old = $this->oldLine(base_path(), PHP_BINARY);
        $others = "MAILTO=\"\"\n*/10 * * * * /usr/local/bin/keep-me.sh   \n";
        $after = "17 3 * * * /bin/true # owner job\n";
        $current = LaravelSchedulerCron::lineFor(PHP_BINARY);
        $check = function (string $crontab, string $state) use ($file): void {
            file_put_contents($file, $crontab);
            $this->forgetLineCheck();

            $this->assertSame($state, LaravelSchedulerCron::crontabState()['state']);
            $this->assertSame($state, LaravelSchedulerCron::migrateCronLine(), 'the update leaves it');
            $this->assertSame($crontab, file_get_contents($file), 'byte for byte');
            $this->assertNull(LaravelSchedulerCron::migrateCronLine(), 'and never looks again');

            $installed = LaravelSchedulerCron::ensureInstalled(PHP_BINARY);
            $this->assertFalse($installed['changed']);
            $this->assertFalse($installed['installed']);
            $this->assertSame($crontab, file_get_contents($file), 'the installer and the import page leave it too');
        };

        // Switched off: the 1.2.x line, or the current one, behind "#"; a block whose line was removed.
        $check($others."# >>> {$marker}\n# {$old}\n# <<< {$marker}\n".$after, 'off');
        $check($others."# >>> {$marker}\n#{$current}\n# <<< {$marker}\n".$after, 'off');
        $check($others."# >>> {$marker}\n# <<< {$marker}\n".$after, 'off');
        // Written by hand in the site's block (no lock in /tmp), or the block's end marker gone.
        $check($others."# >>> {$marker}\n* * * * * cd /home/me/site && php artisan schedule:run >/dev/null 2>&1\n# <<< {$marker}\n".$after, 'custom');
        $check($others."# >>> {$marker}\n{$old}\n".$after, 'custom');
        $this->assertSame(['* * * * * cd /home/me/site && php artisan schedule:run >/dev/null 2>&1'], $this->privately('planBlock',
            $others."# >>> {$marker}\n* * * * * cd /home/me/site && php artisan schedule:run >/dev/null 2>&1\n# <<< {$marker}\n", null, false)['lines']);

        // Only the 1.2.x line is rewritten, in its place: the owner's comment in the block stays.
        file_put_contents($file, $others."# >>> {$marker}\n# kept: the owner's note\n{$old}\n# <<< {$marker}\n".$after);
        $this->forgetLineCheck();
        $this->assertSame('old', LaravelSchedulerCron::crontabState()['state']);
        $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine());
        $this->assertSame($others."# >>> {$marker}\n# kept: the owner's note\n{$current}\n# <<< {$marker}\n".$after, file_get_contents($file));
        $this->assertSame('current', LaravelSchedulerCron::crontabState()['state']);

        // A 1.2.x line next to this site's current line would run the scheduler twice: it goes, the current one stays.
        file_put_contents($file, $others."# >>> {$marker}\n{$current}\n{$old}\n# <<< {$marker}\n".$after);
        $this->forgetLineCheck();
        $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine());
        $this->assertSame($others."# >>> {$marker}\n{$current}\n# <<< {$marker}\n".$after, file_get_contents($file));
    }

    public function test_a_rewrite_is_read_back_and_made_again_when_another_site_put_the_older_line_back(): void
    {
        // Judge round 1b BS-RV-02: two sites of one account rewrite the crontab in the same second; each reads the whole
        // crontab, changes its own block and writes it all back, so the second write put the first site's 1.2.x line
        // back, and the first site's "updated" stopped every later check.
        $this->skipOnWindows('A crontab command is a Unix one.');
        [$file, $calls] = $this->fakeCrontab();
        $this->forgetLineCheck();
        $marker = $this->marker();
        $before = "MAILTO=\"\"\n# >>> {$marker}\n".$this->oldLine(base_path(), PHP_BINARY)."\n# <<< {$marker}\n";
        file_put_contents($file, $before);
        $current = "MAILTO=\"\"\n# >>> {$marker}\n".LaravelSchedulerCron::lineFor(PHP_BINARY)."\n# <<< {$marker}\n";

        $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine());
        $this->assertSame($current, file_get_contents($file));

        // Another site's write of the same moment puts the older line back.
        file_put_contents($file, $before);
        $this->assertNull(LaravelSchedulerCron::migrateCronLine(), 'not in the same minute');
        $this->ageLineCheck(31);
        $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine(), 'read back at a later run and made again');
        $this->assertSame($current, file_get_contents($file));
        $this->ageLineCheck(31);
        $this->assertSame('current', LaravelSchedulerCron::migrateCronLine(), 'read back: it stayed');
        $count = count(file($calls));
        $this->ageLineCheck(4000);
        $this->assertNull(LaravelSchedulerCron::migrateCronLine(), 'done for good');
        $this->assertCount($count, file($calls));

        // Something that keeps putting the older line back: three rewrites in a row, then it waits an hour.
        $this->forgetLineCheck();
        for ($write = 1; $write <= 3; $write++) {
            file_put_contents($file, $before);
            $this->ageLineCheck(31);
            $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine(), "rewrite {$write}");
        }
        file_put_contents($file, $before);
        $this->ageLineCheck(31);
        $this->assertSame('failed', LaravelSchedulerCron::migrateCronLine());
        $this->assertSame($before, file_get_contents($file), 'no fourth rewrite');
        $this->ageLineCheck(1000);
        $this->assertNull(LaravelSchedulerCron::migrateCronLine());
        $this->ageLineCheck(3601);
        $this->assertSame('updated', LaravelSchedulerCron::migrateCronLine(), 'an hour later');

        // The sites of one account change the crontab one after the other: a change waits while another site holds
        // the account's lock (and goes on after the wait: the read-back makes up for a lost change).
        $lock = fopen($this->privately('accountLockPath'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $started = microtime(true);
        $this->assertSame('done', $this->privately('withAccountLock', fn (): string => 'done', 0.4));
        $this->assertGreaterThanOrEqual(0.35, microtime(true) - $started, 'it waited for the other site');
        flock($lock, LOCK_UN);
        fclose($lock);
        $started = microtime(true);
        $this->assertSame('done', $this->privately('withAccountLock', fn (): string => 'done', 5.0));
        $this->assertLessThan(0.3, microtime(true) - $started, 'free: at once');
    }

    public function test_the_windows_task_runs_php_itself_and_is_named_for_the_site(): void
    {
        // Judge round 1b BS-RV-06: the Task Scheduler starts a program with its arguments; "cd ... && ... >> log" only
        // works in a shell. The command creates the site's task (named like its crontab block) that runs PHP itself.
        $php = 'C:\\Program Files\\PHP 8.3\\php.exe';
        $command = LaravelSchedulerCron::windowsTaskCommand($php);
        $run = '"'.$php.'" "'.base_path('artisan').'" schedule:run';

        $this->assertSame('schtasks /Create /SC MINUTE /MO 1 /TN "'.$this->marker().'" /TR "'.str_replace('"', '\\"', $run).'" /F', $command);
        // Read the way a Windows program reads its command line: /TR is the program and its arguments, nothing else.
        $words = $this->windowsArguments($command);
        $this->assertSame(['schtasks', '/Create', '/SC', 'MINUTE', '/MO', '1', '/TN', $this->marker(), '/TR', $run, '/F'], $words);
        $this->assertSame([$php, base_path('artisan'), 'schedule:run'], $this->windowsArguments($words[9]));
        foreach (['&&', '>>', '2>&1', 'cd '] as $shell) {
            $this->assertStringNotContainsString($shell, $command);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertSame(LaravelSchedulerCron::windowsTaskCommand(PHP_BINARY), LaravelSchedulerCron::lineFor(PHP_BINARY));
        }
    }

    // ------------------------------------------------------------------ R1B-006 / R1B-015: the page requests

    public function test_the_page_requests_start_the_scheduler_only_on_an_installed_site_and_never_while_the_cron_runs_it(): void
    {
        $started = 0;
        $start = function () use (&$started): bool {
            $started++;

            return true;
        };
        $now = time();
        // each request below started after the installation (the lock is older than the request)
        $request = fn (int $at): float => (float) $at;

        // A fresh upload, the installer at work: no lock yet, the .env.example copy (R1B-015).
        $this->setEnv('APP_INSTALLED', 'false');
        $this->assertSame('not installed', LaravelSchedulerCron::afterPageRequest($now, $start, $request($now)));
        $this->setEnv('APP_INSTALLED', 'true');
        file_put_contents(storage_path('app/installed.json'), '{}');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->assertSame('not installed', LaravelSchedulerCron::afterPageRequest($now, $start, $request($now + 1)), 'the installer is open again');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', null);
        // The installer's own last request wrote the lock: it still holds the values of the .env.example copy it read
        // when it started (no database user), and a run it started would inherit them.
        $this->assertSame('changed', LaravelSchedulerCron::afterPageRequest($now, $start, $request($now - 5)));
        $this->assertSame(0, $started);

        // Installed, no cron: the site runs it, once a minute.
        $expected = \App\Support\Environment::canRunProcesses() ? 'started' : 'no processes';
        $this->assertSame($expected, LaravelSchedulerCron::afterPageRequest($now + 1, $start, $request($now + 1)));
        $this->assertSame('throttled', LaravelSchedulerCron::afterPageRequest($now + 30, $start, $request($now + 30)));

        // The system cron runs it (its run stamps itself): the page requests never start a second run (R1B-006).
        LaravelSchedulerCron::recordCronRun();
        $this->assertTrue(LaravelSchedulerCron::cronRanRecently());
        $this->assertSame('cron', LaravelSchedulerCron::afterPageRequest(time() + 62, $start, $request(time() + 62)));
        $this->assertSame('cron', LaravelSchedulerCron::afterPageRequest(time() + 123, $start, $request(time() + 123)), 'a cron minute that was skipped once');

        // The cron stopped: the site takes over again.
        $later = time() + 62 + LaravelSchedulerCron::CRON_FRESH_SECONDS + 61;
        $this->assertSame($expected, LaravelSchedulerCron::afterPageRequest($later, $start, $request($later)));
        $this->assertSame($expected === 'started' ? 2 : 0, $started);
    }

    public function test_an_installed_site_without_the_installers_lock_still_runs_its_tasks_after_visits(): void
    {
        // Judge round 1b BS-RV-04: a site moved to another host without storage/app, or whose lock was removed by hand:
        // .env says APP_INSTALLED=true, an administrator exists. 1.2.x ran its tasks after visits; the console schedule
        // still runs them. During an installation (an installer that writes APP_INSTALLED=true before its database work:
        // a .env of the last minutes) and without an administrator, the page requests still start nothing.
        $started = 0;
        $start = function () use (&$started): bool {
            $started++;

            return true;
        };
        $expected = \App\Support\Environment::canRunProcesses() ? 'started' : 'no processes';
        $base = base_path();
        $site = str_replace('\\', '/', $this->storage).'/site';
        File::ensureDirectoryExists($site);
        file_put_contents($site.'/.env', "APP_INSTALLED=true\n");
        $written = time() - 30;
        touch($site.'/.env', $written);
        $this->setEnv('APP_INSTALLED', 'true');

        try {
            $this->app->setBasePath($site);
            $this->forgetLineCheck(true);
            $this->assertFileDoesNotExist(storage_path('app/installed.json'));

            // .env written 30 seconds ago: an installation may be at work (no minute taken: the next request looks again).
            $this->assertSame('not installed', LaravelSchedulerCron::afterPageRequest($written + 30, $start, $written + 30.0));
            $this->assertFileDoesNotExist(storage_path('framework/scheduler-last-run.timestamp'));

            // An older .env, but no administrator yet (an unfinished installation): nothing, and the minute is taken.
            $quiet = 600; // LaravelSchedulerCron::INSTALL_QUIET_SECONDS (checked at the end)
            $later = $written + $quiet + 5;
            $this->assertSame('not installed', LaravelSchedulerCron::afterPageRequest($later, $start, (float) $later));
            $this->assertSame('throttled', LaravelSchedulerCron::afterPageRequest($later + 10, $start, (float) $later + 10));
            $this->assertFalse(LaravelSchedulerCron::siteInstalled($later));

            // The installed site: an administrator; the page requests start its tasks again, once a minute.
            User::factory()->admin()->create();
            $this->assertTrue(LaravelSchedulerCron::siteInstalled($later));
            $this->assertSame($expected, LaravelSchedulerCron::afterPageRequest($later + 61, $start, (float) $later + 61));
            $this->assertSame('throttled', LaravelSchedulerCron::afterPageRequest($later + 90, $start, (float) $later + 90));
            $this->assertGreaterThan(10, count($this->app->make(ConsoleKernel::class)->resolveConsoleSchedule()->events()), 'the console schedule agrees');

            // Never while the .env says it is not installed, nor while the installer is open again.
            $this->setEnv('APP_INSTALLED', 'false');
            $this->assertSame('not installed', LaravelSchedulerCron::afterPageRequest($later + 200, $start, (float) $later + 200));
            $this->setEnv('APP_INSTALLED', 'true');
            $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
            $this->assertSame('not installed', LaravelSchedulerCron::afterPageRequest($later + 300, $start, (float) $later + 300));
            $this->setEnv('INSTALLER_ALLOW_REINSTALL', null);
            $this->assertSame($expected, LaravelSchedulerCron::afterPageRequest($later + 400, $start, (float) $later + 400));
        } finally {
            $this->app->setBasePath($base);
        }

        $this->assertSame($expected === 'started' ? 2 : 0, $started);
        $this->assertSame(600, LaravelSchedulerCron::INSTALL_QUIET_SECONDS, 'what the test took for it');
    }

    public function test_the_cron_runs_are_stamped_and_the_sites_own_runs_are_not(): void
    {
        $this->assertSame(['source' => 'none', 'last_cron' => null, 'last_site' => null], LaravelSchedulerCron::runStatus());

        // A run the site started (after a page request, "Run Scheduler Now") is not the cron.
        $this->setEnv(LaravelSchedulerCron::SOURCE_ENV, LaravelSchedulerCron::SOURCE_SITE);
        $this->assertTrue(LaravelSchedulerCron::startedBySite());
        LaravelSchedulerCron::recordCronRun();
        $this->assertNull(LaravelSchedulerCron::lastCronRunAt());
        $this->privately('writeStamp', 'framework/scheduler-web.timestamp');
        $this->assertSame('site', LaravelSchedulerCron::runStatus()['source']);

        // The system cron (or a run by hand, the Task Scheduler, schedule:work).
        $this->setEnv(LaravelSchedulerCron::SOURCE_ENV, null);
        $this->assertFalse(LaravelSchedulerCron::startedBySite());
        LaravelSchedulerCron::recordCronRun();
        $this->assertEqualsWithDelta(time(), LaravelSchedulerCron::lastCronRunAt(), 2);
        $this->assertSame('cron', LaravelSchedulerCron::runStatus()['source']);
        $this->assertSame('none', LaravelSchedulerCron::runStatus(time() + LaravelSchedulerCron::RECENT_SECONDS + 5)['source'], 'old runs count no more');

        // The cron's run keeps the cron's logs small.
        file_put_contents(storage_path(LaravelSchedulerCron::CRON_LOG), str_repeat("a line of the cron's log\n", 120000));
        LaravelSchedulerCron::recordCronRun();
        clearstatcache();
        $this->assertLessThanOrEqual(AppendLog::KEEP_BYTES, filesize(storage_path(LaravelSchedulerCron::CRON_LOG)));
    }

    public function test_the_status_says_running_only_for_the_system_cron(): void
    {
        Cache::flush();
        $label = fn (): string => StatusProbeController::result('schedule', true)['label'];

        $this->assertSame('Scheduled tasks are not running', $label());

        $this->privately('writeStamp', 'framework/scheduler-web.timestamp');
        Cache::flush();
        $site = StatusProbeController::result('schedule', true);
        $this->assertSame('Scheduled tasks run only while people visit the site', $site['label']);
        $this->assertSame('warn', $site['status']);
        $this->assertSame('site', $site['data']['source']);
        $this->assertNotNull($site['action']);

        LaravelSchedulerCron::recordCronRun();
        Cache::flush();
        $cron = StatusProbeController::result('schedule', true);
        $this->assertSame('Scheduled tasks are running', $cron['label']);
        $this->assertSame('ok', $cron['status']);
        $this->assertNull($cron['action']);
    }

    public function test_the_scheduled_tasks_page_shows_who_starts_them_and_the_line_to_add(): void
    {
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);
        Cache::flush();
        $answer = fn (string $probe, array $data): array => ['ok' => true, 'probe' => $probe, 'status' => 'ok', 'label' => $probe, 'detail' => null,
            'data' => $data, 'action' => null, 'checked_at' => now()->toIso8601String(), 'ms' => 1];
        Cache::put('admin.status.schedule.en', $answer('schedule', ['tasks' => [], 'count' => 0]), 300);
        Cache::put('admin.status.php.en', $answer('php', ['path' => PHP_BINARY, 'usable' => true]), 600);
        file_put_contents(storage_path(LaravelSchedulerCron::CRON_LOG), "a run of the cron\n");
        file_put_contents(storage_path(LaravelSchedulerCron::SITE_LOG), "a run after a visit\n");
        $admin = User::factory()->admin()->create();

        $page = $this->actingAs($admin)->get(route('admin.cron.index'))->assertOk()
            ->assertSee('How the scheduled tasks start')
            ->assertSee('They are not running. Add the cron line below.')
            ->assertSee('a run of the cron')
            ->assertSee('a run after a visit')
            ->assertSee('Started by the site (after visits and by Run Scheduler Now)')
            ->assertDontSee('Checking...');

        if (DIRECTORY_SEPARATOR === '\\') {
            // The command that creates the site's task: the Task Scheduler runs PHP itself (judge round 1b BS-RV-06).
            $page->assertSee("Create this site's task in the Windows Task Scheduler with this command (once, in a Command Prompt):")
                ->assertSee(LaravelSchedulerCron::windowsTaskCommand(PHP_BINARY))
                ->assertDontSee('If your panel runs cron jobs as root, use this command instead:');
        } else {
            $page->assertSee('Cron line (every minute):')->assertSee(LaravelSchedulerCron::lockPath())
                ->assertSee('If your panel runs cron jobs as root, use this command instead:');
        }

        $this->privately('writeStamp', 'framework/scheduler-web.timestamp');
        $this->actingAs($admin)->get(route('admin.cron.index'))->assertOk()
            ->assertSee('Only visits to the site start them now');

        LaravelSchedulerCron::recordCronRun();
        $this->actingAs($admin)->get(route('admin.cron.index'))->assertOk()
            ->assertSee("The server's cron starts them every minute.");
    }

    // ------------------------------------------------------------------ R1B-005: the logs

    public function test_a_log_is_cut_to_its_newest_whole_lines_in_place(): void
    {
        $log = storage_path('logs/test-cap.log');
        $lines = [];
        for ($i = 1; $i <= 100000; $i++) {
            $lines[] = sprintf('line %06d of the scheduler log', $i);
        }
        file_put_contents($log, implode("\n", $lines)."\n");
        $inode = @fileinode($log);
        $appender = fopen($log, 'ab');

        $this->assertTrue(AppendLog::cap($log, 1024 * 1024, 64 * 1024));
        clearstatcache();
        $this->assertLessThanOrEqual(64 * 1024, filesize($log));
        $kept = file($log, FILE_IGNORE_NEW_LINES);
        $this->assertSame('line 100000 of the scheduler log', end($kept));
        $this->assertMatchesRegularExpression('/^line \d{6} of the scheduler log$/', $kept[0], 'it starts with a whole line');
        $this->assertSame($inode, @fileinode($log), 'the same file');

        // A process that had it open for appending goes on at its new end.
        fwrite($appender, "written after the cut\n");
        fclose($appender);
        $this->assertSame('written after the cut', last(file($log, FILE_IGNORE_NEW_LINES)));
        $this->assertFalse(AppendLog::cap($log, 1024 * 1024, 64 * 1024), 'small enough now');

        $this->assertSame(['line 099999 of the scheduler log', 'line 100000 of the scheduler log', 'written after the cut'], AppendLog::tail($log, 3));
        file_put_contents($log, "one\ntwo\nthree");
        $this->assertSame(['two', 'three'], AppendLog::tail($log, 2));
        $this->assertSame(['one', 'two', 'three'], AppendLog::tail($log, 80));
        $this->assertSame([], AppendLog::tail(storage_path('logs/missing.log')));
    }

    public function test_a_log_is_created_owner_writable_only_and_loses_the_world_write_bit_of_older_versions(): void
    {
        $this->skipOnWindows('Unix file modes.');

        $umask = umask(0);

        try {
            $new = AppendLog::prepare(storage_path('logs/new.log'));
            $this->assertSame(storage_path('logs/new.log'), $new);
            $this->assertSame('644', substr(sprintf('%o', fileperms($new)), -3));

            $old = storage_path(LaravelSchedulerCron::CRON_LOG);
            file_put_contents($old, "1.2.x\n");
            chmod($old, 0666);
            $this->assertTrue(AppendLog::withoutWorldWrite($old));
            clearstatcache();
            $this->assertSame('664', substr(sprintf('%o', fileperms($old)), -3), 'only the world bit goes');
            $this->assertFalse(AppendLog::withoutWorldWrite($old));

            // The cron's run takes it off its own log too.
            chmod($old, 0666);
            LaravelSchedulerCron::recordCronRun();
            clearstatcache();
            $this->assertSame(0, fileperms($old) & 0002);
        } finally {
            umask($umask);
        }
    }

    public function test_background_runs_append_to_their_log_and_get_the_environment_given(): void
    {
        $this->skipOnWindows('The detached start of Unix (setsid, nohup).');

        $log = storage_path('logs/background.log');
        file_put_contents($log, "kept from before\n");
        chmod($log, 0666);
        // Each run writes its line in ONE write (an echo of several parts writes each part on its own: two runs at the
        // same moment interleaved them, "run run site\nsite\n": judge round 1b BS-RV-03), and the second run starts once
        // the first one's line is in the log, so the order is known.
        $command = [PHP_BINARY, '-r', 'echo "run ".getenv("AVDB_SCHEDULER_SOURCE")."\n";'];
        $runs = function (int $count) use ($log): void {
            $deadline = microtime(true) + 15;
            while (substr_count((string) file_get_contents($log), 'run site') < $count && microtime(true) < $deadline) {
                usleep(50000);
            }
        };

        $this->assertTrue(ShellRunner::runBackground($command, $log, null, [LaravelSchedulerCron::SOURCE_ENV => LaravelSchedulerCron::SOURCE_SITE]));
        $runs(1);
        $this->assertTrue(ShellRunner::runBackground($command, $log, null, [LaravelSchedulerCron::SOURCE_ENV => LaravelSchedulerCron::SOURCE_SITE]));
        $runs(2);

        $this->assertSame("kept from before\nrun site\nrun site\n", file_get_contents($log), 'appended, never wiped');
        clearstatcache();
        $this->assertSame(0, fileperms($log) & 0002, 'no longer world-writable');
    }

    // ------------------------------------------------------------------ R1B-006: the member mails

    public function test_the_member_mails_of_the_scheduler_run_once_per_minute_whoever_starts_it(): void
    {
        file_put_contents(storage_path('app/installed.json'), '{}');
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:05', config('app.timezone')));
        // Two scheduler runs = two processes, each with its schedule; they share the site's cache.
        $kernel = $this->app->make(ConsoleKernel::class);
        [$cron, $site] = [$kernel->resolveConsoleSchedule(), $kernel->resolveConsoleSchedule()];
        $find = fn (Schedule $schedule, string $command): ?Event => collect($schedule->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

        foreach (['premium:trial_end', 'premium:card_expiration'] as $command) {
            $event = $find($cron, $command);
            $this->assertNotNull($event, $command);
            $this->assertTrue($event->onOneServer, "{$command} runs on one server");

            // The cron's run and a run the site started in the same minute: only the first one runs it.
            $minute = Carbon::now();
            $this->assertTrue($cron->serverShouldRun($event, $minute), "{$command}: the first run of the minute");
            $this->assertFalse($site->serverShouldRun($find($site, $command), $minute->copy()->addSeconds(40)), "{$command}: a second run of the same minute");
        }

        // The other tasks are not tied to one run (they are safe to repeat, or guard themselves).
        $this->assertFalse($find($cron, 'video:published')->onOneServer);
    }

    public function test_no_task_is_scheduled_while_the_installer_is_open_or_before_the_installation(): void
    {
        $events = fn (): int => count($this->app->make(ConsoleKernel::class)->resolveConsoleSchedule()->events());

        $this->setEnv('APP_INSTALLED', 'true');
        $this->assertGreaterThan(10, $events(), 'an installed site without the lock (installed before it existed) runs them');

        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->assertSame(0, $events(), 'the installer is open again');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', null);

        $this->setEnv('APP_INSTALLED', 'false');
        $this->assertSame(0, $events(), 'a fresh upload with its .env.example copy');
        file_put_contents(storage_path('app/installed.json'), '{}');
        $this->assertGreaterThan(10, $events(), "the installer's lock");
        $this->assertTrue(LaravelSchedulerCron::siteInstalled());
    }

    // ------------------------------------------------------------------ helpers

    private function skipOnWindows(string $why): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped($why);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    private function privately(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(LaravelSchedulerCron::class, $method))->invoke(null, ...$arguments);
    }

    /**
     * The account's check of this site's crontab line: forgotten (the next migrateCronLine() reads the crontab), or
     * counted as done ($done: the account's real crontab is never asked).
     */
    private function forgetLineCheck(bool $done = false): void
    {
        foreach (glob($this->storage.'/framework/scheduler-cron-line-*.json') ?: [] as $state) {
            unlink($state);
        }

        if ($done) {
            $account = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'user';
            file_put_contents($this->storage.'/framework/scheduler-cron-line-'.$account.'.json', json_encode([
                'key' => sha1($this->marker().'|'.LaravelSchedulerCron::lockPath()), 'result' => 'absent', 'at' => time(),
            ]));
        }
    }

    /** The last check of the crontab line as if it had been made $seconds earlier. */
    private function ageLineCheck(int $seconds): void
    {
        foreach (glob($this->storage.'/framework/scheduler-cron-line-*.json') ?: [] as $state) {
            $data = json_decode((string) file_get_contents($state), true);
            $data['at'] = (int) $data['at'] - $seconds;
            file_put_contents($state, json_encode($data));
        }
    }

    /**
     * A command line split the way a Windows program's C runtime splits it (CommandLineToArgvW): blanks separate words,
     * double quotes group, a backslash before a quote makes it a quote of the word, other backslashes are themselves.
     *
     * @return array<int, string>
     */
    private function windowsArguments(string $line): array
    {
        $words = [];
        $word = '';
        $inWord = false;
        $quoted = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\') {
                $slashes = 0;
                while ($i < $length && $line[$i] === '\\') {
                    $slashes++;
                    $i++;
                }

                if ($i < $length && $line[$i] === '"') {
                    $word .= str_repeat('\\', intdiv($slashes, 2));
                    if ($slashes % 2 === 1) {
                        $word .= '"';
                    } else {
                        $quoted = ! $quoted;
                    }
                } else {
                    $word .= str_repeat('\\', $slashes);
                    $i--;
                }

                $inWord = true;

                continue;
            }

            if ($char === '"') {
                $quoted = ! $quoted;
                $inWord = true;

                continue;
            }

            if (($char === ' ' || $char === "\t") && ! $quoted) {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }

                continue;
            }

            $word .= $char;
            $inWord = true;
        }

        if ($inWord) {
            $words[] = $word;
        }

        return $words;
    }

    private function marker(): string
    {
        return 'AVDBClip Laravel Scheduler '.substr(sha1(base_path()), 0, 12);
    }

    /** The line 1.2.x wrote (LaravelSchedulerCron::currentUserShellCommand() of RC1), quoted the Unix way. */
    private function oldLine(string $base, string $php): string
    {
        $q = fn (string $value): string => "'".str_replace("'", "'\\''", $value)."'";
        $log = $q($base.'/storage/logs/schedule-run.log');
        $inner = 'cd '.$q($base).' && /bin/date >> '.$log.' && '.$q($php).' artisan schedule:run >> '.$log.' 2>&1';

        return '* * * * * /usr/bin/flock -n -o '.$q('/tmp/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', basename($base)).'-scheduler.lock').' /bin/sh -c '.$q($inner);
    }

    /**
     * A crontab command of the test's own, first on the PATH: it keeps the crontab in a file and every call in another.
     *
     * @return array{0: string, 1: string}
     */
    private function fakeCrontab(): array
    {
        $bin = $this->storage.'/bin';
        File::ensureDirectoryExists($bin);
        $file = $this->storage.'/crontab.txt';
        $calls = $this->storage.'/crontab-calls.txt';
        touch($calls);
        file_put_contents($bin.'/crontab', "#!/bin/sh\necho \"\$*\" >> '{$calls}'\nif [ \"\$1\" = \"-l\" ]; then\n  if [ -f '{$file}' ]; then cat '{$file}'; exit 0; fi\n  echo 'no crontab for this user' >&2; exit 1\nfi\ncp \"\$1\" '{$file}'\n");
        chmod($bin.'/crontab', 0755);
        $path = getenv('PATH');
        $this->envBefore['PATH'] = [$_ENV['PATH'] ?? null, $_SERVER['PATH'] ?? null, $path];
        $this->setEnv('PATH', $bin.':'.$path);

        return [$file, $calls];
    }
}
