<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Installer;
use App\Support\TelegramLinks;
use App\Support\Upload18\BundledKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 1 judge, installer items:
 *  - R1-013: "Run migrations and seeders" stored no Upload18 key (uploads stopped at once); the site now gets the key
 *    the package ships, read from its install data like the SQL backup mode imports it. Only that mode: an imported
 *    backup keeps its own key, also one its owner left empty (reviewer finding R1FX-REV-2).
 *  - R1-014: that mode showed AVDBAPI's Telegram group as the site's own channel; it now starts empty, as the bundled
 *    SQL backup does.
 *  - R1-015: "From email" was prefilled with noreply@example.com of .env.example, so the administrator's e-mail never
 *    applied; example addresses are now never offered nor kept.
 *  - R1-104 / R1-105: the locked page (no steps, one column, no mid-word break, no checks) and the phone layout.
 */
class InstallerRoundOneTest extends TestCase
{
    use RefreshDatabase;

    private const SHIPPED_TEST_KEY = 'R1fxShippedTestKey0123456789abcdef';

    private array $environment = [];

    private ?string $sqlFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['MAIL_FROM_ADDRESS', 'UPLOAD18_API_KEY', 'INSTALLER_ALLOW_REINSTALL', 'APP_INSTALLED'] as $key) {
            $this->environment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        }
        $this->env('UPLOAD18_API_KEY', '');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => [$env, $server, $process]) {
            $env === null ? $this->forget($key, '_ENV') : $_ENV[$key] = $env;
            $server === null ? $this->forget($key, '_SERVER') : $_SERVER[$key] = $server;
            putenv($process === false ? $key : $key.'='.$process);
        }
        BundledKey::$exampleSqlPath = null;
        if ($this->sqlFile !== null) {
            @unlink($this->sqlFile);
        }

        parent::tearDown();
    }

    // R1-015

    public function test_example_addresses_are_not_from_addresses(): void
    {
        foreach (['noreply@example.com', 'hello@example.com', 'mail@example.org', 'x@example.net', 'a@sub.example.com', 'a@shop.test',
            'a@x.invalid', 'root@localhost', 'a@site.example', ' noreply@EXAMPLE.COM ', 'not-an-address', '', '@example.com'] as $example) {
            $this->assertSame('', Installer::realFromAddress($example), $example);
        }

        foreach (['noreply@mysite.com', 'mail@example-shop.com', 'owner@examples.org', 'news@mytest.net'] as $real) {
            $this->assertSame($real, Installer::realFromAddress($real), $real);
        }
    }

    public function test_the_admin_step_does_not_offer_the_example_from_address_of_env_example(): void
    {
        $this->env('MAIL_FROM_ADDRESS', 'noreply@example.com');
        $this->assertSame('', (new Installer())->appDefaults('https://mysite.test')['mail_from_address']);

        $this->env('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->env('APP_INSTALLED', 'auto');
        $page = (string) $this->get('/install?step=admin')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<input id="mail_from_address" type="email" name="mail_from_address" value=""~', $page);
        $this->assertStringContainsString('Empty: your admin email is used.', $page);

        // An address of the owner's in .env is still offered.
        $this->env('MAIL_FROM_ADDRESS', 'news@mysite.net');
        $this->assertSame('news@mysite.net', (new Installer())->appDefaults('https://mysite.test')['mail_from_address']);
    }

    public function test_an_example_from_address_is_replaced_by_the_admins_email_when_installing(): void
    {
        $this->assertSame('owner@mysite.net', $this->installerEnv('noreply@example.com')['MAIL_FROM_ADDRESS']);
        $this->assertSame('owner@mysite.net', $this->installerEnv('')['MAIL_FROM_ADDRESS']);
        $this->assertSame('mail@mysite.net', $this->installerEnv('mail@mysite.net')['MAIL_FROM_ADDRESS']);

        $this->writeCoreSettings(['mail_from_address' => 'noreply@example.com']);
        $this->assertSame('owner@mysite.net', Setting::query()->where('key', 'mail_from_address')->value('value'));
    }

    // R1-013

    public function test_migrations_mode_gets_the_key_the_package_ships(): void
    {
        $this->shippedKeyInInstallData(self::SHIPPED_TEST_KEY);
        DB::table('settings')->where('key', 'upload18_api_key')->delete();

        $this->migrationsModeSettings();

        $this->assertSame(self::SHIPPED_TEST_KEY, $this->storedKey());
        $this->assertSame('uploads', Setting::query()->where('key', 'upload18_api_key')->value('group'));
    }

    public function test_in_migrations_mode_the_seeders_empty_row_gets_it_too_but_a_stored_or_env_key_wins(): void
    {
        $this->shippedKeyInInstallData(self::SHIPPED_TEST_KEY);

        // The database was empty before the migrations: an empty row is the seeders', nobody's choice.
        Setting::set('upload18_api_key', '', 'uploads');
        $this->migrationsModeSettings();
        $this->assertSame(self::SHIPPED_TEST_KEY, $this->storedKey());

        // A key already stored stays.
        Setting::set('upload18_api_key', 'OwnersRestrictedKey0123456789', 'uploads');
        $this->migrationsModeSettings();
        $this->assertSame('OwnersRestrictedKey0123456789', $this->storedKey());

        // A key in the installer's environment (UPLOAD18_API_KEY) is used, as before.
        DB::table('settings')->where('key', 'upload18_api_key')->delete();
        $this->env('UPLOAD18_API_KEY', 'EnvironmentKey0123456789abcdef');
        $this->migrationsModeSettings();
        $this->assertSame('EnvironmentKey0123456789abcdef', $this->storedKey());
    }

    public function test_an_imported_backup_keeps_its_upload18_key_also_one_its_owner_left_empty(): void
    {
        // "Import SQL backup" (the bundled example.sql or an owner's own backup) writes only the core settings: the
        // installer never puts the shared key into a backup whose key was cleared on purpose (reviewer R1FX-REV-2).
        $this->shippedKeyInInstallData(self::SHIPPED_TEST_KEY);

        Setting::set('upload18_api_key', '', 'uploads');
        $this->writeCoreSettings();
        $this->assertSame('', $this->storedKey(), 'a key left empty stays empty');

        DB::table('settings')->where('key', 'upload18_api_key')->delete();
        $this->writeCoreSettings();
        $this->assertNull($this->storedKey(), 'a backup without the row gets none either');

        Setting::set('upload18_api_key', 'OwnersRestrictedKey0123456789', 'uploads');
        $this->writeCoreSettings();
        $this->assertSame('OwnersRestrictedKey0123456789', $this->storedKey(), 'the backup\'s own key stays');

        // The environment's key still applies in this mode too.
        $this->env('UPLOAD18_API_KEY', 'EnvironmentKey0123456789abcdef');
        $this->writeCoreSettings();
        $this->assertSame('EnvironmentKey0123456789abcdef', $this->storedKey());
    }

    public function test_only_the_migrations_branch_applies_the_shipped_key(): void
    {
        $source = (string) file_get_contents(app_path('Support/Installer.php'));

        $this->assertSame(1, substr_count($source, '$this->applyShippedUpload18Key();'), 'called in one place only');
        $this->assertMatchesRegularExpression(
            "~Artisan::call\('db:seed', \['--force' => true\]\);\s+\\\$this->applyBundledTelegramDefaults\(\);\s+\\\$this->applyShippedUpload18Key\(\);~",
            $source,
            'right after the seeders, in the "Run migrations and seeders" branch'
        );

        $method = new \ReflectionMethod(Installer::class, 'writeCoreSettings');
        $lines = array_slice(explode("\n", $source), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $this->assertStringNotContainsString('shippedUpload18Key', implode("\n", $lines), 'both modes\' core settings never read the shipped key');
    }

    public function test_without_a_usable_shipped_key_nothing_is_stored(): void
    {
        DB::table('settings')->where('key', 'upload18_api_key')->delete();

        // no install data
        BundledKey::$exampleSqlPath = storage_path('framework/testing/no-such-example.sql');
        $this->migrationsModeSettings();
        $this->assertNull($this->storedKey());

        // install data whose key is not one (too short, spaces)
        $this->shippedKeyInInstallData('short');
        $this->migrationsModeSettings();
        $this->assertNull($this->storedKey());
    }

    // R1-014

    public function test_migrations_mode_starts_without_avdbapis_group_as_the_sites_telegram_channel(): void
    {
        $apply = new \ReflectionMethod(Installer::class, 'applyBundledTelegramDefaults');

        foreach ([TelegramLinks::GROUP_URL, 'https://t.me/avdb_api', 't.me/avdb_api', '@avdb_api'] as $seeded) {
            Setting::set('social_telegram', $seeded, 'sociallinks');
            $apply->invoke(new Installer());
            $this->assertSame('', Setting::query()->where('key', 'social_telegram')->value('value'), $seeded);
        }

        // No row at all shows the group too (AppServiceProvider): an empty row is written.
        DB::table('settings')->where('key', 'social_telegram')->delete();
        $apply->invoke(new Installer());
        $this->assertSame('', Setting::query()->where('key', 'social_telegram')->value('value'));

        // Any other value stays.
        Setting::set('social_telegram', 'https://t.me/my_own_channel', 'sociallinks');
        $apply->invoke(new Installer());
        $this->assertSame('https://t.me/my_own_channel', Setting::query()->where('key', 'social_telegram')->value('value'));
    }

    public function test_the_migrations_branch_applies_the_bundled_telegram_defaults(): void
    {
        $source = (string) file_get_contents(app_path('Support/Installer.php'));
        $this->assertMatchesRegularExpression(
            "~Artisan::call\('db:seed', \['--force' => true\]\);\s+\\\$this->applyBundledTelegramDefaults\(\);~",
            $source
        );
    }

    // R1-104

    public function test_the_locked_page_has_no_steps_one_column_and_runs_no_check(): void
    {
        $path = storage_path('framework/testing/installer-lock-'.uniqid().'.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, '{"installed_at":"2026-10-27"}');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $installer = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('lockPath')->andReturn($path);
        foreach (['requirements', 'filesystemChecks', 'serverGuidance', 'backupInfo', 'environmentSummary', 'serverConfigSnippet', 'schedulerCronCommand', 'storageExposure'] as $check) {
            $installer->shouldNotReceive($check);
        }
        $this->app->instance(Installer::class, $installer);

        $page = (string) $this->get('/install')->assertOk()->assertSee('Already Installed')->assertSee('Open Admin')->getContent();

        $this->assertStringNotContainsString('class="stepper"', $page, 'no steps on the locked page');
        $this->assertStringNotContainsString('Current step', $page);
        $this->assertStringContainsString('<section class="grid single">', $page);
        $this->assertStringContainsString('code { color: #d9e4ff; overflow-wrap: anywhere; word-break: normal; }', $page);
        $this->assertStringNotContainsString('word-break: break-all', $page);
    }

    // R1-105

    public function test_after_the_first_step_the_hero_is_compact_and_the_help_card_also_follows_the_form(): void
    {
        $this->env('INSTALLER_ALLOW_REINSTALL', 'true');
        $this->env('APP_INSTALLED', 'auto');

        $intro = (string) $this->get('/install?step=introduction')->assertOk()->getContent();
        $this->assertStringContainsString('<header class="hero">', $intro);

        foreach (['database', 'admin', 'install'] as $step) {
            $page = (string) $this->get('/install?step='.$step)->assertOk()->getContent();
            $this->assertStringContainsString('<header class="hero hero-compact">', $page, $step);
            $this->assertStringContainsString('class="support-card hero-help"', $page, $step);
            $this->assertStringContainsString('class="support-card bottom-help"', $page, $step);
            $this->assertStringContainsString('class="stepper"', $page, $step);
            $this->assertSame(2, substr_count($page, TelegramLinks::SUPPORT_URL), $step.': both help cards link support');
        }
    }

    /** @return array<string, string> */
    private function installerEnv(string $from): array
    {
        $method = new \ReflectionMethod(Installer::class, 'envValues');

        return $method->invoke(new Installer(), [
            'host' => '127.0.0.1', 'port' => '3306', 'database' => 'avdbcms', 'username' => 'installer', 'password' => 'secret',
        ], ['username' => 'owner', 'email' => 'owner@mysite.net', 'password' => 'Password123!'], $this->appValues(['mail_from_address' => $from]));
    }

    private function writeCoreSettings(array $app = []): void
    {
        $method = new \ReflectionMethod(Installer::class, 'writeCoreSettings');
        $method->invoke(new Installer(), ['username' => 'owner', 'email' => 'owner@mysite.net', 'password' => 'x'], $this->appValues($app));
    }

    /** What "Run migrations and seeders" does to the settings after the seeders: the bundled defaults, then the core settings. */
    private function migrationsModeSettings(): void
    {
        (new \ReflectionMethod(Installer::class, 'applyShippedUpload18Key'))->invoke(new Installer());
        $this->writeCoreSettings();
    }

    private function storedKey(): ?string
    {
        return Setting::query()->where('key', 'upload18_api_key')->value('value');
    }

    private function appValues(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'My site', 'url' => 'https://mysite.net', 'mail_mailer' => 'log', 'mail_host' => '', 'mail_port' => '587',
            'mail_username' => '', 'mail_password' => '', 'mail_encryption' => '', 'mail_from_address' => '',
        ];
    }

    /** Install data (an example.sql) whose settings hold this upload18_api_key row. */
    private function shippedKeyInInstallData(string $key): void
    {
        $this->sqlFile ??= storage_path('framework/testing/r1fx-example-'.uniqid().'.sql');
        @mkdir(dirname($this->sqlFile), 0777, true);
        file_put_contents($this->sqlFile, "INSERT INTO `settings` VALUES\n(1,'site_name','AVDBCMS','general',NULL,NULL),\n(2,'upload18_api_key','".$key."','uploads',NULL,NULL);\n");
        BundledKey::$exampleSqlPath = $this->sqlFile;
    }

    private function env(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    private function forget(string $key, string $where): void
    {
        if ($where === '_ENV') {
            unset($_ENV[$key]);
        } else {
            unset($_SERVER[$key]);
        }
    }
}
