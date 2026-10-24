<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Release hygiene (1.2.2): nothing AVDBCMS ships can create an account with a known password.
 *
 * Up to 1.2.1 the release shipped three development seeders: UserSeeder (an admin "admin" with the password "admin"
 * and factory members with the password "password"), ModelSeeder (20 factory members, password "password") and
 * FleetDataEnricherSeeder (20 fake members meant to have the password "password123", fake comments and playlists,
 * new view counts for every video). 1.2.2 ships only the seeders the installer runs, and migration
 * 2026_10_24_120100 deletes the copies an updated site still has (the one-click updater only deletes old files in
 * code folders), as long as a file is exactly as a release shipped it.
 */
class DevelopmentSeedersTest extends TestCase
{
    private const MIGRATION = '2026_10_24_120100_remove_shipped_development_seeders';

    /** The development seeders as releases shipped them (tests/Fixtures/shipped-seeders). */
    private const FIXTURES = [
        'FleetDataEnricherSeeder-community.php.txt' => 'FleetDataEnricherSeeder.php',
        'FleetDataEnricherSeeder-1.2.1.php.txt' => 'FleetDataEnricherSeeder.php',
        'UserSeeder-1.2.1.php.txt' => 'UserSeeder.php',
        'ModelSeeder-1.2.1.php.txt' => 'ModelSeeder.php',
    ];

    private ?string $sandbox = null;

    protected function tearDown(): void
    {
        if ($this->sandbox !== null) {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    public function test_only_the_seeders_the_installer_runs_ship(): void
    {
        $files = array_map('basename', glob(database_path('seeders/*')) ?: []);
        sort($files);

        $this->assertSame(['CategorySeeder.php', 'DatabaseSeeder.php', 'PlanSeeder.php'], $files);

        // "Run migrations and seeders" in the installer runs DatabaseSeeder: categories and plans only.
        preg_match_all('/([A-Za-z]+Seeder)::class/', (string) file_get_contents(database_path('seeders/DatabaseSeeder.php')), $called);
        $this->assertSame(['CategorySeeder', 'PlanSeeder'], $called[1]);
    }

    public function test_no_seeder_and_no_shipped_command_creates_an_account_with_a_known_password(): void
    {
        foreach (glob(database_path('seeders/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression('/(Hash::make|bcrypt|password_hash)\(\s*[\'"]/', $source, basename($path).' hashes a fixed password');
            $this->assertDoesNotMatchRegularExpression('/[\'"]password[\'"]\s*=>\s*[\'"]/', $source, basename($path).' sets a fixed password');
            $this->assertStringNotContainsString('User::', $source, basename($path).' creates accounts');
            $this->assertStringNotContainsString('::factory(', $source, basename($path).' uses a factory (UserFactory sets the password "password")');
        }

        // The only shipped code that uses a model factory is "php artisan users:create", and it gives every account a
        // random password (the factory's own default, "password", is meant for the test suite only).
        $users = [];
        foreach (File::allFiles(app_path()) as $file) {
            if (str_contains($file->getContents(), '::factory(')) {
                $users[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
        $this->assertSame(['Console/Commands/CreateUser.php'], $users);
        $this->assertMatchesRegularExpression("/'password'\s*=>\s*Str::random\(/", (string) file_get_contents(app_path('Console/Commands/CreateUser.php')));
    }

    public function test_the_migration_knows_every_shipped_version(): void
    {
        $shipped = $this->migration()::SHIPPED;

        foreach (self::FIXTURES as $fixture => $name) {
            $content = $this->fixture($fixture);

            $this->assertContains(hash('sha256', str_replace("\r\n", "\n", $content)), $shipped[$name] ?? [], $fixture);
        }

        $this->assertSame(['FleetDataEnricherSeeder.php', 'UserSeeder.php', 'ModelSeeder.php'], array_keys($shipped));
    }

    public function test_the_release_zips_ship_no_other_versions(): void
    {
        // Optional cross-check with real release zips: AVD_RELEASE_ZIPS="path/a.zip;path/b.zip".
        $zips = array_filter(explode(';', (string) getenv('AVD_RELEASE_ZIPS')), 'is_file');
        if ($zips === [] || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('Set AVD_RELEASE_ZIPS to release zips to compare with.');
        }

        $shipped = $this->migration()::SHIPPED;
        $checked = 0;

        foreach ($zips as $path) {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true, $path);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $name = basename($entry);

                if (str_starts_with($entry, 'database/seeders/') && isset($shipped[$name])) {
                    $this->assertContains(hash('sha256', str_replace("\r\n", "\n", (string) $zip->getFromIndex($i))), $shipped[$name], basename($path).': '.$entry);
                    $checked++;
                }
            }

            $zip->close();
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_an_updated_site_loses_the_shipped_copies_and_keeps_everything_else(): void
    {
        $folder = $this->sandboxSeeders();
        $owner = $this->fixture('FleetDataEnricherSeeder-1.2.1.php.txt')."\n// my own change\n";

        foreach ([
            // As 1.2.1 shipped them (UserSeeder and ModelSeeder with CRLF line endings) ...
            'FleetDataEnricherSeeder.php' => $this->fixture('FleetDataEnricherSeeder-1.2.1.php.txt'),
            'UserSeeder.php' => $this->fixture('UserSeeder-1.2.1.php.txt'),
            'ModelSeeder.php' => str_replace("\r\n", "\n", $this->fixture('ModelSeeder-1.2.1.php.txt')),
            // ... and the seeders a site keeps.
            'DatabaseSeeder.php' => (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php')),
            'CategorySeeder.php' => (string) file_get_contents(base_path('database/seeders/CategorySeeder.php')),
            'PlanSeeder.php' => (string) file_get_contents(base_path('database/seeders/PlanSeeder.php')),
            'MyOwnSeeder.php' => "<?php\n// an owner's seeder\n",
        ] as $name => $content) {
            file_put_contents($folder.'/'.$name, $content);
        }

        $this->migration()->up();

        $this->assertSame(['CategorySeeder.php', 'DatabaseSeeder.php', 'MyOwnSeeder.php', 'PlanSeeder.php'], $this->listing($folder));

        // A copy the owner changed is theirs: it stays.
        file_put_contents($folder.'/FleetDataEnricherSeeder.php', $owner);
        $this->migration()->up();
        $this->assertSame($owner, file_get_contents($folder.'/FleetDataEnricherSeeder.php'));

        // Safe to run again and to roll back.
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame(['CategorySeeder.php', 'DatabaseSeeder.php', 'FleetDataEnricherSeeder.php', 'MyOwnSeeder.php', 'PlanSeeder.php'], $this->listing($folder));
    }

    public function test_the_older_community_package_version_and_windows_line_endings_are_removed_too(): void
    {
        $folder = $this->sandboxSeeders();
        file_put_contents($folder.'/FleetDataEnricherSeeder.php', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $this->fixture('FleetDataEnricherSeeder-community.php.txt'))));
        file_put_contents($folder.'/UserSeeder.php', str_replace("\r\n", "\n", $this->fixture('UserSeeder-1.2.1.php.txt')));
        file_put_contents($folder.'/ModelSeeder.php', $this->fixture('ModelSeeder-1.2.1.php.txt'));

        $this->migration()->up();

        $this->assertSame([], $this->listing($folder));
    }

    public function test_a_site_without_a_seeders_folder_is_skipped(): void
    {
        $this->sandboxSeeders();
        File::deleteDirectory($this->sandbox.'/seeders');

        $this->migration()->up();

        $this->assertDirectoryDoesNotExist($this->sandbox.'/seeders');
    }

    /** Points database_path() at an empty temporary folder with a seeders/ folder, as on a site. */
    private function sandboxSeeders(): string
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avdbcms-dev-seeders-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/seeders');
        $this->app->useDatabasePath($this->sandbox);

        return $this->sandbox.'/seeders';
    }

    /** @return list<string> */
    private function listing(string $folder): array
    {
        $files = array_map('basename', glob($folder.'/*') ?: []);
        sort($files);

        return $files;
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/shipped-seeders/'.$name));
        $this->assertIsString($content, $name);

        return $content;
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/'.self::MIGRATION.'.php');
    }
}
