<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\AppServiceProvider;
use App\Support\Installer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

/**
 * The installer's "Import SQL backup" on a real MySQL or MariaDB server, end to end: database/install/example.sql is
 * imported the way the installer imports it, every migration runs, and the site's settings and Premium page are
 * checked. Then the same database plays a 1.2.1 site (bundled plan limits, the 1.2.2 migrations not yet run) and a
 * site whose owner chose values, on the server's own collation, which ignores trailing spaces in "=".
 *
 * Opt-in, because it needs a server: AVD_TEST_MYSQL_HOST (and AVD_TEST_MYSQL_PORT, AVD_TEST_MYSQL_USER,
 * AVD_TEST_MYSQL_PASSWORD). It creates a throwaway database avdbcms_install_<random>_test and drops it afterwards.
 */
class ExampleSqlInstallTest extends TestCase
{
    private const CONNECTION = 'example_sql_test';

    private const PLAN_MIGRATION = '2026_10_24_120000_correct_bundled_plan_upload_limits';

    private const SEEDER_MIGRATION = '2026_10_24_120100_remove_shipped_development_seeders';

    private const SHIPPED_122 = [
        'free_max_uploads' => '10',
        'free_max_file_size' => '524288000',
        'free_max_storage' => '5368709120',
        'premium_max_uploads' => '',
        'premium_max_file_size' => '2147483648',
        'premium_max_storage' => '',
        'trial_period_days' => '30',
        'trial_email_reminder' => '7',
    ];

    private const BUNDLED_121 = [
        'free_max_uploads' => '1000000',
        'free_max_file_size' => '10485760000',
        'free_max_storage' => '10995116277760',
        'premium_max_file_size' => '104857600000',
    ];

    private ?PDO $server = null;

    private ?string $database = null;

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== null) {
            try {
                DB::purge(self::CONNECTION);
                $this->server->exec('DROP DATABASE IF EXISTS `'.$this->database.'`');
            } catch (Throwable) {
                // The server went away: nothing left to clean up from here.
            }
        }

        parent::tearDown();
    }

    public function test_a_new_site_from_example_sql_and_its_updates(): void
    {
        $pdo = $this->createDatabase();

        // 1. A new 1.2.2 site, as the installer makes it: import example.sql, then run the migrations.
        $installer = app(Installer::class);
        $imported = (new ReflectionMethod($installer, 'importSqlBackup'))->invoke($installer, $pdo, database_path('install/example.sql'));
        $this->assertGreaterThan(100, $imported['statements']);
        $accounts =$this->db()->table('users')->orderBy('id')->pluck('password', 'username')->all();
        $this->migrate();

        // The sample members of example.sql have had unusable passwords since 1.2.0: no 1.2.2 migration touches them.
        $this->assertSame(['admin', 'pbergstrom', 'blackbox_creator'], array_keys($accounts));
        $this->assertSame($accounts, $this->db()->table('users')->orderBy('id')->pluck('password', 'username')->all());
        $this->assertSame(0, $this->db()->table('admin_audit_logs')->whereIn('action', ['security.known_passwords_reset', 'update.plan_limits_defaults'])->count());

        $files = array_map(fn (string $path): string => basename($path, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $ran = $this->db()->table('migrations')->pluck('migration')->all();
        $this->assertSame([], array_values(array_diff($files, $ran)), 'every migration ran');
        $this->assertContains(self::PLAN_MIGRATION, $ran);
        $this->assertContains(self::SEEDER_MIGRATION, $ran);
        $this->assertSame(self::SHIPPED_122, $this->planLimits(), 'a new site has the defaults of config/plans.php');

        // 2. A 1.2.1 site: the bundled limits, the 1.2.2 migration not run yet.
        $this->setPlanLimits(self::BUNDLED_121);
        $this->forgetMigration();
        $this->migrate();
        $this->assertSame(self::SHIPPED_122, $this->planLimits(), 'a 1.2.1 site gets the defaults');

        // 3. Values the owner chose stay, also one that this collation compares equal to a bundled value.
        $this->setPlanLimits(['free_max_uploads' => '1000000 ', 'free_max_file_size' => '2147483648'] + self::BUNDLED_121);
        $this->forgetMigration();
        $this->migrate();
        $this->assertSame(array_replace(self::SHIPPED_122, ['free_max_uploads' => '1000000 ', 'free_max_file_size' => '2147483648']), $this->planLimits());
        $this->assertSame(1, $this->db()->table('migrations')->where('migration', self::PLAN_MIGRATION)->count(), 'recorded once');

        // 4. The Premium page of the new site.
        $this->setPlanLimits(self::SHIPPED_122);
        $this->assertSame([
            'Max Video Uploads' => ['10', 'Unlimited'],
            'Max File Size Per Upload' => ['500 MB', '2 GB'],
            'Video Storage Space' => ['5 GB', 'Unlimited'],
        ], $this->premiumComparison());
    }

    private function createDatabase(): PDO
    {
        $host = (string) getenv('AVD_TEST_MYSQL_HOST');
        if ($host === '' || ! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Set AVD_TEST_MYSQL_HOST (and _PORT, _USER, _PASSWORD) to run the install test on MySQL or MariaDB.');
        }

        $port = (string) (getenv('AVD_TEST_MYSQL_PORT') ?: '3306');
        $user = (string) (getenv('AVD_TEST_MYSQL_USER') ?: 'root');
        $password = (string) (getenv('AVD_TEST_MYSQL_PASSWORD') ?: '');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

        $this->server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, $options);
        $this->database = 'avdbcms_install_'.bin2hex(random_bytes(4)).'_test';
        $this->server->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        config(['database.connections.'.self::CONNECTION => array_replace(config('database.connections.mysql'), [
            'url' => null,
            'host' => $host,
            'port' => $port,
            'database' => $this->database,
            'username' => $user,
            'password' => $password,
        ])]);

        return new PDO("mysql:host={$host};port={$port};dbname={$this->database};charset=utf8mb4", $user, $password, $options);
    }

    private function migrate(): void
    {
        $code = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);

        $this->assertSame(0, $code, Artisan::output());
    }

    private function forgetMigration(): void
    {
        $this->db()->table('migrations')->where('migration', self::PLAN_MIGRATION)->delete();
    }

    /** @param array<string, string> $values */
    private function setPlanLimits(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assertTrue($this->db()->table('settings')->where('group', 'planlimits')->where('key', $key)->exists(), $key);
            $this->db()->table('settings')->where('group', 'planlimits')->where('key', $key)->update(['value' => $value]);
        }
    }

    /** @return array<string, string> */
    private function planLimits(): array
    {
        $rows = $this->db()->table('settings')->where('group', 'planlimits')->orderBy('id')->pluck('value', 'key')->map(fn ($value) => (string) $value)->all();

        return array_replace(array_intersect_key(self::SHIPPED_122, $rows), $rows);
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function premiumComparison(): array
    {
        $this->withoutVite();
        config(['database.default' => self::CONNECTION]);
        Setting::clearCache();

        // What AppServiceProvider::applyDbSettings() does at the start of every web request (it skips the console).
        $provider = $this->app->getProvider(AppServiceProvider::class);
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $before = $console->getValue($this->app);
        $console->setValue($this->app, false);
        try {
            (new ReflectionMethod($provider, 'applyDbSettings'))->invoke($provider);
        } finally {
            $console->setValue($this->app, $before);
        }
        app()->setLocale('en');

        $html = $this->get(route('pages.premium'))->assertOk()->getContent();
        preg_match_all('#<td class="fw-bold">\s*(.*?)\s*</td>\s*<td>\s*(.*?)\s*</td>\s*<td>\s*(.*?)\s*</td>#s', $html, $rows, PREG_SET_ORDER);
        $table = [];

        foreach ($rows as [, $label, $free, $premium]) {
            if (in_array($label, ['Max Video Uploads', 'Max File Size Per Upload', 'Video Storage Space'], true)) {
                $table[$label] = [html_entity_decode(strip_tags($free)), html_entity_decode(strip_tags($premium))];
            }
        }

        return $table;
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }
}
