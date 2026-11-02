<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstallRun;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * R1B-001 (round 1b judge, major): the "Run migrations and seeders" install did all of its database work in one request;
 * a host that ends requests after 30 s (PHP-FPM's request_terminate_timeout) killed it half way, and because .env already
 * said APP_INSTALLED=true (its restore guard was a shutdown function, which such a kill never runs) the site was left
 * locked with no admin. Now:
 *  - the database work runs in steps over several requests, each within a time budget, and a step the host ended is
 *    found out by the next one, which goes on from a safe point (Installer::startRun() / runStep(), App\Support\InstallRun);
 *  - the final .env (APP_INSTALLED=true, the new key) and the lock are written only after the database work and the
 *    admin account succeeded; INSTALLER_ALLOW_REINSTALL stays until then;
 *  - an installation that did not finish serves only its owner (the password of its database).
 * R1B-008: the project folder / .env is checked first in the Filesystem step.
 *
 * Here on the test database (SQLite); InstallerMysqlRunTest runs the real thing on MySQL / MariaDB with real kills.
 */
class InstallerResumableRunTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/install-run-'.bin2hex(random_bytes(5)));
        @mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/.env', "APP_NAME=AVDBCMS\nAPP_KEY=\nAPP_INSTALLED=false\nINSTALLER_ALLOW_REINSTALL=true\nDB_USERNAME=\n");

        foreach (['APP_INSTALLED', 'INSTALLER_ALLOW_REINSTALL', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
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

        if (is_dir($this->dir)) {
            @chmod($this->dir.'/.env', 0666);
            $this->removeTree($this->dir);
        }

        parent::tearDown();
    }

    // R1B-001: steps, then the final .env

    public function test_the_database_work_runs_first_and_env_and_the_lock_are_written_only_at_the_end(): void
    {
        $installer = $this->installer();
        $envBefore = (string) file_get_contents($this->dir.'/.env');

        $progress = $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('working', $progress['status']);
        $this->assertFileExists($this->dir.'/run.json');
        $this->assertSame(['prepare', 'migrate', 'seed', 'admin', 'settings', 'files', 'finish'], array_column($progress['phases'], 'key'));

        // The progress page's background steps: all the work, never .env or the lock.
        $progress = $installer->runStep($this->payload(), 'owner-token');
        $this->assertSame('ready', $progress['status']);
        $this->assertSame($envBefore, (string) file_get_contents($this->dir.'/.env'), '.env is untouched until the end');
        $this->assertFileDoesNotExist($this->dir.'/installed.json');
        $this->assertTrue(User::query()->where('username', 'site_owner')->where('is_admin', true)->exists(), 'the admin exists before .env is written');
        $this->assertSame(16, DB::table('categories')->count());

        $state = json_decode((string) file_get_contents($this->dir.'/run.json'), true);
        $this->assertNull($state['running'], 'a step that stopped by itself clears its note');
        $this->assertStringNotContainsString('Owner-pass', (string) file_get_contents($this->dir.'/run.json'), 'no password in the run file');

        // The page request after it: the final .env and the lock, then the run is over.
        $progress = $installer->runStep($this->payload(), 'owner-token', false, true);
        $this->assertSame('finished', $progress['status']);
        $env = \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'));
        $this->assertSame('true', $env['APP_INSTALLED']);
        $this->assertNotSame('', (string) $env['APP_KEY']);
        $this->assertArrayNotHasKey('INSTALLER_ALLOW_REINSTALL', $env, 'the reinstall switch is dropped only now');
        $this->assertFileExists($this->dir.'/installed.json');
        $this->assertFileDoesNotExist($this->dir.'/run.json');
        // The double of the cron step says nothing about the line: no line about the scheduled tasks (round 3 R3-036;
        // InstallerRoundThreeTest covers what the result page says for each answer).
        $this->assertSame(['.env file written for production.', 'Installer locked.', 'XVIDAPI imports remain disabled until started or enabled by an admin.'], array_slice($progress['messages'], -3));
        $this->assertContains('Database migrated and seeded.', $progress['messages']);
        $this->assertContains('Admin account created or updated.', $progress['messages']);
    }

    public function test_the_installer_keeps_no_shutdown_function_and_no_early_env(): void
    {
        $source = (string) file_get_contents(app_path('Support/Installer.php'));

        $this->assertStringNotContainsString('register_shutdown_function', $source, 'a host that ends the request runs no shutdown function');
        $this->assertSame(1, substr_count($source, '$this->writeEnvironment('), '.env is written in one place: finishRun()');
        $method = new \ReflectionMethod(Installer::class, 'finishRun');
        $body = implode("\n", array_slice(explode("\n", $source), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $this->assertStringContainsString('$this->writeEnvironment(', $body);
        $this->assertLessThan(strpos($body, '$this->writeLock('), strpos($body, '$this->writeEnvironment('), '.env first, then the lock');
    }

    public function test_the_same_installation_goes_on_and_another_one_replaces_it(): void
    {
        $installer = $this->installer();
        $first = $installer->startRun($this->payload(), 'owner-token');
        $id = json_decode((string) file_get_contents($this->dir.'/run.json'), true)['id'];

        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame($id, json_decode((string) file_get_contents($this->dir.'/run.json'), true)['id'], 'the same database and mode: the same run');

        $other = $this->payload();
        $other['database']['database'] = 'another_database';
        $installer->startRun($other, 'owner-token');
        $this->assertNotSame($id, json_decode((string) file_get_contents($this->dir.'/run.json'), true)['id'], 'another database: a new run');
        $this->assertSame('working', $first['status']);
    }

    public function test_a_step_ended_from_outside_is_found_and_the_installation_goes_on_in_shorter_steps(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        // A request the host ended inside the admin step: its note was never cleared.
        $this->editState(fn (array $state): array => ['running' => ['at' => time() - 40, 'job' => 'admin', 'point' => null]] + $state);

        $progress = $installer->runStep($this->payload(), 'owner-token');

        $this->assertSame('ready', $progress['status']);
        $state = $this->state();
        $this->assertSame(Installer::RUN_BUDGET / 2, (float) $state['budget'], 'the next steps get half the time');
        $this->assertSame(1, $state['ends']['admin:']);
        $this->assertStringContainsString('The server ended a step of the installation before it was done', implode(' ', $progress['notes']));
        $this->assertSame(1, User::query()->where('is_admin', true)->count(), 'the admin step ran again without a second account');
    }

    public function test_a_migration_cut_off_half_way_rebuilds_the_database_and_one_that_was_recorded_does_not(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $recorded = (string) DB::table('migrations')->orderBy('id')->value('migration');

        $this->editState(fn (array $state): array => ['running' => ['at' => time() - 40, 'job' => 'migrate', 'point' => $recorded]] + $state);
        $installer->runStep($this->payload(), 'owner-token');
        $this->assertSame(0, (int) $this->state()['restarts'], 'a recorded migration was done: no rebuild');

        $installer->startRun($this->payload(), 'owner-token');
        $this->editState(fn (array $state): array => ['running' => ['at' => time() - 40, 'job' => 'migrate', 'point' => '2099_01_01_000000_cut_off_half_way']] + $state);
        $progress = $installer->runStep($this->payload(), 'owner-token');
        $state = $this->state();
        $this->assertSame(1, (int) $state['restarts'], 'a migration that was not recorded: the database is built again');
        $this->assertSame(['prepare', 'reset', 'migrate', 'seed', 'admin', 'settings', 'files'], array_keys($state['jobs']));
        $this->assertSame('done', $state['jobs']['reset']);
        $this->assertStringContainsString('emptied the database and built it again from the start', implode(' ', $progress['notes']));
    }

    public function test_the_import_goes_back_to_the_start_of_the_table_it_was_importing(): void
    {
        $installer = $this->installer();
        $run = InstallRun::memory(['mode' => 'backup', 'jobs' => ['prepare' => 'done', 'import' => 'pending'], 'budget' => 15.0,
            'import' => ['size' => 9000, 'offset' => 7000, 'statements' => 40, 'preamble_end' => 120, 'block_start' => 5000,
                'block_statements' => 31, 'block_table' => 'videos', 'block_kind' => 'create', 'replay' => false]]);

        (new \ReflectionMethod(Installer::class, 'recover'))->invoke($installer, $run, ['job' => 'import', 'point' => '5000'], true);

        $cursor = $run->get('import');
        $this->assertSame(5000, $cursor['offset'], 'back to the table\'s first statement');
        $this->assertSame(31, $cursor['statements'], 'its statements are counted again');
        $this->assertTrue($cursor['replay'], 'a table the block created is dropped first');
        $this->assertSame(7.5, (float) $run->get('budget'));

        $run->set('import', ['block_kind' => 'drop'] + $cursor);
        (new \ReflectionMethod(Installer::class, 'recover'))->invoke($installer, $run, ['job' => 'import', 'point' => '5000'], true);
        $this->assertFalse($run->get('import')['replay'], 'a block that starts with its own DROP TABLE needs nothing first');
    }

    public function test_after_three_ends_at_the_same_point_in_the_shortest_steps_it_stops_and_names_the_host_limit(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->editState(fn (array $state): array => ['budget' => Installer::RUN_MIN_BUDGET, 'ends' => ['settings:' => 2],
            'running' => ['at' => time() - 40, 'job' => 'settings', 'point' => null]] + $state);

        $progress = $installer->runStep($this->payload(), 'owner-token');

        $this->assertSame('failed', $progress['status']);
        $this->assertStringContainsString('3 times at the same point', (string) $progress['error']);
        $this->assertStringContainsString('request_terminate_timeout', (string) $progress['error']);
        $this->assertFileDoesNotExist($this->dir.'/installed.json');

        // "Try again" (after the host raised its limit) goes on.
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token', true)['status']);
    }

    public function test_only_the_owner_drives_a_run(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');

        $this->assertSame('not_owner', $installer->runStep($this->payload(), 'stranger-token')['status']);
        $this->assertFalse($installer->runOwnedBy('stranger-token'));
        $this->assertTrue($installer->runOwnedBy('owner-token'));

        $this->expectException(\RuntimeException::class);
        $installer->startRun($this->payload(), 'stranger-token');
    }

    public function test_the_wizard_starts_the_run_and_the_progress_page_drives_it_to_the_result(): void
    {
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->app->instance(Installer::class, $this->installer());
        $this->withSession(['installer.database' => $this->payload()['database'], 'installer.admin' => $this->payload()['admin'], 'installer.app' => $this->payload()['app']]);

        $this->post('/install/install')->assertRedirect('/install?step=installing');

        $page = (string) $this->get('/install?step=installing')->assertOk()->getContent();
        $this->assertStringContainsString('data-install-run', $page);
        $this->assertStringContainsString('Create the database tables', $page);
        $this->assertStringContainsString('role="progressbar"', $page);
        $this->get('/install?step=installing', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'working');

        $this->postJson('/install/run')->assertOk()->assertJsonPath('status', 'ready');
        $this->assertFileDoesNotExist($this->dir.'/installed.json', 'the background steps never finish the installation');

        $this->post('/install/run')->assertOk()->assertSee('Installation complete')->assertSee('Installer locked.');
        $this->assertFileExists($this->dir.'/installed.json');
        $this->assertSame('true', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['APP_INSTALLED']);
    }

    // R1B-001: an unfinished installation serves only its owner

    public function test_a_run_of_this_installer_shows_strangers_only_the_password_page(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);

        $page = (string) $this->get('/install')->assertStatus(503)->assertSee('Installation Not Finished')->getContent();
        foreach (['installer_db_name', '127.0.0.9', 'installer_user', base_path(), str_replace('\\', '/', base_path()), 'Web server', 'class="stepper"'] as $detail) {
            $this->assertStringNotContainsString($detail, $page, 'the page names no detail: '.$detail);
        }
        $this->assertStringContainsString('name="resume_password"', $page);

        $this->post('/install/introduction')->assertRedirect('/install');
        $this->postJson('/install/run')->assertForbidden()->assertJsonPath('status', 'not_owner');
        $this->get('/install?step=installing', ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_owner_continues_a_run_with_its_database_password(): void
    {
        $installer = $this->installer(provable: true);
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);

        $this->post('/install/resume', ['resume_password' => 'wrong'])->assertRedirect('/install')->assertSessionHasErrors('resume_password');
        $this->assertFalse($installer->runOwnedBy((string) session('installer.run_token', '')));

        $this->post('/install/resume', ['resume_password' => 'the-db-password'])->assertRedirect('/install?step=admin');
        $this->assertTrue($installer->runOwnedBy((string) session('installer.run_token')), 'this session owns the run now');
        $this->assertFalse($installer->runOwnedBy('owner-token'), 'the old session does not');
        $this->assertSame('the-db-password', session('installer.database.password'));
        $this->assertSame('installer_db_name', session('installer.database.database'), 'the run\'s own database');
        $this->get('/install?step=admin')->assertOk()->assertSee('Admin Account')->assertDontSee('Installation Not Finished');
    }

    public function test_an_earlier_installers_half_install_opens_the_wizard_only_for_the_owner(): void
    {
        $installer = $this->installer();
        $this->app->instance(Installer::class, $installer);
        $this->setEnv('APP_INSTALLED', 'true');
        $this->setEnv('DB_PASSWORD', 'secret-of-env');
        $this->setEnv('DB_HOST', '127.0.0.1');
        $this->setEnv('DB_DATABASE', 'half_installed');
        $this->setEnv('DB_USERNAME', 'site_user');

        // No lock, no admin: not installed - an installation that did not finish.
        $this->assertFalse($installer->isInstalled());
        $pending = $installer->unfinishedInstallation();
        $this->assertSame('env', $pending['kind']);
        $this->assertTrue($pending['provable']);
        $this->get('/')->assertRedirect('/install');
        $page = (string) $this->get('/install')->assertStatus(503)->assertSee('Installation Not Finished')->assertSee('DB_PASSWORD')->getContent();
        $this->assertStringNotContainsString('half_installed', $page);
        $this->assertStringNotContainsString('site_user', $page);
        $this->post('/install/requirements')->assertRedirect('/install');

        $this->post('/install/resume', ['resume_password' => 'not-it'])->assertSessionHasErrors('resume_password');
        $this->post('/install/resume', ['resume_password' => 'secret-of-env'])->assertRedirect('/install?step=requirements');
        $this->assertSame('half_installed', session('installer.database.database'));
        $this->get('/install?step=database')->assertOk()->assertSee('value="half_installed"', false);

        // With an administrator it is an installed site again.
        User::factory()->create(['is_admin' => true]);
        $this->assertTrue($installer->isInstalled());
        $this->assertNull($installer->unfinishedInstallation());
    }

    public function test_an_empty_database_password_proves_nothing_and_the_tries_are_limited(): void
    {
        $installer = $this->installer();
        $this->app->instance(Installer::class, $installer);
        $this->setEnv('APP_INSTALLED', 'true');
        $this->setEnv('DB_PASSWORD', '');

        $this->assertFalse($installer->unfinishedInstallation()['provable']);
        $this->get('/install')->assertStatus(503)->assertSee('Installation Not Finished')->assertDontSee('name="resume_password"', false)->assertSee('INSTALLER_');
        $this->assertFalse($installer->proveOwnership($installer->unfinishedInstallation(), ''));

        $this->setEnv('DB_PASSWORD', 'secret-of-env');
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        for ($i = 0; $i < 5; $i++) {
            $this->post('/install/resume', ['resume_password' => 'guess-'.$i])->assertSessionHasErrors('resume_password');
        }
        // The sixth try is refused before the password is even compared - also the right one.
        $this->post('/install/resume', ['resume_password' => 'secret-of-env'])->assertSessionHasErrors('resume_password');
        $this->assertStringContainsString('Too many tries', (string) session('errors')->first('resume_password'));
        $this->assertNull(session('installer.owner_proof'));
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        RateLimiter::clear('installer-resume');
    }

    public function test_a_finish_cut_off_after_env_gets_its_lock_on_the_next_visit(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $installer->runStep($this->payload(), 'owner-token');
        $this->assertSame('ready', $this->state()['status']);
        // The host ended the last step after .env was written: APP_INSTALLED=true, an administrator, no lock yet.
        $this->setEnv('APP_INSTALLED', 'true');
        $this->editState(fn (array $state): array => ['running' => ['at' => time(), 'job' => 'finish', 'point' => 'env-written']] + $state);

        $this->assertTrue($installer->isInstalled());
        $this->assertFileExists($this->dir.'/installed.json', 'the lock follows');
        $this->assertFileDoesNotExist($this->dir.'/run.json', 'the run is over');
        $this->assertSame(1, $installer->cronCalls, 'and the scheduler\'s cron line');
    }

    public function test_a_finish_cut_off_after_the_lock_gets_its_cron_line_on_the_next_visit(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $installer->runStep($this->payload(), 'owner-token');
        // Cut off after .env and the lock, before the cron line and the end of the note.
        $this->setEnv('APP_INSTALLED', 'true');
        file_put_contents($this->dir.'/installed.json', '{"installed_at":"2026-10-27"}');
        $this->editState(fn (array $state): array => ['running' => ['at' => time(), 'job' => 'finish', 'point' => 'env-written']] + $state);

        $this->assertTrue($installer->isInstalled());
        $this->assertSame('{"installed_at":"2026-10-27"}', file_get_contents($this->dir.'/installed.json'), 'the lock stays as it was');
        $this->assertFileDoesNotExist($this->dir.'/run.json');
        $this->assertSame(1, $installer->cronCalls);

        // Any other note next to a lock (an old reinstall that was given up) is left alone.
        file_put_contents($this->dir.'/run.json', json_encode(['version' => 1, 'status' => 'working', 'running' => null, 'owner' => 'x']));
        $this->assertTrue($installer->isInstalled());
        $this->assertFileExists($this->dir.'/run.json');
        $this->assertSame(1, $installer->cronCalls);
    }

    public function test_resuming_an_earlier_installers_half_install_its_admin_step_does_not_end_the_run(): void
    {
        // .env of an earlier installer says APP_INSTALLED=true from the start; this run's admin step makes the admin
        // account before the final .env. The site must not count as installed then: the last step still writes .env.
        $this->setEnv('APP_INSTALLED', 'true');
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $installer->runStep($this->payload(), 'owner-token');
        $this->assertTrue(User::query()->where('is_admin', true)->exists());

        $this->assertFalse($installer->isInstalled(), 'the run is not over: its final .env is not written yet');
        $this->assertFileDoesNotExist($this->dir.'/installed.json', 'no lock before the final .env');
        $this->assertSame('run', $installer->unfinishedInstallation()['kind']);

        $this->assertSame('finished', $installer->runStep($this->payload(), 'owner-token', false, true)['status']);
        $this->assertSame('https://mysite.net', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['APP_URL'], 'the final .env is this run\'s');
        $this->assertFileExists($this->dir.'/installed.json');
        $this->assertTrue($installer->isInstalled());
    }

    // Second pass (the review of R1B-001: BI-RV-01, BI-RV-02, BI-RV-04)

    public function test_a_run_never_goes_on_with_other_database_settings(): void
    {
        // BI-RV-01: the steps took the run's position (prepare done, import offset, migrations count) and went on with
        // whatever database the session held now - a site marked installed over a database that missed tables.
        file_put_contents($this->dir.'/example.sql', "-- an SQL backup\n");
        $installer = $this->installer(backup: $this->dir.'/example.sql');
        $installer->startRun($this->payload(), 'owner-token');
        $envBefore = (string) file_get_contents($this->dir.'/.env');

        foreach (['database' => 'another_database', 'host' => '127.0.0.10', 'username' => 'another_user', 'mode' => 'backup', 'overwrite' => true] as $field => $value) {
            $other = $this->payload();
            $other['database'][$field] = $value;

            foreach ([[false, false], [true, false], [false, true]] as [$retry, $finalize]) {
                $progress = $installer->runStep($other, 'owner-token', $retry, $finalize);
                $this->assertSame('changed', $progress['status'], $field.' changed: nothing is done');
                $this->assertStringContainsString('database installer_db_name on 127.0.0.9', (string) $progress['error'], 'the run names its own settings to its owner');
            }

            $this->assertFalse($installer->runMatches($other['database']));
        }

        $state = $this->state();
        $this->assertSame(['prepare', 'migrate', 'seed', 'admin', 'settings', 'files'], array_keys(array_filter($state['jobs'], fn (string $job): bool => $job === 'pending')), 'no step ran');
        $this->assertNull($state['running']);
        $this->assertSame($envBefore, (string) file_get_contents($this->dir.'/.env'));
        $this->assertFileDoesNotExist($this->dir.'/installed.json');

        // The run's own settings (another password is no other database) go on to the end.
        $this->assertTrue($installer->runMatches($this->payload()['database']));
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);
        $this->assertSame('finished', $installer->runStep($this->payload(), 'owner-token', false, true)['status']);
        $this->assertSame('installer_db_name', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['DB_DATABASE']);
    }

    public function test_the_install_step_and_the_progress_page_go_on_only_with_the_runs_own_settings(): void
    {
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->app->instance(Installer::class, $this->installer());
        $a = $this->payload();
        $this->withSession(['installer.database' => $a['database'], 'installer.admin' => $a['admin'], 'installer.app' => $a['app']]);
        $this->post('/install/install')->assertRedirect('/install?step=installing');
        $runId = $this->state()['id'];

        $same = (string) $this->get('/install?step=install')->assertOk()->getContent();
        $this->assertStringContainsString('This installation is already in progress', $same);
        $this->assertStringContainsString('step=installing', $same, 'its own settings: the link to its progress');

        // The owner goes back to the Database step and enters database B (the reviewer's path), then the Install step.
        $b = $a['database'];
        $b['database'] = 'database_b';
        $this->withSession(['installer.database' => $b]);

        $install = (string) $this->get('/install?step=install')->assertOk()->getContent();
        $this->assertStringContainsString('An installation with other database settings is in progress', $install);
        $this->assertStringNotContainsString('step=installing', $install, 'no way to the progress page of database A');

        $page = (string) $this->get('/install?step=installing')->assertOk()->getContent();
        $this->assertStringContainsString('data-status="changed"', $page);
        $this->assertStringContainsString('The database settings changed', $page);
        $this->assertStringContainsString('Back to Install', $page);
        $this->get('/install?step=installing', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'changed');

        $this->postJson('/install/run')->assertOk()->assertJsonPath('status', 'changed');
        $this->post('/install/run', ['retry' => '1'])->assertOk()->assertSee('The database settings changed');
        $this->post('/install/run')->assertOk()->assertSee('The database settings changed');
        $this->assertSame($runId, $this->state()['id']);
        $this->assertSame('pending', $this->state()['jobs']['prepare'], 'nothing was done on database B with the position of A');
        $this->assertFileDoesNotExist($this->dir.'/installed.json');
        $this->assertSame('false', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['APP_INSTALLED']);

        // Install Now with B starts again: a new run, from its own first step, on B.
        $this->post('/install/install')->assertRedirect('/install?step=installing');
        $this->assertNotSame($runId, $this->state()['id']);
        $this->postJson('/install/run')->assertOk()->assertJsonPath('status', 'ready');
        $this->post('/install/run')->assertOk()->assertSee('Installation complete');
        $this->assertSame('database_b', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['DB_DATABASE']);
    }

    public function test_changed_admin_values_make_the_account_settings_and_file_steps_run_again(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);
        $this->assertTrue(User::query()->where('username', 'site_owner')->where('is_admin', true)->exists());

        // The Admin step was sent again with other values, then the progress page (not Install Now) went on.
        $changed = $this->payload();
        $changed['admin'] = ['username' => 'new_owner', 'email' => 'new-owner@mysite.net', 'password' => 'New-pass-2026!'];
        $changed['app']['name'] = 'Renamed site';
        $this->assertSame('finished', $installer->runStep($changed, 'owner-token', false, true)['status']);

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $admin = User::query()->where('is_admin', true)->first();
        $this->assertSame('new_owner', $admin->username, 'the account has the values of the final .env');
        $this->assertSame('new-owner@mysite.net', $admin->email);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('New-pass-2026!', $admin->password));
        $this->assertSame('Renamed site', (string) DB::table('settings')->where('key', 'site_name')->value('value'));
        $env = \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'));
        $this->assertSame('new-owner@mysite.net', $env['SUPPORT_EMAIL']);
        $this->assertSame('Renamed site', $env['APP_NAME']);
    }

    public function test_the_final_env_is_written_only_when_the_database_answers_with_these_settings(): void
    {
        $installer = $this->installer(provable: true);
        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);
        $envBefore = (string) file_get_contents($this->dir.'/.env');

        // The same database with a password that does not open it: .env is not written (it would name a database the
        // site cannot use).
        $wrong = $this->payload();
        $wrong['database']['password'] = 'not-the-password';
        $progress = $installer->runStep($wrong, 'owner-token', false, true);
        $this->assertSame('failed', $progress['status']);
        $this->assertStringContainsString('so .env was not written', (string) $progress['error']);
        $this->assertSame($envBefore, (string) file_get_contents($this->dir.'/.env'));
        $this->assertFileDoesNotExist($this->dir.'/installed.json');

        // No administrator in that database (the account is in the bin): not either, and Try again makes the account again.
        User::query()->where('is_admin', true)->delete();
        $progress = $installer->runStep($this->payload(), 'owner-token', true, true);
        $this->assertSame('failed', $progress['status']);
        $this->assertStringContainsString('has no admin account, so .env was not written', (string) $progress['error']);
        $this->assertSame($envBefore, (string) file_get_contents($this->dir.'/.env'));
        $this->assertSame('pending', $this->state()['jobs']['admin'], 'Try again makes the account again');

        $progress = $installer->runStep($this->payload(), 'owner-token', true, true);
        $this->assertSame('finished', $progress['status'], (string) ($progress['error'] ?? ''));
        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $this->assertSame('true', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env'))['APP_INSTALLED']);
    }

    public function test_the_admin_step_counts_accounts_in_the_bin_and_refuses_a_taken_name_clearly(): void
    {
        // An imported database with a deleted account of the owner's (users are soft-deleted; e-mail, username and slug
        // are unique over every row - the bundled backup has such an account too): it is the admin account again, instead
        // of a duplicate key.
        $deleted = User::factory()->create(['email' => 'owner@mysite.net', 'username' => 'old_name', 'is_admin' => false]);
        $deleted->delete();
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $progress = $installer->runStep($this->payload(), 'owner-token');
        $this->assertSame('ready', $progress['status'], (string) ($progress['error'] ?? ''));
        $admin = User::query()->where('is_admin', true)->sole();
        $this->assertSame($deleted->id, $admin->id, 'the account in the bin is the admin account again');
        $this->assertSame('site_owner', $admin->username);
        $this->assertNull($admin->deleted_at);

        // Another account holds the chosen username: the step stops with what to do (it was a raw duplicate key).
        $member = User::factory()->create(['username' => 'taken_name', 'email' => 'member@mysite.net', 'is_admin' => false]);
        $member->delete();
        $taken = $this->payload();
        $taken['admin']['username'] = 'taken_name';
        $progress = $installer->runStep($taken, 'owner-token');
        $this->assertSame('failed', $progress['status']);
        $this->assertSame('The username taken_name belongs to another account in this database. Go back to the Admin Account step and choose another one.', $progress['error']);
        $this->assertSame('site_owner', User::query()->where('is_admin', true)->sole()->username, 'nothing of the account was changed');
    }

    public function test_install_now_never_starts_while_a_step_is_at_work(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $held = fopen($this->dir.'/run.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB), 'the test holds the step lock, like a step at work');

        try {
            $other = $this->payload();
            $other['database']['database'] = 'another_database';
            $installer->startRun($other, 'owner-token');
            $this->fail('Install Now started while a step was at work');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('A step of the installation is running right now', $e->getMessage());
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        $this->assertSame('installer_db_name', $this->state()['target']['database'], 'the run at work was left as it was');
    }

    public function test_with_the_reinstall_switch_the_first_page_offers_the_proof_and_install_now_starts_again_once_the_run_is_idle(): void
    {
        // BI-RV-02: a reinstallation (INSTALLER_ALLOW_REINSTALL=true on an installed site) whose browser lost its session.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        file_put_contents($this->dir.'/installed.json', '{"installed_at":"2026-10-20"}');
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $runId = $this->state()['id'];
        $this->app->instance(Installer::class, $installer);

        // Another browser: the wizard is open (the switch), and its first page offers the proof.
        $first = (string) $this->get('/install')->assertOk()->getContent();
        $this->assertStringContainsString('Start With a Real Environment Scan', $first);
        $this->assertStringContainsString('An installation started from another browser has not finished', $first);
        $this->assertStringContainsString('name="resume_password"', $first);
        foreach (['installer_db_name', '127.0.0.9', 'installer_user'] as $detail) {
            $this->assertStringNotContainsString($detail, $first, 'no detail of that installation: '.$detail);
        }

        // Install Now while that run is still driven: refused, with what to do.
        $p = $this->payload();
        $this->withSession(['installer.database' => $p['database'], 'installer.admin' => $p['admin'], 'installer.app' => $p['app']]);
        $this->assertStringContainsString('in its place once it has stopped for 2 minutes', (string) $this->get('/install?step=install')->getContent());
        $this->post('/install/install')->assertRedirect('/install?step=install')->assertSessionHasErrors('install');
        $this->assertStringContainsString('still running', (string) session('errors')->first('install'));
        $this->assertSame($runId, $this->state()['id']);

        // Once no browser has driven it for RUN_IDLE_SECONDS: Install Now starts again in its place.
        $this->editState(fn (array $state): array => ['updated_at' => time() - Installer::RUN_IDLE_SECONDS - 5] + $state);
        $this->post('/install/install')->assertRedirect('/install?step=installing');
        $this->assertNotSame($runId, $this->state()['id']);
        $this->assertTrue($installer->runOwnedBy((string) session('installer.run_token')));
        $this->postJson('/install/run')->assertOk()->assertJsonPath('status', 'ready');
        $this->post('/install/run')->assertOk()->assertSee('Installation complete');
        $this->assertArrayNotHasKey('INSTALLER_ALLOW_REINSTALL', \Dotenv\Dotenv::parse((string) file_get_contents($this->dir.'/.env')));
    }

    public function test_with_the_reinstall_switch_the_owner_continues_the_run_with_its_database_password(): void
    {
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        file_put_contents($this->dir.'/installed.json', '{"installed_at":"2026-10-20"}');
        $installer = $this->installer(provable: true);
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));

        $this->post('/install/resume', ['resume_password' => 'wrong'])->assertRedirect('/install')->assertSessionHasErrors('resume_password');
        $this->post('/install/resume', ['resume_password' => 'the-db-password'])->assertRedirect('/install?step=admin');
        $this->assertTrue($installer->runOwnedBy((string) session('installer.run_token')), 'this browser goes on with the same run');
        $this->assertSame('installer_db_name', session('installer.database.database'));
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        RateLimiter::clear('installer-resume');
    }

    public function test_a_reinstall_of_the_sites_own_database_that_did_not_finish_is_not_installed_without_the_switch(): void
    {
        // The site's .env names the database the reinstallation (INSTALLER_ALLOW_REINSTALL=true) replaces.
        foreach (['DB_HOST' => '127.0.0.9', 'DB_PORT' => '3306', 'DB_DATABASE' => 'installer_db_name', 'DB_USERNAME' => 'installer_user'] as $key => $value) {
            $this->setEnv($key, $value);
        }
        file_put_contents($this->dir.'/installed.json', '{"installed_at":"2026-10-20"}');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $installer = $this->installer(provable: true);
        $this->app->instance(Installer::class, $installer);

        // Its steps rebuilt the database (not finished yet), then the owner took the switch away again (BI-RV-02): the
        // site said "Already Installed" over that database and nobody could go on with the installation.
        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');

        $this->assertFalse($installer->isInstalled(), 'the lock belongs to a site that is gone');
        $pending = $installer->unfinishedInstallation();
        $this->assertSame('run', $pending['kind']);
        $this->assertFalse($pending['reinstall']);
        $this->get('/')->assertRedirect('/install');
        $page = (string) $this->get('/install')->assertStatus(503)->assertSee('Installation Not Finished')->getContent();
        $this->assertStringContainsString('name="resume_password"', $page);
        $this->assertStringNotContainsString('installer_db_name', $page);

        // Its owner proves it and finishes it.
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        $this->post('/install/resume', ['resume_password' => 'the-db-password'])->assertRedirect('/install?step=admin');
        $p = $this->payload();
        $this->withSession(['installer.admin' => $p['admin'], 'installer.app' => $p['app']]);
        $this->postJson('/install/run')->assertOk()->assertJsonPath('status', 'ready');
        $this->post('/install/run')->assertOk()->assertSee('Installation complete');
        $this->assertTrue($installer->isInstalled());
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        RateLimiter::clear('installer-resume');

        // A reinstallation of the same database that was started but has changed nothing yet: the site is still there,
        // and its locked page says how the owner goes on.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $installer->startRun($this->payload(), 'owner-token');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');
        $this->assertTrue($installer->isInstalled());
        $this->assertTrue($installer->hasUnfinishedReinstall());
        $this->get('/install')->assertOk()->assertSee('Already Installed')->assertSee('A reinstallation did not finish');
        @unlink($this->dir.'/run.json');

        // A run on ANOTHER database next to the lock leaves the installed site alone.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $other = $this->payload();
        $other['database']['database'] = 'a_new_database';
        $installer->startRun($other, 'owner-token');
        $installer->runStep($other, 'owner-token');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');
        $this->assertTrue($installer->isInstalled());
        $this->get('/install')->assertOk()->assertSee('Already Installed')->assertSee('A reinstallation did not finish');

        // "localhost", 127.0.0.1 and ::1 are one server; other names compare as written.
        $sameHost = new \ReflectionMethod(Installer::class, 'sameDatabaseHost');
        $this->assertTrue($sameHost->invoke(null, 'localhost', '127.0.0.1'));
        $this->assertTrue($sameHost->invoke(null, 'LOCALHOST', '[::1]'));
        $this->assertTrue($sameHost->invoke(null, 'db.example.net', 'DB.example.net'));
        $this->assertFalse($sameHost->invoke(null, '127.0.0.9', 'localhost'));
    }

    public function test_an_installation_whose_database_user_has_no_password_offers_no_password_form(): void
    {
        $installer = $this->installer();
        $payload = $this->payload();
        $payload['database']['password'] = '';
        $installer->startRun($payload, 'owner-token');
        $this->app->instance(Installer::class, $installer);

        $this->assertFalse($installer->unfinishedInstallation()['provable']);
        $page = (string) $this->get('/install')->assertStatus(503)->assertSee('Installation Not Finished')->getContent();
        $this->assertStringNotContainsString('name="resume_password"', $page, 'no form that can never succeed');
        $this->assertStringContainsString('has no password', $page);
        $this->assertStringContainsString('INSTALLER_', $page, 'how to start again');

        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        $this->post('/install/resume', ['resume_password' => ''])->assertSessionHasErrors('resume_password');
        $this->assertStringContainsString('cannot be continued with a password', (string) session('errors')->first('resume_password'));
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        RateLimiter::clear('installer-resume');
    }

    public function test_the_wait_after_too_many_tries_is_the_one_of_the_limit_that_was_reached(): void
    {
        // BI-RV-04: 5 wrong tries from one address block that address for 10 minutes - not the hour of the overall limit.
        $installer = $this->installer(provable: true);
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        RateLimiter::clear('installer-resume');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/install/resume', ['resume_password' => 'guess-'.$i])->assertSessionHasErrors('resume_password');
        }
        $this->post('/install/resume', ['resume_password' => 'the-db-password'])->assertSessionHasErrors('resume_password');
        $this->assertSame('Too many tries. Wait 10 minutes and try again.', (string) session('errors')->first('resume_password'));

        // The overall limit (30 an hour, all addresses together) reached: no wait for the owner any more (round 3 R3-083),
        // the file the page names first (InstallerResumeFileProofTest).
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('installer-resume', 3600);
        }
        $this->post('/install/resume', ['resume_password' => 'the-db-password'])->assertSessionHasErrors('resume_password');
        $this->assertStringStartsWith('Too many wrong passwords were tried here from other addresses. First create the empty file named below', (string) session('errors')->first('resume_password'));
        RateLimiter::clear('installer-resume:'.sha1('127.0.0.1'));
        RateLimiter::clear('installer-resume');
    }

    // R1B-008: the project folder / .env, first in the Filesystem step

    public function test_the_filesystem_step_checks_the_project_folder_and_env_first(): void
    {
        $this->skipWhenRoot();
        $installer = $this->installer();

        $first = $installer->filesystemChecks()[0];
        $this->assertSame('Project folder (.env)', $first['label']);
        $this->assertTrue($first['required']);
        $this->assertTrue($first['ok']);
        $this->assertSame($this->dir.'/.env', $first['path']);

        chmod($this->dir.'/.env', 0444);
        clearstatcache();
        $first = $installer->filesystemChecks()[0];
        $this->assertFalse($first['ok']);
        $this->assertSame('Not writable', $first['detail']);
        $this->assertFalse($installer->filesystemPass(), 'the Filesystem step blocks before any database detail is asked');
        chmod($this->dir.'/.env', 0666);
    }

    public function test_when_php_is_not_the_owner_the_fix_says_whose_the_files_are_and_how_to_give_them_to_php(): void
    {
        $this->skipWhenRoot();
        $installer = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('onWindows')->andReturn(false);
        $installer->shouldReceive('envPath')->andReturn($this->dir.'/.env');
        $installer->shouldReceive('fileOwnership')->andReturn(['php_user' => 'www-data', 'php_uid' => 82, 'php_gid' => 82, 'owner' => 'deploy', 'owner_uid' => 1001]);
        chmod($this->dir.'/.env', 0444);
        clearstatcache();

        // .env exists: it is the file that must be written (BI-RV-03: its own owner, chown of the file).
        $check = $installer->projectFolderCheck();
        chmod($this->dir.'/.env', 0666);

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('PHP runs as www-data (user id 82), but this file belongs to deploy (user id 1001)', $check['fix']);
        $this->assertStringContainsString('chown 82:82 '.escapeshellarg($this->dir.'/.env'), $check['fix']);

        // No .env yet, the folder belongs to deploy: the whole site folder goes to PHP.
        $folder = $this->dir.'/no-env-yet';
        @mkdir($folder, 0777, true);
        $noEnv = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $noEnv->shouldReceive('onWindows')->andReturn(false);
        $noEnv->shouldReceive('envPath')->andReturn($folder.'/.env');
        $noEnv->shouldReceive('fileOwnership')->andReturn(['php_user' => 'www-data', 'php_uid' => 82, 'php_gid' => 82, 'owner' => 'deploy', 'owner_uid' => 1001]);
        chmod($folder, 0555);
        clearstatcache();
        $check = $noEnv->projectFolderCheck();
        chmod($folder, 0777);

        if (! is_writable($folder) || ! $check['ok']) {
            // (Windows ignores a folder's read-only flag: there the folder stays writable.)
            $this->assertStringContainsString('PHP runs as www-data (user id 82), but this belongs to deploy (user id 1001)', $check['fix']);
            $this->assertStringContainsString('chown -R 82:82 ', $check['fix']);
        }

        // The same user: write permission is the fix.
        $same = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $same->shouldReceive('onWindows')->andReturn(false);
        $same->shouldReceive('fileOwnership')->andReturn(['php_user' => 'site', 'php_uid' => 1001, 'php_gid' => 1001, 'owner' => 'site', 'owner_uid' => 1001]);
        $fix = (new \ReflectionMethod(Installer::class, 'writeAccessFix'))->invoke($same, $this->dir, 'fallback text');
        $this->assertSame('fallback text', $fix);
    }

    public function test_an_installation_that_cannot_write_env_never_starts_and_a_failed_write_says_why(): void
    {
        $this->skipWhenRoot();
        $installer = $this->installer();
        $env = $this->dir.'/.env';

        // Install Now with an .env PHP cannot write (the Filesystem step skipped, or permissions changed since): refused
        // before any database work.
        chmod($env, 0444);
        clearstatcache();
        try {
            $installer->startRun($this->payload(), 'owner-token');
            $this->fail('the installation started although PHP cannot write .env');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PHP cannot write the site\'s .env file', $e->getMessage());
        } finally {
            chmod($env, 0666);
        }
        $this->assertFileDoesNotExist($this->dir.'/run.json');

        // .env becomes unwritable during the run: the last step stops with the reason and the fix, nothing half written;
        // Try again finishes once it is writable.
        $installer->startRun($this->payload(), 'owner-token');
        $this->assertSame('ready', $installer->runStep($this->payload(), 'owner-token')['status']);
        $before = (string) file_get_contents($env);
        chmod($env, 0444);
        clearstatcache();
        $progress = $installer->runStep($this->payload(), 'owner-token', false, true);
        chmod($env, 0666);
        $this->assertSame('failed', $progress['status']);
        $this->assertStringContainsString('could not be written to '.$env.': PHP cannot write that file', (string) $progress['error']);
        $this->assertStringNotContainsString('Failed to open stream', (string) $progress['error']);
        $this->assertSame($before, (string) file_get_contents($env));
        $this->assertFileDoesNotExist($this->dir.'/installed.json');
        $this->assertSame('finished', $installer->runStep($this->payload(), 'owner-token', true, true)['status']);
    }

    public function test_the_env_check_takes_the_owner_of_the_env_file_itself(): void
    {
        $this->skipWhenRoot();
        // BI-RV-03: the folder belongs to the PHP user, but .env was made by root afterwards ("cp .env.example .env").
        $env = $this->dir.'/.env';
        $installer = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('onWindows')->andReturn(false);
        $installer->shouldReceive('envPath')->andReturn($env);
        $installer->shouldReceive('fileOwnership')->andReturnUsing(fn (?string $path = null): array => $path === $env
            ? ['php_user' => 'www-data', 'php_uid' => 33, 'php_gid' => 33, 'owner' => 'root', 'owner_uid' => 0]
            : ['php_user' => 'www-data', 'php_uid' => 33, 'php_gid' => 33, 'owner' => 'www-data', 'owner_uid' => 33]);
        chmod($env, 0444);
        clearstatcache();

        $check = $installer->projectFolderCheck();
        chmod($env, 0666);

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('PHP runs as www-data (user id 33), but this file belongs to root (user id 0)', $check['fix']);
        $this->assertStringContainsString('chown 33:33 '.escapeshellarg($env), $check['fix']);
        $this->assertStringNotContainsString('write for the owner', $check['fix'], 'write permission for root would not help PHP');

        // The Permission Guide names it too, and no longer says that everything belongs to the PHP user.
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $page = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $page->shouldReceive('fileOwnership')->andReturn(['php_user' => 'www-data', 'php_uid' => 33, 'php_gid' => 33, 'owner' => 'www-data', 'owner_uid' => 33]);
        $page->shouldReceive('envFileOwnership')->andReturn(['php_user' => 'www-data', 'php_uid' => 33, 'php_gid' => 33, 'owner' => 'root', 'owner_uid' => 0]);
        $page->shouldReceive('runStatePath')->andReturn($this->dir.'/run.json');
        $this->app->instance(Installer::class, $page);

        $html = (string) $this->get('/install?step=filesystem')->assertOk()->getContent();
        $this->assertStringContainsString('But its <code>.env</code> file belongs to root (user id 0)', $html);
        $this->assertStringContainsString('chown 33:33 ', $html);
        $this->assertStringNotContainsString('PHP can be given write access to everything it needs', $html);
    }

    public function test_the_filesystem_page_shows_the_php_user_and_the_owner(): void
    {
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $installer = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('fileOwnership')->andReturn(['php_user' => 'www-data', 'php_uid' => 82, 'php_gid' => 82, 'owner' => null, 'owner_uid' => 1001]);
        $installer->shouldReceive('runStatePath')->andReturn($this->dir.'/run.json');
        $this->app->instance(Installer::class, $installer);

        $page = (string) $this->get('/install?step=filesystem')->assertOk()->getContent();

        $this->assertStringContainsString('Project folder (.env)', $page);
        $this->assertStringContainsString('PHP runs as www-data (user id 82)', $page);
        $this->assertStringContainsString('belongs to another user (user id 1001)', $page);
        $this->assertStringContainsString('chown -R 82:82', $page);
        $this->assertLessThan(strpos($page, 'Storage</strong>'), strpos($page, 'Project folder (.env)'), 'the project folder is the first check');
    }

    public function test_the_bootstrap_page_tells_a_permission_problem_from_missing_files(): void
    {
        // A copy of public/index.php in a folder of its own: storage/ is a file (PHP cannot make the folder) - a
        // permission problem; then storage/ is fine but vendor/ is missing - an incomplete package.
        $root = $this->dir.'/site';
        @mkdir($root.'/public', 0777, true);
        copy(public_path('index.php'), $root.'/public/index.php');
        file_put_contents($root.'/storage', 'not a folder');

        $permission = $this->runPage($root);
        $this->assertStringContainsString('Runtime Permissions Required', $permission);
        $this->assertStringContainsString('Uploading the package again does not fix this', $permission);
        $this->assertStringContainsString('belong to the user PHP runs as', $permission);
        $this->assertStringNotContainsString('Upload or extract the complete AVDBCMS package again', $permission);
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid')) {
            $this->assertStringContainsString('PHP runs as', $permission);
        }

        unlink($root.'/storage');
        $missing = $this->runPage($root);
        $this->assertStringContainsString('Incomplete AVDBCMS Package', $missing);
        $this->assertStringContainsString('Upload or extract the complete AVDBCMS package again', $missing);

        $this->removeTree($root);
    }

    // helpers

    /**
     * Root ignores file modes: chmod 0444 / 0555 cannot make .env or a folder unwritable for a suite run as root (a
     * Linux CI or a container's default user), so the tests that need it skip there, like the suite's other permission
     * tests (tests/Feature/Updater/Support/UpdaterScenario.php; round 3 R3-042).
     */
    private function skipWhenRoot(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: file permissions do not stop this PHP from writing.');
        }
    }

    private function runPage(string $root): string
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', $root.'/public/index.php'], $root.'/public');
        $process->setTimeout(60);
        $process->run();

        return $process->getOutput();
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }

    /** The installer on the test database, its files in this test's folder (the copy's own .env, lock and run stay). */
    private function installer(bool $provable = false, ?string $backup = null): Installer
    {
        return new class($this->dir, $provable, $backup) extends Installer
        {
            public int $cronCalls = 0;

            public function __construct(private string $testDir, private bool $provable, private ?string $backup = null)
            {
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
                if ($this->provable && ($database['password'] ?? null) !== 'the-db-password') {
                    throw new \PDOException('Access denied');
                }

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

            protected function installSchedulerCron(): void
            {
                $this->cronCalls++;
            }

            public function backupInfo(): array
            {
                return ['exists' => $this->backup !== null, 'path' => $this->backup, 'filename' => 'example.sql', 'configured_filename' => 'example.sql', 'size' => '', 'source' => '', 'available' => []];
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

    /** @return array<string, mixed> */
    private function state(): array
    {
        return json_decode((string) file_get_contents($this->dir.'/run.json'), true);
    }

    private function editState(\Closure $edit): void
    {
        file_put_contents($this->dir.'/run.json', json_encode($edit($this->state())));
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
