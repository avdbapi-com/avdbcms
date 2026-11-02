<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\InstallRun;
use App\Support\Installer;
use App\Support\LaravelSchedulerCron;
use App\Support\SiteMailAddress;
use App\Support\SiteSettingsSource;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round 3 (RC2) findings of the installer and of a new site's defaults (lane IN):
 *  - R3-006: a new site's Alerts and Support e-mail are the administrator's address (the bundled data and the
 *    migrations left example.com placeholders, so failed jobs and scheduled tasks were mailed to noreply@example.com);
 *    on an updated site an example address that was never changed falls back to a real one, and Settings > Email shows
 *    the address the site really uses (IN-RV-03);
 *  - R3-036: the result page says what the installer really did about the scheduler's cron line, and asks for a line
 *    only when none was added - then the installer's own line (with its lock), for a hosting panel's cron job the
 *    command alone (IN-RV-01); the cron step keeps the signature the tests' doubles use (IN-RV-M1);
 *  - R3-037: the seeding gives the ids of an uninterrupted installation (the MySQL / MariaDB part with a real kill is in
 *    InstallerMysqlRunTest);
 *  - R3-038: no answer of the installer is indexed; an unfinished installation answers 503 with Retry-After, and while
 *    a site is being set up its other pages say so (503) instead of showing an empty or broken site;
 *  - R3-007: the result page's columns shrink to the screen on phones and tablets (the browser proof is in the lane's
 *    notes).
 * R3-008 is in StorageExposureProbeTest, R3-042 in InstallerResumableRunTest.
 */
class InstallerRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/install-r3-'.bin2hex(random_bytes(5)));
        @mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/.env', "APP_NAME=AVDBCMS\nAPP_KEY=\nAPP_INSTALLED=false\nINSTALLER_ALLOW_REINSTALL=true\nDB_USERNAME=\n");

        foreach (['APP_INSTALLED', 'INSTALLER_ALLOW_REINSTALL', 'SERVER_EMAIL', 'SUPPORT_EMAIL', 'PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
            $this->environment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->setEnv('APP_INSTALLED', 'auto');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => [$env, $server, $process]) {
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

        $this->removeTree($this->dir);

        parent::tearDown();
    }

    // R3-006

    public function test_a_new_site_mails_its_alerts_and_support_from_the_administrators_address(): void
    {
        // What each install mode starts with: the bundled SQL backup's rows, the migration's copy of .env.example.
        foreach (['bundled backup' => ['noreply@example.com', 'support@example.com'], 'migrations' => ['server@example.com', 'support@example.com'],
            'empty rows' => ['', ''], 'no rows' => [null, null]] as $case => [$server, $support]) {
            $this->startingRows($server, $support);

            $progress = $this->installCompletely($this->installer());

            $this->assertSame('finished', $progress['status'], $case);
            $this->assertSame('owner@mysite.net', $this->row('server_email'), $case.': Alerts e-mail');
            $this->assertSame('owner@mysite.net', $this->row('support_email'), $case.': Support e-mail');
        }

        // An imported backup of the owner's own site keeps its real addresses.
        $this->startingRows('alerts@owner-site.net', 'help@owner-site.net');
        $this->installCompletely($this->installer());
        $this->assertSame('alerts@owner-site.net', $this->row('server_email'));
        $this->assertSame('help@owner-site.net', $this->row('support_email'));
    }

    public function test_a_changed_administrator_email_reaches_the_alerts_and_support_addresses_too(): void
    {
        // The settings step has run; then the Admin step is sent again with another e-mail (a typo put right): the
        // account, settings and file steps run again, and what the installer itself wrote follows the new address.
        foreach (['bundled backup' => ['noreply@example.com', 'support@example.com', 'right-owner@mysite.net', 'right-owner@mysite.net'],
            'own backup' => ['alerts@owner-site.net', 'help@owner-site.net', 'alerts@owner-site.net', 'help@owner-site.net']] as $case => [$server, $support, $wantServer, $wantSupport]) {
            $this->startingRows($server, $support);
            @unlink($this->dir.'/installed.json');
            @unlink($this->dir.'/run.json');
            DB::table('categories')->delete();
            DB::table('plans')->delete();
            $installer = $this->installer();
            $installer->startRun($this->payload(), 'owner-token');
            $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status'], $case);

            $note = json_decode((string) file_get_contents($this->dir.'/run.json'), true);
            $this->assertStringNotContainsString('owner@mysite.net', json_encode($note['mail_filled'] ?? []), $case.': the note keeps no address');
            if ($case === 'bundled backup') {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($note['mail_filled']['server_email'] ?? ''), $case);
                $this->assertSame('owner@mysite.net', $this->row('server_email'), $case);
            }

            $changed = $this->payload();
            $changed['admin']['email'] = 'right-owner@mysite.net';
            $status = null;
            for ($request = 0; $request < 5 && ! in_array($status, ['finished', 'failed'], true); $request++) {
                $status = $installer->runStep($changed, 'owner-token', false, true)['status'];
            }

            $this->assertSame('finished', $status, $case);
            $this->assertSame('right-owner@mysite.net', $this->row('contact_email'), $case);
            $this->assertSame($wantServer, $this->row('server_email'), $case.': Alerts e-mail');
            $this->assertSame($wantSupport, $this->row('support_email'), $case.': Support e-mail');
        }
    }

    public function test_the_failure_mails_of_the_queue_worker_and_the_scheduler_reach_the_administrator(): void
    {
        $this->startingRows('noreply@example.com', 'support@example.com');
        $this->installCompletely($this->installer());

        // A console process of the new site (the worker, the scheduler) applies the admin's settings ...
        $this->setEnv('SERVER_EMAIL', 'server@example.com');
        $this->applySiteSettings();
        $this->assertSame('owner@mysite.net', config('mail.server_mail'));
        $this->assertSame('owner@mysite.net', config('mail.support_mail'));

        // ... and a job that failed for good and a scheduled task that failed are mailed there.
        $sent = $this->failureMails();
        $this->assertSame(['owner@mysite.net', 'owner@mysite.net'], array_column($sent, 'to'), json_encode($sent));
        $this->assertStringContainsString('FAILED', $sent[0]['subject']);
        $this->assertStringContainsString('FAILED', $sent[1]['subject']);
    }

    public function test_an_updated_sites_example_alert_address_falls_back_to_a_real_one(): void
    {
        // A 1.2.x site whose owner never changed the bundled noreply@example.com: .env's address (the installer wrote the
        // administrator's there), else the contact e-mail. Nothing stored changes.
        Setting::set('contact_email', 'contact@owner-site.net', 'general');
        $this->startingRows('noreply@example.com', 'support@example.com');

        $this->setEnv('SERVER_EMAIL', 'admin@owner-site.net');
        $this->setEnv('SUPPORT_EMAIL', 'admin@owner-site.net');
        $this->applySiteSettings();
        $this->assertSame('admin@owner-site.net', config('mail.server_mail'));
        $this->assertSame('admin@owner-site.net', config('mail.support_mail'));

        $this->setEnv('SERVER_EMAIL', 'server@example.com');
        $this->setEnv('SUPPORT_EMAIL', 'support@example.com');
        $this->applySiteSettings();
        $this->assertSame('contact@owner-site.net', config('mail.server_mail'), '.env has the example too: the contact e-mail');
        $this->assertSame('contact@owner-site.net', config('mail.support_mail'));
        $this->assertSame('noreply@example.com', $this->row('server_email'), 'the stored setting stays as it is');

        // The admin's own choice is used as it is (also an address of a reserved test domain), an empty one falls back.
        $this->startingRows('alerts@mysite.test', '');
        $this->applySiteSettings();
        $this->assertSame('alerts@mysite.test', config('mail.server_mail'));
        $this->assertSame('contact@owner-site.net', config('mail.support_mail'));

        $this->assertTrue(SiteMailAddress::isShippedExample(' Noreply@Example.com '));
        $this->assertFalse(SiteMailAddress::isShippedExample('noreply@example.org'));
        $this->assertNull(SiteMailAddress::resolve(null, 'R3_006_NOT_SET', null), 'nothing real anywhere: as before');
        $this->assertSame('support@example.com', SiteMailAddress::resolve('support@example.com', 'R3_006_NOT_SET', 'admin@example.com'));
    }

    public function test_the_email_settings_page_shows_the_addresses_the_site_really_uses(): void
    {
        // An updated 1.2.x site whose Alerts / Support e-mail still hold the bundled samples (or nothing): the site mails
        // .env's address, else the contact e-mail (above), and Settings > Email shows exactly that address, not one the
        // site does not use (round 3 IN-RV-03). The admin's own address is shown as it is. Nothing stored changes.
        $admin = User::factory()->admin()->create();
        Setting::set('contact_email', 'contact@owner-site.net', 'general');
        $this->startingRows('noreply@example.com', 'support@example.com');
        $this->setEnv('SERVER_EMAIL', 'admin@owner-site.net');
        $this->setEnv('SUPPORT_EMAIL', 'support@example.com');

        config(['admin.ui' => 'v2']);
        $page = (string) $this->actingAs($admin)->get('/admin/settings/email')->assertOk()->getContent();
        $this->assertSame('admin@owner-site.net', $this->fieldValue($page, 'server_email'), 'the Alerts e-mail: the address of .env');
        $this->assertSame('contact@owner-site.net', $this->fieldValue($page, 'support_email'), 'the Support e-mail: .env has the sample too, so the contact e-mail');
        $this->assertMatchesRegularExpression('#id="server_email-row"[^>]*data-default-same#', $page, 'its default is that address too: no "Default: ... Reset" line');
        $this->assertDoesNotMatchRegularExpression('#id="(server|support)_email-row"[^>]*data-default="[^"]*@example\.com"#', $page, 'no sample address as their default either');
        $this->assertSame('noreply@example.com', $this->row('server_email'), 'nothing stored changed');

        // The admin's own address stays as it is, and its "Default" names the address the site uses without it.
        $this->startingRows('alerts@owner-site.net', '');
        $page = (string) $this->actingAs($admin)->get('/admin/settings/email')->assertOk()->getContent();
        $this->assertSame('alerts@owner-site.net', $this->fieldValue($page, 'server_email'));
        $this->assertMatchesRegularExpression('#id="server_email-row"[^>]*data-default="admin@owner-site\.net"#', $page);
        $this->assertSame('contact@owner-site.net', $this->fieldValue($page, 'support_email'), 'an empty Support e-mail: the address the site uses');

        // The old settings page (an admin who chose the old look) says the same.
        config(['admin.ui' => 'v1']);
        $this->startingRows('noreply@example.com', 'support@example.com');
        $old = (string) $this->actingAs($admin)->get('/admin/settings?tab=email')->assertOk()->getContent();
        $this->assertSame('admin@owner-site.net', $this->fieldValue($old, 'server_email'));
        $this->assertSame('contact@owner-site.net', $this->fieldValue($old, 'support_email'));
    }

    // R3-036

    public function test_the_result_page_says_the_cron_line_was_added_and_asks_for_no_second_one(): void
    {
        // One installer for all the cases: the wizard's controller keeps the one it was made with.
        $installer = $this->installer();

        foreach ([
            'added' => ['was added to the crontab of the account PHP runs as', 'from the cron line the installer added to the crontab of the account PHP runs as.'],
            'current' => ['already has this site\'s cron line', 'from the cron line that the crontab of the account PHP runs as already had.'],
            'updated' => ['older cron line in this account\'s crontab was replaced', 'from the cron line the installer put in the crontab of the account PHP runs as, in place of an older one.'],
        ] as $state => [$words, $aside]) {
            $installer->cronResult = ['available' => true, 'installed' => true, 'changed' => $state !== 'current', 'message' => '', 'command' => null, 'state' => $state];
            $page = $this->resultPage($installer);

            $this->assertStringContainsString(htmlspecialchars($words, ENT_QUOTES), $page, $state);
            $this->assertStringContainsString($aside, $page, $state.': the words beside the result');
            $this->assertStringContainsString('data-scheduler="running"', $page, $state);
            $this->assertStringContainsString('Nothing to add: a second cron line for this site in your hosting panel would run them twice every minute.', $page, $state);
            $this->assertStringNotContainsString('id="cron-command"', $page, $state.': no line to add');
            $this->assertStringNotContainsString('Scheduler configured', $page, $state);
            $this->assertStringContainsString('XVIDAPI imports remain disabled until started or enabled by an admin.', $page, $state);
        }
    }

    public function test_where_no_line_was_added_the_result_page_says_why_and_shows_the_installers_own_line(): void
    {
        $line = LaravelSchedulerCron::lineFor(PHP_BINARY);
        $command = LaravelSchedulerCron::commandFor(PHP_BINARY);
        // One installer for all the cases: the wizard's controller keeps the one it was made with.
        $installer = $this->installer();

        foreach ([
            'windows' => ['The site can add its cron line by itself only on Linux and other Unix servers.', 'On Windows the installer cannot add the scheduler by itself.'],
            'no_processes' => ['PHP may not start programs on this server, so the site could not add its cron line by itself.', 'The installer could not add the scheduler\'s cron line. PHP may not start programs on this server, so the site could not add its cron line by itself.'],
            'no_crontab' => ['The crontab command was not found on this server.', 'The installer could not add the scheduler\'s cron line. The crontab command was not found on this server.'],
            'failed' => ['crontab: permission denied', 'The installer could not add the scheduler\'s cron line. crontab: permission denied.'],
            'off' => ['This site\'s line in the crontab is switched off (it starts with #), so it was left as it is.', 'is switched off (it starts with #), so the installer left it as it is'],
            'custom' => ['This site\'s line in the crontab was changed by hand, so it was left as it is.', 'was changed by hand, so the installer left it as it is'],
        ] as $state => [$message, $words]) {
            $installer->cronResult = ['available' => in_array($state, ['off', 'custom'], true), 'installed' => false, 'changed' => false,
                'message' => $message, 'command' => null, 'state' => $state, 'php' => PHP_BINARY];
            $progress = null;
            $page = $this->resultPage($installer, $progress);

            $this->assertStringContainsString($words, $page, $state.': the words of the page itself');
            $this->assertStringContainsString('data-scheduler="add"', $page, $state);
            $this->assertStringNotContainsString('Scheduler configured', $page, $state);
            $this->assertStringContainsString('Scheduled tasks: ', implode(' ', $progress['notes']), $state.': a note, not an OK line');
            $this->assertStringNotContainsString('Scheduled tasks: ', implode(' ', $progress['messages']), $state);

            if (PHP_OS_FAMILY === 'Windows') {
                // the Task Scheduler's command, whatever the reason
                $this->assertStringContainsString('<pre id="cron-command" class="config-snippet">'.e($line).'</pre>', $page, $state.': the installer\'s own task');
                $this->assertStringContainsString('schtasks /Create /SC MINUTE', $page, $state);
                $this->assertStringNotContainsString('id="cron-command-root"', $page, $state);

                continue;
            }

            if ($state === 'windows') {
                // (that answer comes only from Windows: its words above are all there is to see here)
                continue;
            }

            $this->assertStringContainsString('<pre id="cron-command-root" class="config-snippet">'.e(LaravelSchedulerCron::panelLineFor(PHP_BINARY)).'</pre>', $page, $state);
            $this->assertStringStartsWith('/usr/bin/flock -n -o ', $command, 'the command with its lock');
            $this->assertSame('* * * * * '.$command, $line, 'the crontab line is that command after its schedule');

            if (in_array($state, ['off', 'custom'], true)) {
                // the owner's own line in the account's crontab: the whole crontab line, to put in its place
                $this->assertStringContainsString('<pre id="cron-command" class="config-snippet">'.e($line).'</pre>', $page, $state.': the installer\'s own line');
                $this->assertStringContainsString('<span>Cron line, every minute</span>', $page, $state);
                $this->assertStringNotContainsString('(crontab -e)', $page, $state);
            } else {
                // a cron job of a hosting panel: the command alone - a panel takes "every minute" in fields of its own and
                // its shell would read the "* * * * *" of a crontab line as file names (IN-RV-01)
                $this->assertStringContainsString('<pre id="cron-command" class="config-snippet">'.e($command).'</pre>', $page, $state.': the command of the cron job');
                $this->assertStringNotContainsString('<pre id="cron-command" class="config-snippet">* * * * *', $page, $state);
                $this->assertStringContainsString('<span>Cron job command, every minute</span>', $page, $state);
                $this->assertStringContainsString('Add one cron job in your hosting panel that runs this command every minute as the site&#039;s user', $page, $state);
                $this->assertStringContainsString('In the account\'s crontab (crontab -e) the line is <code style="white-space: nowrap">* * * * *</code> and then the command above.', $page, $state);
                $this->assertStringContainsString('Add the cron job shown under Recommended After Install.', implode(' ', $progress['notes']), $state);
            }
        }
    }

    public function test_the_command_for_a_hosting_panel_runs_where_the_panel_runs_it(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! is_executable('/usr/bin/flock') || ! function_exists('proc_open')) {
            $this->markTestSkipped('Needs a Unix shell with /usr/bin/flock (the lock of the cron line).');
        }

        // The site's storage in this test's folder (the line's lock and log go there) and /bin/true in place of PHP: what
        // runs is exactly the command the result page shows, as a hosting panel runs a cron job - "/bin/sh -c <command>"
        // in the account's home folder, which has files of its own (the "* * * * *" of a crontab line became their names
        // there: "access-logs: not found", the scheduler never ran; round 3 IN-RV-01).
        foreach (['app', 'framework', 'logs'] as $folder) {
            @mkdir($this->dir.'/storage/'.$folder, 0777, true);
        }
        $this->app->useStoragePath($this->dir.'/storage');
        $home = $this->dir.'/home';
        @mkdir($home.'/public_html', 0777, true);
        foreach (['access-logs', 'etc', 'mail', 'tmp'] as $entry) {
            touch($home.'/'.$entry);
        }

        $installer = $this->installer();
        $installer->cronResult = ['available' => false, 'installed' => false, 'changed' => false, 'command' => null, 'state' => 'no_processes', 'php' => '/bin/true',
            'message' => 'PHP may not start programs on this server, so the site could not add its cron line by itself.'];
        $page = $this->resultPage($installer);

        $this->assertSame(1, preg_match('#<pre id="cron-command" class="config-snippet">(.*?)</pre>#s', $page, $box), 'the page shows a command');
        $shown = html_entity_decode($box[1], ENT_QUOTES | ENT_HTML5);
        $job = new \Symfony\Component\Process\Process(['/bin/sh', '-c', $shown], $home, ['PATH' => '/usr/bin:/bin']);
        $job->run();

        $this->assertSame(0, $job->getExitCode(), 'the panel\'s shell runs it: '.$job->getErrorOutput());
        $this->assertFileExists($this->dir.'/storage/logs/schedule-run.log');
        $this->assertNotSame('', trim((string) file_get_contents($this->dir.'/storage/logs/schedule-run.log')), 'it ran: its date line is in the cron log');
    }

    public function test_the_cron_step_keeps_the_signature_its_doubles_use_and_one_that_says_nothing_leaves_no_line(): void
    {
        // The tests of other classes replace installSchedulerCron() with "protected function installSchedulerCron(): void {}"
        // (its signature since 1.2.x): another return type would make each of them a fatal error that ends the whole run
        // with no report (round 3 merge, IN-RV-M1).
        $this->assertSame('void', (string) (new \ReflectionMethod(Installer::class, 'installSchedulerCron'))->getReturnType());

        // Such a double says nothing about the line: the result has no line about the scheduled tasks and the page
        // points to the admin's page - never "added", never "could not be added".
        $installer = $this->installer();
        $installer->cronSilent = true;
        $progress = null;
        $page = $this->resultPage($installer, $progress);

        $this->assertNull($progress['scheduler']);
        $this->assertStringNotContainsString('Scheduled tasks:', implode(' ', array_merge($progress['messages'], $progress['notes'])));
        $this->assertStringContainsString('data-scheduler="unknown"', $page);
        $this->assertStringContainsString('XVIDAPI imports remain disabled until started or enabled by an admin.', $page);
    }

    public function test_the_real_cron_step_tells_the_last_step_what_it_did(): void
    {
        // No double: LaravelSchedulerCron::ensureInstalled() itself, its answer kept for the result page. On Windows it
        // says why it cannot add a line; elsewhere a crontab command of this test (first on the PATH, it keeps the
        // crontab in a file) takes the line, and the site's storage is this test's folder.
        $windows = PHP_OS_FAMILY === 'Windows';

        if (! $windows) {
            if (! function_exists('proc_open') || ! function_exists('exec')) {
                $this->markTestSkipped('This PHP may not start programs.');
            }

            foreach (['app', 'framework', 'logs'] as $folder) {
                @mkdir($this->dir.'/storage/'.$folder, 0777, true);
            }
            $this->app->useStoragePath($this->dir.'/storage');
            $bin = $this->dir.'/bin';
            @mkdir($bin, 0777, true);
            file_put_contents($bin.'/crontab', "#!/bin/sh\nF='{$bin}/tab'\nif [ \"\$1\" = '-l' ]; then [ -f \"\$F\" ] && cat \"\$F\" && exit 0; echo 'no crontab for test' >&2; exit 1; fi\ncat \"\$1\" > \"\$F\"\n");
            chmod($bin.'/crontab', 0755);
            $this->setEnv('PATH', $bin.':'.getenv('PATH'));
        }

        $installer = $this->installer();
        $installer->realCron = true;
        $progress = null;
        $page = $this->resultPage($installer, $progress);

        $this->assertIsArray($progress['scheduler']);
        $this->assertSame($windows ? 'windows' : 'added', $progress['scheduler']['state']);
        $this->assertSame(! $windows, $progress['scheduler']['running']);

        if ($windows) {
            $this->assertStringContainsString('Scheduled tasks: no cron line was added. The site can add its cron line by itself only on Linux and other Unix servers.', implode(' ', $progress['notes']));
            $this->assertStringContainsString('schtasks /Create /SC MINUTE', $page);
        } else {
            $this->assertContains('Scheduled tasks: this site\'s cron line was added to the crontab of the account PHP runs as; the server starts them every minute.', $progress['messages']);
            $this->assertStringContainsString('Nothing to add: a second cron line for this site in your hosting panel would run them twice every minute.', $page);
            $this->assertStringContainsString(LaravelSchedulerCron::lockPath(), (string) file_get_contents($this->dir.'/bin/tab'), 'the line is in the crontab');
        }
    }

    public function test_a_cron_line_that_cannot_be_added_never_stops_the_last_step(): void
    {
        $installer = $this->installer();
        $installer->cronThrows = true;
        $progress = $this->installCompletely($installer);

        $this->assertSame('finished', $progress['status']);
        $this->assertFileExists($this->dir.'/installed.json');
        $this->assertStringContainsString('Scheduled tasks: no cron line was added. The cron line could not be added: crontab exploded.', implode(' ', $progress['notes']));
        $this->assertFalse($progress['scheduler']['running']);
    }

    public function test_where_no_line_was_added_on_windows_the_page_asks_for_the_task_and_not_for_a_cron_job(): void
    {
        // One installer for all the cases: the wizard's controller keeps the one it was made with.
        $installer = $this->installer();
        $installer->cronResult = ['available' => true, 'installed' => false, 'changed' => false, 'message' => 'The cron line could not be added: crontab exploded.',
            'command' => null, 'state' => 'failed', 'php' => PHP_BINARY];
        $windowsWords = 'Create the site&#039;s task in the Windows Task Scheduler once, with this command in a Command Prompt';
        $cronWords = 'Add one cron job in your hosting panel that runs this command every minute as the site&#039;s user';

        $installer->windows = true;
        $page = $this->resultPage($installer);
        $this->assertStringContainsString('The installer could not add the scheduler\'s cron line. The cron line could not be added: crontab exploded.', $page);
        $this->assertStringContainsString($windowsWords, $page);
        $this->assertStringNotContainsString($cronWords, $page);
        $this->assertStringContainsString('Command Prompt, once', $page);
        $this->assertStringNotContainsString('(crontab -e)', $page);

        $installer->windows = false;
        $page = $this->resultPage($installer);
        $this->assertStringContainsString($cronWords, $page);
        $this->assertStringNotContainsString($windowsWords, $page);
        $this->assertStringContainsString('<span>Cron job command, every minute</span>', $page);
        $this->assertStringNotContainsString('Command Prompt, once', $page);
    }

    public function test_ensure_installed_says_what_it_did(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $result = LaravelSchedulerCron::ensureInstalled(PHP_BINARY);
            $this->assertSame('windows', $result['state']);
            $this->assertFalse($result['installed']);

            return;
        }

        if (! function_exists('proc_open') || ! function_exists('exec')) {
            $this->markTestSkipped('This PHP may not start programs.');
        }

        // A storage folder of this test (the line's lock and log live there) and a crontab command of this test, first
        // on the PATH: it keeps the crontab in a file.
        foreach (['app', 'framework', 'logs'] as $folder) {
            @mkdir($this->dir.'/storage/'.$folder, 0777, true);
        }
        $this->app->useStoragePath($this->dir.'/storage');
        $bin = $this->dir.'/bin';
        @mkdir($bin, 0777, true);
        file_put_contents($bin.'/crontab', "#!/bin/sh\nF='{$bin}/tab'\nif [ \"\$1\" = '-l' ]; then [ -f \"\$F\" ] && cat \"\$F\" && exit 0; echo 'no crontab for test' >&2; exit 1; fi\ncat \"\$1\" > \"\$F\"\n");
        chmod($bin.'/crontab', 0755);
        $this->setEnv('PATH', $bin.':'.getenv('PATH'));

        $this->assertSame('added', LaravelSchedulerCron::ensureInstalled(PHP_BINARY)['state']);
        $this->assertSame('current', LaravelSchedulerCron::ensureInstalled(PHP_BINARY)['state']);
        file_put_contents($bin.'/tab', str_replace(LaravelSchedulerCron::lineFor(PHP_BINARY), '# '.LaravelSchedulerCron::lineFor(PHP_BINARY), (string) file_get_contents($bin.'/tab')));
        $this->assertSame('off', LaravelSchedulerCron::ensureInstalled(PHP_BINARY)['state']);
    }

    public function test_the_line_the_installer_shows_is_its_own_line_with_the_lock(): void
    {
        $this->assertSame(LaravelSchedulerCron::lineFor(\App\Support\PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']) ?: 'php'), (new Installer())->schedulerCronCommand());
    }

    // R3-037

    public function test_the_seeding_sets_the_next_ids_back_before_its_transaction(): void
    {
        $source = (string) file_get_contents(app_path('Support/Installer.php'));
        $method = new \ReflectionMethod(Installer::class, 'jobSeed');
        $body = implode("\n", array_slice(explode("\n", $source), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $this->assertLessThan(strpos($body, '->beginTransaction()'), strpos($body, '$this->resetSeedAutoIncrements('), 'ALTER TABLE ends a transaction: before it');

        // On SQLite (which gives a rolled-back row's number back by itself) nothing is sent to the database.
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        (new \ReflectionMethod(Installer::class, 'resetSeedAutoIncrements'))->invoke(new Installer(), DB::connection());
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql): bool => stripos($sql, 'alter table') !== false)));

        // A resumed seeding on the test database: the basic categories and plans with the ids of an uninterrupted one.
        $progress = $this->installCompletely($this->installer());
        $this->assertSame('finished', $progress['status']);
        $this->assertSame(range(1, 16), DB::table('categories')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([1, 2], DB::table('plans')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    // R3-038

    public function test_no_answer_of_the_installer_is_indexed(): void
    {
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->app->instance(Installer::class, $this->installer());

        foreach (['/install', '/install?step=requirements', '/install?step=database'] as $address) {
            $answer = $this->get($address)->assertOk();
            $this->assertSame('noindex, nofollow', $answer->headers->get('X-Robots-Tag'), $address);
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $answer->getContent(), $address);
        }

        $this->assertSame('noindex, nofollow', $this->post('/install/introduction')->assertRedirect()->headers->get('X-Robots-Tag'));
        $this->assertSame('noindex, nofollow', $this->getJson('/install?step=installing')->headers->get('X-Robots-Tag'));

        // The locked page of an installed site, and its refused POST.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');
        file_put_contents($this->dir.'/installed.json', '{}');
        $locked = $this->get('/install')->assertOk()->assertSee('Already Installed');
        $this->assertSame('noindex, nofollow', $locked->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $locked->getContent());
        $this->assertSame('noindex, nofollow', $this->post('/install/introduction')->assertForbidden()->headers->get('X-Robots-Tag'));
    }

    public function test_an_unfinished_installation_answers_503_with_retry_after(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);

        $this->get('/')->assertRedirect('/install');
        $page = $this->get('/install')->assertStatus(503)->assertSee('Installation Not Finished');
        $this->assertSame('3600', $page->headers->get('Retry-After'));
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $page->getContent());

        // The owner's own pages answer as before (the progress page's script reads their status).
        $this->withSession(['installer.run_token' => 'owner-token', 'installer.database' => $this->payload()['database'],
            'installer.admin' => $this->payload()['admin'], 'installer.app' => $this->payload()['app']]);
        $this->get('/install?step=installing')->assertOk();
        $this->getJson('/install?step=installing')->assertOk()->assertJsonPath('status', 'working');
    }

    public function test_while_a_site_is_being_set_up_its_pages_say_so_with_503(): void
    {
        $this->app->instance(Installer::class, $this->installer());

        // A fresh upload: its .env (a copy of .env.example) says APP_INSTALLED=false, no lock.
        $this->setEnv('APP_INSTALLED', 'false');
        foreach (['/trend', '/categories', '/login', '/fr/trend'] as $address) {
            $answer = $this->get($address)->assertStatus(503);
            $this->assertSame('3600', $answer->headers->get('Retry-After'), $address);
            $this->assertSame('noindex, nofollow', $answer->headers->get('X-Robots-Tag'), $address);
            $this->assertStringContainsString('This site is being set up. Please come back later.', (string) $answer->getContent(), $address);
            $this->assertStringContainsString('href="'.url('/install').'"', (string) $answer->getContent(), $address);
        }
        $this->getJson('/api/videos')->assertStatus(503)->assertJsonPath('message', 'This site is being set up. Please come back later.');
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk();
        $this->get('/_avdbcms/check/clean-urls/'.str_repeat('a', 32))->assertNotFound();

        // An installation of this installer in progress (its note), the .env not saying false: the same.
        $this->setEnv('APP_INSTALLED', 'auto');
        $this->get('/trend')->assertOk();
        $this->installer()->startRun($this->payload(), 'owner-token');
        $this->get('/trend')->assertStatus(503);

        // With the installer's lock every page is the site's again.
        file_put_contents($this->dir.'/installed.json', '{}');
        $this->get('/trend')->assertOk();
    }

    public function test_a_reinstallation_that_rebuilds_the_sites_own_database_says_being_set_up_too(): void
    {
        $installer = $this->installer();
        $this->app->instance(Installer::class, $installer);
        @unlink($this->dir.'/run.json');
        DB::table('categories')->delete();
        DB::table('plans')->delete();
        file_put_contents($this->dir.'/installed.json', '{}');
        $this->get('/trend')->assertOk();

        // The owner's reinstallation (INSTALLER_ALLOW_REINSTALL) into the database the site's .env names, begun (its
        // steps up to the last one done) and not finished: the installed site is gone until it ends.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $database = $this->payload()['database'];
        $this->setEnv('DB_HOST', $database['host']);
        $this->setEnv('DB_PORT', $database['port']);
        $this->setEnv('DB_DATABASE', $database['database']);
        $this->setEnv('DB_USERNAME', $database['username']);
        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);
        $this->assertTrue($installer->beingSetUp());
        $answer = $this->get('/trend')->assertStatus(503);
        $this->assertSame('3600', $answer->headers->get('Retry-After'));

        // Into another database the site keeps its own pages, also with the switch taken away again.
        $this->setEnv('DB_DATABASE', 'the_live_sites_database');
        $this->assertFalse($installer->beingSetUp());
        $this->get('/trend')->assertOk();
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');
        $this->get('/trend')->assertOk();

        // Its own database again, and once it has finished the site is the site again.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->setEnv('DB_DATABASE', $database['database']);
        $this->assertTrue($installer->beingSetUp());
        $this->assertSame('finished', $installer->runStep($this->payload(), 'owner-token', false, true)['status']);
        $this->assertFalse($installer->beingSetUp());
        $this->get('/trend')->assertOk();
    }

    // R3-007

    public function test_the_result_page_columns_shrink_to_the_screen_on_phones_and_tablets(): void
    {
        $installer = $this->installer();
        $installer->cronResult = ['available' => false, 'installed' => false, 'changed' => false, 'message' => 'no crontab', 'command' => null, 'state' => 'no_crontab', 'php' => PHP_BINARY];
        $page = $this->resultPage($installer);

        $this->assertMatchesRegularExpression('/@media \(max-width: 980px\) \{[^@]*\.hero, \.grid, \.grid\.equal, \.grid\.single \{ grid-template-columns: minmax\(0, 1fr\); \}/s', $page);
        $this->assertStringNotContainsString('.grid.single { grid-template-columns: 1fr; }', $page);
        $this->assertStringContainsString('.timeline-row > div { overflow-wrap: anywhere; }', $page);
        $this->assertStringContainsString('<button class="copy-btn" type="button" data-copy="cron-command">Copy</button>', $page);
    }

    // helpers

    /** The settings rows an installation starts with (null: no row). */
    private function startingRows(?string $server, ?string $support): void
    {
        foreach (['server_email' => $server, 'support_email' => $support] as $key => $value) {
            DB::table('settings')->where('key', $key)->delete();

            if ($value !== null) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'group' => 'email', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Cache::flush();
    }

    private function row(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    /** The value of the input field $key of an admin page (null: no such field). */
    private function fieldValue(string $page, string $key): ?string
    {
        return preg_match('#<input\b[^>]*\bid="'.preg_quote($key, '#').'"[^>]*\bvalue="([^"]*)"#', $page, $match) === 1
            ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }

    /** The admin's settings, applied the way a web request and a console process of the site apply them. */
    private function applySiteSettings(): void
    {
        Cache::flush();
        $this->app->getProvider(AppServiceProvider::class)->applySettings(SiteSettingsSource::console() ?? SiteSettingsSource::web());
    }

    /**
     * A job that failed for good and a scheduled task that failed, as the queue worker and the scheduler raise them:
     * the mails App\Listeners\BackgroundEventSubscriber sends (the array mailer keeps them).
     *
     * @return list<array{to: string, subject: string}>
     */
    private function failureMails(): array
    {
        config(['mail.default' => 'array']);
        Mail::purge('array');
        $payload = json_encode(['uuid' => 'r3-006', 'displayName' => 'App\\Jobs\\R3006Probe', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]);
        event(new JobFailed('database', new SyncJob($this->app, (string) $payload, 'database', 'default'), new \RuntimeException('a job failed for good')));
        event(new ScheduledTaskFailed($this->app->make(Schedule::class)->command('video:published'), new \RuntimeException('a scheduled task failed')));

        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($sent): array => [
            'to' => $sent->getOriginalMessage()->getTo()[0]->getAddress(),
            'subject' => (string) $sent->getOriginalMessage()->getSubject(),
        ])->values()->all();
    }

    /**
     * The whole installation on the test database, as the progress page drives it.
     *
     * @return array<string, mixed> the last progress
     */
    private function installCompletely(Installer $installer): array
    {
        @unlink($this->dir.'/installed.json');
        @unlink($this->dir.'/run.json');
        DB::table('categories')->delete();
        DB::table('plans')->delete();
        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);

        return $installer->runStep($this->payload(), 'owner-token', false, true);
    }

    /** The result page of an installation driven through the wizard's own routes. */
    private function resultPage(Installer $installer, ?array &$progress = null): string
    {
        @unlink($this->dir.'/installed.json');
        @unlink($this->dir.'/run.json');
        DB::table('categories')->delete();
        DB::table('plans')->delete();
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->app->instance(Installer::class, $installer);
        $this->withSession(['installer.database' => $this->payload()['database'], 'installer.admin' => $this->payload()['admin'], 'installer.app' => $this->payload()['app']]);

        $this->post('/install/install')->assertRedirect('/install?step=installing');
        $this->postJson('/install/run')->assertOk()->assertJsonPath('status', 'ready');
        $answer = $this->post('/install/run')->assertOk()->assertSee('Installation complete');
        $progress = $installer->lastProgress;
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');

        return (string) $answer->getContent();
    }

    private function installer(): Installer
    {
        return new class($this->dir) extends Installer
        {
            /** @var array<string, mixed>|null what installSchedulerCron() reports (null: added) */
            public ?array $cronResult = null;

            public bool $cronThrows = false;

            /** installSchedulerCron() reports nothing, as the doubles of other test classes (int-C4's "{}"). */
            public bool $cronSilent = false;

            /** The real installSchedulerCron() (LaravelSchedulerCron::ensureInstalled()). */
            public bool $realCron = false;

            /** Windows or not for the result page's words (null: this machine's). */
            public ?bool $windows = null;

            /** @var array<string, mixed>|null */
            public ?array $lastProgress = null;

            public function __construct(private string $testDir)
            {
            }

            public function runStep(array $payload, string $ownerToken, bool $retry = false, bool $finalize = false): array
            {
                return $this->lastProgress = parent::runStep($payload, $ownerToken, $retry, $finalize);
            }

            protected function envPath(): string
            {
                return $this->testDir.'/.env';
            }

            public function lockPath(): string
            {
                return $this->testDir.'/installed.json';
            }

            protected function runStatePath(): string
            {
                return $this->testDir.'/run.json';
            }

            protected function runLockPath(): string
            {
                return $this->testDir.'/run.lock';
            }

            protected function targetDatabasePdo(array $database): \PDO
            {
                return DB::connection()->getPdo();
            }

            protected function databaseObjects(\PDO $pdo, string $database): array
            {
                return [];
            }

            protected function applyRuntimeDatabaseConfig(array $database): void
            {
                // the test database stays the connection
            }

            protected function prepareFiles(InstallRun $run): void
            {
                $run->message('Files prepared (test).');
            }

            protected function onWindows(): bool
            {
                return $this->windows ?? parent::onWindows();
            }

            protected function installSchedulerCron(): void
            {
                if ($this->cronThrows) {
                    throw new \RuntimeException('crontab exploded');
                }

                if ($this->realCron) {
                    parent::installSchedulerCron();

                    return;
                }

                if (! $this->cronSilent) {
                    $this->schedulerCronResult = $this->cronResult ?? ['available' => true, 'installed' => true, 'changed' => true, 'message' => 'added (test)', 'command' => null, 'state' => 'added'];
                }
            }

            public function backupInfo(): array
            {
                return ['exists' => false, 'path' => null, 'filename' => 'example.sql', 'configured_filename' => 'example.sql', 'size' => '', 'source' => '', 'available' => []];
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'database' => ['host' => '127.0.0.9', 'port' => '3306', 'database' => 'installer_db_name', 'username' => 'installer_user', 'password' => 'the-db-password', 'mode' => 'migrate', 'overwrite' => false],
            'admin' => ['username' => 'site_owner', 'email' => 'owner@mysite.net', 'password' => 'Owner-pass-2026!'],
            'app' => ['name' => 'My site', 'url' => 'https://mysite.net', 'mail_mailer' => 'log', 'mail_host' => '', 'mail_port' => '587',
                'mail_username' => '', 'mail_password' => '', 'mail_encryption' => '', 'mail_from_address' => ''],
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
