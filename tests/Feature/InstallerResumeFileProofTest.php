<?php

namespace Tests\Feature;

use App\Support\InstallRun;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Round 3 R3-083: the owner of an unfinished installation proves it with the password of its database (R1B-001), a few
 * tries per address and 30 an hour for all addresses together (against guessing from many addresses). resume() checked the
 * overall limit before the password, so strangers sending 5 wrong passwords from each of 6 addresses kept the owner's
 * right password out ("Too many tries. Wait 59 minutes"), again every hour. Decided: the per-address limits stay; once the
 * overall limit is reached, the right password goes through together with a file proof - an empty file with a name the
 * page shows (a random one for each browser, so the owner's file never opens the way for anybody else), made in
 * storage/app/ through the hosting's file manager, which nobody but the owner can do. Without the file no password is
 * compared at all.
 */
class InstallerResumeFileProofTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var array<string, array{0: mixed, 1: mixed, 2: mixed}> */
    private array $environment = [];

    /** @var list<string> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/install-proof-'.bin2hex(random_bytes(5)));
        @mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/.env', "APP_NAME=AVDBCMS\nAPP_KEY=\nAPP_INSTALLED=false\nINSTALLER_ALLOW_REINSTALL=true\nDB_USERNAME=\n");

        foreach (['APP_INSTALLED', 'INSTALLER_ALLOW_REINSTALL', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
            $this->environment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->setEnv('APP_INSTALLED', 'auto');
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'false');
        $this->clearLimits();
    }

    protected function tearDown(): void
    {
        $this->clearLimits();
        foreach ($this->made as $file) {
            @unlink($file);
        }
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
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_after_the_overall_limit_the_right_password_goes_through_with_the_file_the_page_names(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);

        // below the overall limit: no file asked, as before
        $page = (string) $this->get('/install')->assertStatus(503)->getContent();
        $this->assertStringNotContainsString('data-resume-file', $page);

        // strangers: 5 wrong passwords from each of 6 addresses
        foreach (range(1, 6) as $n) {
            foreach (range(1, 5) as $try) {
                $this->fromAddress('198.51.100.'.$n)->post('/install/resume', ['resume_password' => 'guess-'.$n.'-'.$try])->assertSessionHasErrors('resume_password');
            }
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('installer-resume', 30), 'the overall limit is reached');

        // the page names the file (one name for this browser: the same on its next visit) and says both steps, each a
        // step of its own; the name stands whole in a box of its own with the installer's Copy button (SV-RV-01)
        $page = (string) $this->fromAddress('203.0.113.9')->get('/install')->assertStatus(503)->getContent();
        $this->assertMatchesRegularExpression('#data-resume-file>.*?<ol\b.*?<li>\s*With your hosting\'s file manager.*?storage/<wbr>app.*?'
            .'data-copy="resume-file-name".*?<pre id="resume-file-name"[^>]*>continue-install-[0-9a-f]{16}\.txt</pre>.*?</li>\s*'
            .'<li>Enter the database password below#s', $page);
        preg_match('#continue-install-[0-9a-f]{16}\.txt#', $page, $name);
        $file = storage_path('app/'.$name[0]);
        $this->made[] = $file;
        $this->assertStringContainsString($name[0], (string) $this->get('/install')->getContent(), 'the same name on the next visit');
        foreach (['installer_db_name', 'installer_user', '127.0.0.9', base_path()] as $detail) {
            $this->assertStringNotContainsString($detail, $page, 'no detail of the server: '.$detail);
        }

        // the owner, from a new browser and address, without the file: refused before any password is compared
        $installer->proofs = 0;
        $this->fromAddress('203.0.113.9')->post('/install/resume', ['resume_password' => 'the-db-password'])->assertRedirect('/install')->assertSessionHasErrors('resume_password');
        $this->assertSame('Too many wrong passwords were tried here from other addresses. First create the empty file named below in the folder storage/app of this site with your hosting\'s file manager, then enter the password again.', (string) session('errors')->first('resume_password'));
        // the refusal points to the box below, which names the file whole (no longer inside the sentence, where a line
        // could break it)
        $refused = (string) $this->fromAddress('203.0.113.9')->get('/install')->getContent();
        $this->assertMatchesRegularExpression('#role="alert">.*?named below.*?</div>.*?data-resume-file>.*?<pre id="resume-file-name"[^>]*>'.preg_quote($name[0], '#').'</pre>#s', $refused);
        $this->assertFalse($installer->runOwnedBy((string) session('installer.run_token', '')));
        $this->assertSame(0, $installer->proofs, 'no password was compared');

        // the owner makes the file. Another browser is shown a name of its own, and the owner's file does not open the
        // way for it: its right password too is refused before anything is compared
        file_put_contents($file, '');
        $ownerSession = session()->all();
        $this->flushSession();
        $other = (string) $this->fromAddress('198.51.100.40')->get('/install')->assertStatus(503)->getContent();
        $this->assertSame(1, preg_match('#continue-install-[0-9a-f]{16}\.txt#', $other, $otherName));
        $this->assertNotSame($name[0], $otherName[0], 'a name of its own');
        $this->assertStringNotContainsString('(it is there now)', $other);
        $installer->proofs = 0;
        $this->fromAddress('198.51.100.40')->post('/install/resume', ['resume_password' => 'the-db-password'])->assertSessionHasErrors('resume_password');
        $this->assertStringContainsString('First create the empty file named below', (string) session('errors')->first('resume_password'));
        $this->assertStringContainsString('>'.$otherName[0].'</pre>', (string) $this->fromAddress('198.51.100.40')->get('/install')->getContent(), 'its own name below');
        $this->assertSame(0, $installer->proofs, 'nothing compared for another browser');
        $this->flushSession();
        $this->withSession($ownerSession);

        // the owner's browser: a wrong password is refused and counted, the right one goes through and the file goes
        $this->assertStringContainsString('(it is there now)', (string) $this->fromAddress('203.0.113.9')->get('/install')->getContent());
        $this->fromAddress('203.0.113.9')->post('/install/resume', ['resume_password' => 'not-it'])->assertSessionHasErrors('resume_password');
        $this->assertSame('That is not the password of this installation\'s database.', (string) session('errors')->first('resume_password'));
        $this->fromAddress('203.0.113.9')->post('/install/resume', ['resume_password' => 'the-db-password'])->assertRedirect('/install?step=admin');
        $this->assertTrue($installer->runOwnedBy((string) session('installer.run_token')), 'this session owns the run now');
        $this->assertFileDoesNotExist($file, 'the proof is used once');
    }

    public function test_the_per_address_limit_stays_with_the_file(): void
    {
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);
        $this->reachOverallLimit();
        $file = $this->fileOf($this->get('/install')->getContent());
        file_put_contents($file, '');

        for ($i = 0; $i < 5; $i++) {
            $this->fromAddress('192.0.2.7')->post('/install/resume', ['resume_password' => 'guess-'.$i])->assertSessionHasErrors('resume_password');
        }
        $this->fromAddress('192.0.2.7')->post('/install/resume', ['resume_password' => 'the-db-password'])->assertSessionHasErrors('resume_password');
        $this->assertSame('Too many tries. Wait 10 minutes and try again.', (string) session('errors')->first('resume_password'));
        $this->assertFileExists($file, 'not used: the address was over its own limit');
    }

    public function test_an_earlier_installers_half_install_gets_the_same_proof(): void
    {
        $installer = $this->installer();
        $this->app->instance(Installer::class, $installer);
        $this->setEnv('APP_INSTALLED', 'true');
        $this->setEnv('DB_PASSWORD', 'secret-of-env');
        $this->setEnv('DB_HOST', '127.0.0.1');
        $this->setEnv('DB_DATABASE', 'half_installed');
        $this->setEnv('DB_USERNAME', 'site_user');
        $this->assertSame('env', $installer->unfinishedInstallation()['kind']);
        $this->reachOverallLimit();

        $file = $this->fileOf($this->get('/install')->assertStatus(503)->getContent());
        $this->post('/install/resume', ['resume_password' => 'secret-of-env'])->assertSessionHasErrors('resume_password');
        file_put_contents($file, '');
        $this->post('/install/resume', ['resume_password' => 'secret-of-env'])->assertRedirect('/install?step=requirements');
        $this->assertFileDoesNotExist($file);
    }

    public function test_with_the_reinstall_switch_the_first_page_names_the_file_too(): void
    {
        $this->setEnv('INSTALLER_ALLOW_REINSTALL', 'true');
        $installer = $this->installer();
        $installer->startRun($this->payload(), 'owner-token');
        $this->app->instance(Installer::class, $installer);
        $this->reachOverallLimit();

        $page = (string) $this->get('/install')->assertOk()->getContent();
        $this->assertStringContainsString('name="resume_password"', $page);
        $this->assertMatchesRegularExpression('#data-resume-file>.*?data-copy="resume-file-name".*?<pre id="resume-file-name"[^>]*>continue-install-[0-9a-f]{16}\.txt</pre>#s', $page);
    }

    private function reachOverallLimit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('installer-resume', 3600);
        }
    }

    private function fileOf(mixed $page): string
    {
        $this->assertSame(1, preg_match('#continue-install-[0-9a-f]{16}\.txt#', (string) $page, $name), 'the page names the file');
        $file = storage_path('app/'.$name[0]);
        $this->made[] = $file;

        return $file;
    }

    private function fromAddress(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function clearLimits(): void
    {
        RateLimiter::clear('installer-resume');
        foreach (['127.0.0.1', '192.0.2.7', '203.0.113.9'] as $ip) {
            RateLimiter::clear('installer-resume:'.sha1($ip));
        }
        foreach (range(1, 6) as $n) {
            RateLimiter::clear('installer-resume:'.sha1('198.51.100.'.$n));
        }
    }

    /** The installer on this test's folder; the run's database answers the one password 'the-db-password'. */
    private function installer(): Installer
    {
        return new class($this->dir) extends Installer
        {
            public int $proofs = 0;

            public function __construct(private string $testDir)
            {
            }

            public function proveOwnership(array $pending, string $password): bool
            {
                $this->proofs++;

                return parent::proveOwnership($pending, $password);
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
                if (($database['password'] ?? null) !== 'the-db-password') {
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
            }

            protected function prepareFiles(InstallRun $run): void
            {
                $run->message('Files prepared (test).');
            }

            protected function installSchedulerCron(): void
            {
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

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
