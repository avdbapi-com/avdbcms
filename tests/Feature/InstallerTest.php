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
        $this->assertMigrationChoice(false);
    }

    public function test_clean_install_defaults_to_migrations_even_if_a_backup_is_available(): void
    {
        $this->assertMigrationChoice(true);
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

    private function assertMigrationChoice(bool $backupExists): void
    {
        $this->setInstallerAllowReinstall(true);
        $installer = \Mockery::mock(\App\Support\Installer::class)->makePartial();
        $installer->shouldReceive('backupInfo')->andReturn([
            'exists' => $backupExists,
            'path' => null,
            'filename' => 'example.sql',
            'configured_filename' => 'example.sql',
            'size' => $backupExists ? '1 KB' : 'Not bundled',
            'source' => 'test fixture',
            'available' => [],
        ]);
        $this->app->instance(\App\Support\Installer::class, $installer);
        $response = $this->get('/install?step=database')->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//input[@name="mode" and @value="migrate" and @checked]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="mode" and @value="backup" and @checked]')->length);
        $this->assertSame($backupExists ? 0 : 1, $xpath->query('//input[@name="mode" and @value="backup" and @disabled]')->length);
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
