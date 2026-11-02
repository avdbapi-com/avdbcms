<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    private array $runtimeFlagEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Local reinstall flags must not change the installer expectations.
        foreach (['APP_INSTALLED' => 'auto', 'INSTALLER_ALLOW_REINSTALL' => 'false'] as $key => $value) {
            $this->runtimeFlagEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        Schema::dropIfExists('users');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        $this->setInstallerAllowReinstall(null);
        foreach ($this->runtimeFlagEnvironment as $key => [$envValue, $serverValue, $processValue]) {
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
            putenv($processValue === false ? $key : $key.'='.$processValue);
        }

        parent::tearDown();
    }

    public function test_installer_intro_renders_for_fresh_setup(): void
    {
        $this->setInstallerAllowReinstall(true);

        $this->get('/install')
            ->assertOk()
            ->assertSee('AVDBCMS Installer')
            ->assertSee('Start Installation');
    }

    public function test_installer_steps_render_without_server_errors(): void
    {
        $this->setInstallerAllowReinstall(true);

        foreach (['requirements', 'filesystem', 'database', 'admin', 'install'] as $step) {
            $this->get('/install?step='.$step)
                ->assertOk();
        }
    }

    public function test_zip_extension_is_recommended_not_install_blocking(): void
    {
        $zipCheck = collect(app(\App\Support\Installer::class)->requirements())
            ->firstWhere('label', 'PHP extension: zip');

        $this->assertIsArray($zipCheck);
        $this->assertFalse($zipCheck['required']);
    }

    public function test_clean_install_selects_migrations_when_no_backup_is_bundled(): void
    {
        $this->assertDefaultImportMode(false);
    }

    /**
     * The bundled example.sql is a new site's starting point (its settings, languages, categories and plans; the
     * README, the help site and the release check rely on it), so the Database step preselects it: the default to
     * migrations of 2026-09-23 was reverted on 2026-09-26 after the sites installed that way came out unusable.
     * Migrations stay one click away, and an owner's own choice is kept.
     */
    public function test_database_step_preselects_the_bundled_backup_and_keeps_migrations_available(): void
    {
        $this->assertDefaultImportMode(true);
    }

    public function test_a_migrations_choice_is_kept_when_a_backup_is_available(): void
    {
        // the choice saved by the Database step, and the one sent back after a validation error
        foreach ([['installer.database' => ['mode' => 'migrate']], ['_old_input' => ['mode' => 'migrate']]] as $session) {
            $xpath = $this->xpathOf($this->databaseStepResponse(true, $session));

            $this->assertSame(1, $xpath->query('//input[@name="mode" and @value="migrate" and @checked]')->length);
            $this->assertSame(0, $xpath->query('//input[@name="mode" and @value="backup" and @checked]')->length);
        }
    }

    public function test_backup_mode_is_refused_when_no_backup_is_bundled(): void
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldReceive('backupInfo')->andReturn($this->backupFixture(false));
        $installer->shouldNotReceive('testDatabase');
        $this->app->instance(\App\Support\Installer::class, $installer);

        $this->post('/install/database', [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'avdbcms',
            'username' => 'installer',
            'password' => 'secret',
            'mode' => 'backup',
        ])->assertRedirect('/install?step=database')->assertSessionHasErrors('mode');
    }

    public function test_the_review_step_names_the_bundled_backup_as_the_import(): void
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldReceive('backupInfo')->andReturn($this->backupFixture(true));
        $this->app->instance(\App\Support\Installer::class, $installer);

        $this->get('/install?step=install')
            ->assertOk()
            ->assertSee('SQL backup: example.sql')
            ->assertDontSee('Migrations and seeders</dd>', false);
    }

    /**
     * R1B-001: the final .env (APP_INSTALLED=true, a new key) is written only after the database work and the admin
     * account succeeded. Before, it was written first and a failure put the old one back - from a shutdown function too,
     * which a host that ends the request (PHP-FPM's request_terminate_timeout) never runs: the site was left "Already
     * installed" over an empty database. Now the database work runs while .env is still the one the upload had, and a
     * failure leaves that one as it is.
     */
    public function test_a_failed_install_leaves_the_env_it_found(): void
    {
        // without this seam the installer would write the copy's own .env
        $this->assertTrue(method_exists(\App\Support\Installer::class, 'envPath'), 'Installer::envPath() is missing.');

        $directory = storage_path('framework/testing/installer-env-'.bin2hex(random_bytes(6)));
        @mkdir($directory, 0777, true);
        $envPath = $directory.'/.env';
        $original = "APP_NAME=AVDBCMS\nAPP_KEY=\nAPP_INSTALLED=false\n";
        file_put_contents($envPath, $original);
        $backups = glob(storage_path('app/installer-backups/.env.backup*')) ?: [];

        $installer = new class($envPath) extends \App\Support\Installer
        {
            public ?string $envDuringInstall = null;

            public function __construct(private string $testEnvPath)
            {
            }

            protected function envPath(): string
            {
                return $this->testEnvPath;
            }

            protected function targetDatabasePdo(array $database): \PDO
            {
                return new \PDO('sqlite::memory:');
            }

            protected function databaseObjects(\PDO $pdo, string $database): array
            {
                return [];
            }

            public function repairFilesystem(): void
            {
            }

            protected function applyRuntimeDatabaseConfig(array $database): void
            {
                // the database work starts; it fails, like a server that drops the connection
                $this->envDuringInstall = (string) file_get_contents($this->testEnvPath);

                throw new \RuntimeException('The database server closed the connection.');
            }
        };

        try {
            $installer->install($this->installPayload(false));
            $this->fail('A failing database step must stop the installation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The database server closed the connection.', $exception->getMessage());
        } finally {
            $after = is_file($envPath) ? (string) file_get_contents($envPath) : null;
            @unlink($envPath);
            @rmdir($directory);
            $copies = array_values(array_diff(glob(storage_path('app/installer-backups/.env.backup*')) ?: [], $backups));
            foreach ($copies as $copy) {
                @unlink($copy);
            }
        }

        $this->assertSame($original, $installer->envDuringInstall, 'The database work runs before .env is written.');
        $this->assertStringNotContainsString('APP_INSTALLED=true', (string) $installer->envDuringInstall);
        $this->assertSame($original, $after, 'A failed install must leave the .env it found, so the wizard stays open.');
        $this->assertSame([], $copies, 'nothing was copied: .env was never touched');
    }

    public function test_database_step_requires_explicit_replacement_for_populated_database(): void
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldReceive('testDatabase')->once()->andReturn([
            'ok' => true,
            'message' => 'Connected successfully.',
            'version' => 'MariaDB 10.11',
            'tables' => ['users'],
        ]);
        $this->app->instance(\App\Support\Installer::class, $installer);

        $response = $this->post('/install/database', [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'avdbcms',
            'username' => 'installer',
            'password' => 'secret',
            'mode' => 'migrate',
        ]);

        $response->assertRedirect('/install?step=database');
        $response->assertSessionHasErrors('overwrite');
        $this->assertNull(session('installer.database'));
    }

    public function test_database_step_accepts_populated_database_only_when_replacement_is_checked(): void
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldReceive('testDatabase')->once()->andReturn([
            'ok' => true,
            'message' => 'Connected successfully.',
            'version' => 'MariaDB 10.11',
            'tables' => ['users'],
        ]);
        $this->app->instance(\App\Support\Installer::class, $installer);

        $response = $this->post('/install/database', [
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'avdbcms',
            'username' => 'installer',
            'password' => 'secret',
            'mode' => 'migrate',
            'overwrite' => '1',
        ]);

        $response->assertRedirect('/install?step=admin');
        $this->assertTrue((bool) session('installer.database.overwrite'));
    }

    public function test_install_guard_rejects_populated_target_before_writing_environment(): void
    {
        $envPath = base_path('.env');
        $before = is_file($envPath) ? (string) file_get_contents($envPath) : null;
        $installer = new class extends \App\Support\Installer
        {
            protected function targetDatabasePdo(array $database): \PDO
            {
                return new \PDO('sqlite::memory:');
            }

            protected function databaseObjects(\PDO $pdo, string $database): array
            {
                return [['name' => 'users', 'type' => 'BASE TABLE']];
            }
        };

        try {
            $installer->install($this->installPayload(false));
            $this->fail('A populated database must be rejected before installation changes state.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Existing data was preserved', $exception->getMessage());
        }

        $after = is_file($envPath) ? (string) file_get_contents($envPath) : null;
        $this->assertSame($before, $after);
    }

    public function test_install_guard_fails_closed_when_database_introspection_fails(): void
    {
        $envPath = base_path('.env');
        $before = is_file($envPath) ? (string) file_get_contents($envPath) : null;
        $installer = new class extends \App\Support\Installer
        {
            protected function targetDatabasePdo(array $database): \PDO
            {
                return new \PDO('sqlite::memory:');
            }

            protected function databaseObjects(\PDO $pdo, string $database): array
            {
                throw new \RuntimeException('Could not safely inspect the selected database. No installation changes were made.');
            }
        };

        try {
            $installer->install($this->installPayload(false));
            $this->fail('An introspection failure must stop installation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No installation changes were made', $exception->getMessage());
        }

        $after = is_file($envPath) ? (string) file_get_contents($envPath) : null;
        $this->assertSame($before, $after);
    }

    public function test_database_introspection_wraps_driver_errors_in_fail_closed_exception(): void
    {
        $installer = new class extends \App\Support\Installer
        {
            public function inspect(\PDO $pdo): array
            {
                return $this->databaseObjects($pdo, 'avdbcms');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not safely inspect the selected database');
        $installer->inspect(new \PDO('sqlite::memory:'));
    }

    public function test_final_install_requires_fresh_replacement_confirmation(): void
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldNotReceive('install');
        $this->app->instance(\App\Support\Installer::class, $installer);
        session([
            'installer.database' => ['overwrite' => true],
            'installer.admin' => ['username' => 'admin'],
            'installer.app' => ['name' => 'AVDBCMS'],
        ]);

        $this->post('/install/install')
            ->assertRedirect('/install?step=install')
            ->assertSessionHasErrors('confirm_overwrite');
    }

    /** @return array<string, mixed> */
    private function installPayload(bool $overwrite): array
    {
        return [
            'database' => [
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'avdbcms',
                'username' => 'installer',
                'password' => 'secret',
                'mode' => 'migrate',
                'overwrite' => $overwrite,
            ],
            'admin' => ['username' => 'admin', 'email' => 'admin@example.test', 'password' => 'Password123!'],
            'app' => [
                'name' => 'AVDBCMS',
                'url' => 'https://example.test',
                'mail_mailer' => 'log',
                'mail_host' => '',
                'mail_port' => '587',
                'mail_username' => '',
                'mail_password' => '',
                'mail_encryption' => '',
                'mail_from_address' => 'admin@example.test',
            ],
        ];
    }

    /** Exactly one import mode is preselected: the bundled backup when there is one, else migrations. */
    private function assertDefaultImportMode(bool $backupExists): void
    {
        $response = $this->databaseStepResponse($backupExists);
        $xpath = $this->xpathOf($response);
        $preselected = $backupExists ? 'backup' : 'migrate';
        $other = $backupExists ? 'migrate' : 'backup';

        $this->assertSame(1, $xpath->query('//input[@name="mode" and @value="'.$preselected.'" and @checked]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="mode" and @value="'.$other.'" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@type="radio" and @name="mode" and @checked]')->length);
        $this->assertSame($backupExists ? 0 : 1, $xpath->query('//input[@name="mode" and @value="backup" and @disabled]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="mode" and @value="migrate" and @disabled]')->length);

        if ($backupExists) {
            $response->assertSee('Keep Import SQL backup')->assertDontSee('Use migrations for a fresh installation');
        } else {
            $response->assertSee('No SQL backup was found');
        }
    }

    private function databaseStepResponse(bool $backupExists, array $session = []): \Illuminate\Testing\TestResponse
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldReceive('backupInfo')->andReturn($this->backupFixture($backupExists));
        $this->app->instance(\App\Support\Installer::class, $installer);

        return $this->withSession($session)->get('/install?step=database')->assertOk();
    }

    private function xpathOf(\Illuminate\Testing\TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML((string) $response->getContent());

        return new \DOMXPath($document);
    }

    /** @return array<string, mixed> */
    private function backupFixture(bool $exists): array
    {
        return [
            'exists' => $exists,
            'path' => null,
            'filename' => 'example.sql',
            'configured_filename' => 'example.sql',
            'size' => $exists ? '1 KB' : 'Not bundled',
            'source' => 'test fixture',
            'available' => [],
        ];
    }

    public function test_installer_locks_when_admin_user_exists(): void
    {
        $this->createUsersTable();

        DB::table('users')->insert([
            'username' => 'admin',
            'slug' => 'admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'is_admin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/install')
            ->assertOk()
            ->assertSee('Already Installed')
            ->assertSee('Open Admin');
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    // Security plan S12: the install gate.

    public function test_the_install_lock_beats_app_installed_false(): void
    {
        $installer = $this->installerWithLock(true);
        $this->setRuntimeFlag('APP_INSTALLED', 'false');

        $this->assertTrue($installer->isInstalled());
        $this->get('/install')->assertOk()->assertSee('Already Installed');
        $this->post('/install/introduction')->assertForbidden();
    }

    public function test_without_a_lock_app_installed_false_and_the_reinstall_switch_open_the_wizard(): void
    {
        $installer = $this->installerWithLock(false);
        $this->setRuntimeFlag('APP_INSTALLED', 'false');
        $this->assertFalse($installer->isInstalled());
        $this->get('/install')->assertOk()->assertDontSee('Already Installed');

        // The owner's reinstall switch opens it even with the lock.
        $locked = $this->installerWithLock(true);
        $this->setInstallerAllowReinstall(true);
        $this->assertFalse($locked->isInstalled());
    }

    public function test_a_configured_site_whose_database_is_down_keeps_the_wizard_closed(): void
    {
        $installer = $this->installerWithLock(false);
        $this->assertTrue(trim((string) \App\Support\RuntimeEnv::get('APP_KEY', '')) !== '', 'this copy has an APP_KEY');

        $this->withUnreachableDatabase(function () use ($installer): void {
            $this->assertTrue($installer->isInstalled(), 'a key in .env: a site that is down');
            $this->post('/install/introduction')->assertForbidden();
        });
    }

    public function test_a_fresh_upload_whose_database_is_not_reachable_yet_opens_the_wizard(): void
    {
        $installer = $this->installerWithLock(false, configured: false);

        $this->withUnreachableDatabase(function () use ($installer): void {
            $this->assertFalse($installer->isInstalled(), 'no key and no database user yet: a fresh upload');
        });
    }

    // Security plan S19: after installation the public /install page names no server detail.

    public function test_the_locked_page_names_no_server_detail(): void
    {
        $this->installerWithLock(true);
        $html = (string) $this->get('/install')->assertOk()->assertSee('Already Installed')->assertSee('Open Admin')->getContent();

        foreach ([base_path(), str_replace('\\', '/', base_path()), public_path(), str_replace('\\', '/', public_path()),
            'Current Environment', 'Project root', 'Public path', '<span>Web server</span>', '<span>SQL backup</span>'] as $detail) {
            $this->assertStringNotContainsString($detail, $html);
        }

        $backup = app(\App\Support\Installer::class)->backupInfo();
        if ($backup['exists']) {
            $this->assertStringNotContainsString($backup['filename'], $html);
        }
    }

    public function test_the_wizard_before_installation_still_shows_the_environment(): void
    {
        $this->setInstallerAllowReinstall(true);

        $this->get('/install?step=introduction')->assertOk()
            ->assertSee('<span>Web server</span>', false)
            ->assertSee('<span>SQL backup</span>', false)
            ->assertSee('Detected panel');
    }

    /** An installer whose lock file is a temporary path that exists or not (the copy's own lock stays untouched). */
    private function installerWithLock(bool $locked, bool $configured = true): \App\Support\Installer
    {
        $path = storage_path('framework/testing/installer-lock-'.uniqid().'.json');
        @mkdir(dirname($path), 0777, true);

        if ($locked) {
            file_put_contents($path, '{"installed_at":"2026-10-25"}');
            $this->beforeApplicationDestroyed(fn () => @unlink($path));
        }

        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('lockPath')->andReturn($path);

        if (! $configured) {
            $installer->shouldReceive('hasConfiguredEnvironment')->andReturn(false);
        }

        $this->app->instance(\App\Support\Installer::class, $installer);

        return $installer;
    }

    /** Runs the callback while the default database connection points at a closed port (a database that is down). */
    private function withUnreachableDatabase(\Closure $callback): void
    {
        $default = config('database.default');
        config(['database.connections.unreachable' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'avd_down', 'username' => 'nobody',
            'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            'options' => [\PDO::ATTR_TIMEOUT => 2],
        ], 'database.default' => 'unreachable']);
        \Illuminate\Support\Facades\DB::purge('unreachable');

        try {
            $callback();
        } finally {
            config(['database.default' => $default]);
            \Illuminate\Support\Facades\DB::purge('unreachable');
        }
    }

    private function setRuntimeFlag(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function setInstallerAllowReinstall(?bool $value): void
    {
        if ($value === null) {
            putenv('INSTALLER_ALLOW_REINSTALL');
            unset($_ENV['INSTALLER_ALLOW_REINSTALL'], $_SERVER['INSTALLER_ALLOW_REINSTALL']);

            return;
        }

        $stringValue = $value ? 'true' : 'false';

        putenv('INSTALLER_ALLOW_REINSTALL='.$stringValue);
        $_ENV['INSTALLER_ALLOW_REINSTALL'] = $stringValue;
        $_SERVER['INSTALLER_ALLOW_REINSTALL'] = $stringValue;
    }
}
