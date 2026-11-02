<?php

namespace Tests\Feature;

use App\Support\Installer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * The homepage feed's list ad on a real MySQL or MariaDB server: a new site from the installer's "Import SQL backup"
 * (database/install/example.sql, then every migration) gets the empty field; then the same database plays a 1.2.2
 * site (the migration not run yet) with and without a "New videos" ad code, with the owner's own homepage feed code,
 * and with a code the owner cleared.
 *
 * Opt-in, because it needs a server: AVD_TEST_MYSQL_HOST (and AVD_TEST_MYSQL_PORT, AVD_TEST_MYSQL_USER,
 * AVD_TEST_MYSQL_PASSWORD), like ExampleSqlInstallTest. It creates a throwaway database avdbcms_homefeed_<random>_test
 * and drops it afterwards.
 */
class HomepageFeedListAdInstallTest extends TestCase
{
    private const CONNECTION = 'homepage_feed_install_test';

    private const MIGRATION = '2026_10_25_120000_add_homepage_feed_list_ad';

    private const CODE = 'ad_code_grid_home_feed';

    private const SHAPE = 'ad_grid_layout_home_feed';

    private const AUDIT = 'update.homepage_feed_list_ad';

    private const SITE_AD = '<script async src="https://ads.example.com/zone.js" data-zone="42"></script>';

    private const OWNER_AD = '<div class="owner-homepage-ad">Owner</div>';

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

    public function test_new_sites_and_updates_of_1_2_2_sites(): void
    {
        $pdo = $this->createDatabase();

        // 1. A new site, as the installer makes it: example.sql (its ad codes are empty), then every migration.
        $installer = app(Installer::class);
        $imported = (new ReflectionMethod($installer, 'importSqlBackup'))->invoke($installer, $pdo, database_path('install/example.sql'));
        $this->assertGreaterThan(100, $imported['statements']);
        $this->assertFalse($this->db()->table('settings')->where('key', self::CODE)->exists(), 'example.sql has no homepage feed row');
        $this->assertSame('', (string) $this->db()->table('settings')->where('key', 'ad_code_grid_new_videos')->value('value'));
        $this->migrate();

        $this->assertContains(self::MIGRATION, $this->db()->table('migrations')->pluck('migration')->all());
        $this->assertSame(['', 'inherit'], $this->homepageFeed(), 'a new site starts with the empty field, like every list');
        $this->assertSame(0, $this->audits());

        // 2. A 1.2.2 site with a New videos code and a full-width row: the update copies both.
        $this->site122(self::SITE_AD, 'wide_row');
        $this->migrate();
        $this->assertSame([self::SITE_AD, 'wide_row'], $this->homepageFeed());
        $this->assertSame(1, $this->audits());
        $this->assertSame(1, $this->db()->table('migrations')->where('migration', self::MIGRATION)->count(), 'recorded once');

        // 3. A 1.2.2 site without a New videos code: the empty field.
        $this->site122('', 'wide_row');
        $this->migrate();
        $this->assertSame(['', 'inherit'], $this->homepageFeed());
        $this->assertSame(0, $this->audits());

        // 4. The owner's own homepage feed code stays.
        $this->site122(self::SITE_AD, 'wide_row');
        $this->db()->table('settings')->insert([
            ['key' => self::CODE, 'value' => self::OWNER_AD, 'group' => 'monetization', 'created_at' => '2026-10-26 08:00:00', 'updated_at' => '2026-10-26 08:00:00'],
            ['key' => self::SHAPE, 'value' => 'grid_card', 'group' => 'monetization', 'created_at' => '2026-10-26 08:00:00', 'updated_at' => '2026-10-26 08:00:00'],
        ]);
        $this->migrate();
        $this->assertSame([self::OWNER_AD, 'grid_card'], $this->homepageFeed());
        $this->assertSame(0, $this->audits());

        // 5. A code the owner cleared after the copy stays cleared, also when the migration runs again.
        $this->site122(self::SITE_AD, 'wide_row');
        $this->migrate();
        $this->db()->table('settings')->where('key', self::CODE)->update(['value' => '', 'updated_at' => now()->addHour()]);
        $this->forgetMigration();
        $this->migrate();
        $this->assertSame(['', 'wide_row'], $this->homepageFeed());
        $this->assertSame(1, $this->audits(), 'copied once');
    }

    /** A 1.2.2 site: the migration not run, no homepage feed rows, New videos as given. */
    private function site122(string $newVideosCode, string $newVideosShape): void
    {
        $this->forgetMigration();
        $this->db()->table('settings')->whereIn('key', [self::CODE, self::SHAPE])->delete();
        $this->db()->table('admin_audit_logs')->where('action', self::AUDIT)->delete();
        $this->db()->table('settings')->where('key', 'ad_code_grid_new_videos')->update(['value' => $newVideosCode]);
        $this->db()->table('settings')->where('key', 'ad_grid_layout_new_videos')->update(['value' => $newVideosShape]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function homepageFeed(): array
    {
        $rows = $this->db()->table('settings')->whereIn('key', [self::CODE, self::SHAPE])->pluck('value', 'key');
        $this->assertSame(1, $this->db()->table('settings')->where('key', self::CODE)->count(), 'one code row');

        return [$rows[self::CODE] ?? null, $rows[self::SHAPE] ?? null];
    }

    private function audits(): int
    {
        return $this->db()->table('admin_audit_logs')->where('action', self::AUDIT)->count();
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
        $this->database = 'avdbcms_homefeed_'.bin2hex(random_bytes(4)).'_test';
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
        $this->db()->table('migrations')->where('migration', self::MIGRATION)->delete();
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }
}
