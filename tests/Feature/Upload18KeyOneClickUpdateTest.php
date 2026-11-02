<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppVersion;
use App\Support\PendingMigrations;
use App\Support\Updater\Manifest;
use App\Support\Updater\PlanStore;
use App\Support\Upload18\BundledKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Updater\Support\FakeDatabaseBackup;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\Updater120;
use Tests\Feature\Updater\Support\Updater121;
use Tests\Feature\Updater\Support\Updater122;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\Feature\Updater\Support\ZipBuilder;
use Tests\TestCase;

/**
 * The retired Upload18 key through a real one-click update: the site runs 1.2.0, 1.2.1 or 1.2.2 and still has
 * the key those versions shipped. That version's own updater (the files it shipped, byte for byte: Updater120/121/122)
 * runs every step up to the swap (download from GitHub, checksum, unpack, backup, swap through its maintenance hook);
 * it never writes database/install/, so the site keeps its old example.sql. This version's Finish runs the database
 * step, and migration 2026_10_25_140100_replace_retired_upload18_key must find the shipped key in the update zip that the
 * old steps left in the work folder (plan.json as the old code wrote it), with ZipArchive and with PharData.
 *
 * Made-up keys only: no real key is in this test.
 */
class Upload18KeyOneClickUpdateTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const MIGRATION = '2026_10_25_140100_replace_retired_upload18_key';

    private const RETIRED = 'retiredTestKey0000000000000AB';

    private const SHIPPED = 'u18r_TESTpublicDownloadKey0123456789abcdefgh';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeKeySite();

        BundledKey::$retiredHashes = [hash('sha256', self::RETIRED)];
        BundledKey::$exampleSqlPath = $this->box->root.'/database/install/example.sql';
        BundledKey::$updatePaths = $this->box->paths();
    }

    protected function tearDown(): void
    {
        BundledKey::$retiredHashes = null;
        BundledKey::$exampleSqlPath = null;
        BundledKey::$updatePaths = null;
        BundledKey::$withoutZipArchive = false;
        $this->removeSite();

        parent::tearDown();
    }

    public function test_an_update_from_1_2_2_gives_a_site_with_the_retired_key_the_shipped_one(): void
    {
        $this->updateWith(Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT));
    }

    public function test_an_update_from_1_2_1_gives_a_site_with_the_retired_key_the_shipped_one(): void
    {
        $this->updateWith(Updater121::updater($this->box->paths(), $this->database, AppVersion::CURRENT));
    }

    public function test_an_update_from_1_2_0_gives_a_site_with_the_retired_key_the_shipped_one(): void
    {
        $this->updateWith(Updater120::updater($this->box->paths(), $this->database, AppVersion::CURRENT));
    }

    public function test_a_host_without_zip_archive_reads_the_key_with_phar_data(): void
    {
        BundledKey::$withoutZipArchive = true;

        $this->updateWith(Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT));
    }

    public function test_an_owners_own_key_stays_through_the_update(): void
    {
        $this->storedKey('u18r_OwnersOwnRestrictedKey00000000000000000');

        $this->updateWith(Updater122::updater($this->box->paths(), $this->database, AppVersion::CURRENT), replaced: false);

        $this->assertSame('u18r_OwnersOwnRestrictedKey00000000000000000', $this->value());
    }

    private function updateWith(object $old, bool $replaced = true): void
    {
        if ($replaced) {
            $this->storedKey(self::RETIRED);
        }

        $ready = $this->oldToSwap($old);
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);

        // What the old steps left: their plan, the update zip in the work folder, and the site's old example.sql (the
        // updater never writes database/install/).
        $plan = PlanStore::read($this->box->paths()->plan());
        $this->assertSame('swapped', $plan['status']);
        $this->assertFileExists($this->zipFile());
        $this->assertSame($this->sql(self::RETIRED), $this->box->read('database/install/example.sql'));

        // The database step of this version has the key migration to run (the site comes from before it).
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        PendingMigrations::forget();
        $this->assertContains(self::MIGRATION, PendingMigrations::pending());

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame($this->next, Manifest::installed($this->box->paths()->manifest())->version);
        $this->assertNotContains(self::MIGRATION, PendingMigrations::pending());

        $actions = array_column($this->auditRows(), 'action');

        if ($replaced) {
            $this->assertSame(self::SHIPPED, $this->value());
            $this->assertSame(self::SHIPPED, Setting::getGroup('uploads')['upload18_api_key']);
            $this->assertContains(BundledKey::AUDIT_ACTION, $actions, 'Tools > Logs > Admin actions lists update.upload18_key');
            $details = (string) DB::table('admin_audit_logs')->where('action', BundledKey::AUDIT_ACTION)->value('details');
            $this->assertStringNotContainsString(self::SHIPPED, $details);
            $this->assertStringNotContainsString(self::RETIRED, $details);
        } else {
            $this->assertNotContains(BundledKey::AUDIT_ACTION, $actions);
        }

        $this->assertContains('update.finished', $actions);
    }

    /**
     * makeSite() of UpdaterScenario, with the site's own example.sql (the old one, the retired key) and an update zip
     * whose database/install/example.sql carries the shipped key, as the release build writes it.
     */
    private function makeKeySite(): void
    {
        config(['admin.operational_tools_enabled' => true, 'app.url' => 'http://localhost']);
        $this->box = new UpdaterSandbox();
        $this->next = UpdaterSandbox::nextVersion();

        File::ensureDirectoryExists($this->box->path('views'));
        config(['view.compiled' => $this->box->path('views')]);

        $this->box->install(AppVersion::CURRENT, self::shippedFiles() + ['database/install/example.sql' => $this->sql(self::RETIRED)], self::siteData());
        $this->before = $this->box->snapshot();

        $files = self::releaseFiles();
        $removed = ['app/Support/Old.php'];
        ksort($files, SORT_STRING);

        // UpdaterSandbox::releaseZip(), with the package's example.sql (the updater skips database/install/).
        $zip = new ZipBuilder();
        $zip->dir('app')->dir('storage/app/public');

        foreach ($files as $path => $content) {
            $zip->file((string) $path, $content);
        }

        $zip->file(Manifest::FILE, UpdaterSandbox::manifest($this->next, $files, $removed, '8.3.0', AppVersion::CURRENT))
            ->file('storage/app/.gitignore', "*\n!public/\n!.gitignore\n")
            ->file('storage/logs/.gitkeep', '')
            ->file('database/install/example.sql', $this->sql(self::SHIPPED));

        $file = $zip->save($this->box->path('release/'.AppVersion::updateAsset($this->next)));

        $this->github = (new ReleaseServer($this->next, $file))->install();
        $this->database = new FakeDatabaseBackup($this->box->root.'/storage/app/backups');
        $this->admin = User::factory()->admin()->create(['username' => 'owner']);
    }

    /** The old version's steps (its own Updater) until the swap is ready. */
    private function oldToSwap(object $old): array
    {
        $start = $old->start($this->admin);
        $this->assertTrue($start['ok'], (string) $start['message']);

        for ($i = 0; $i < 40; $i++) {
            $answer = $old->step($this->admin);
            $this->assertTrue($answer['ok'], (string) $answer['message']);

            if ($answer['next'] !== 'step') {
                $this->assertSame('swap', $answer['next']);

                return ['token' => $answer['token'], 'cookie' => $start['cookie'] ?? null];
            }
        }

        $this->fail('The old updater did not get to the swap in 40 steps.');
    }

    private function storedKey(string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'upload18_api_key'], ['value' => $value, 'group' => 'uploads', 'updated_at' => now()->subDay()]);
        Setting::clearCache();
    }

    private function value(): ?string
    {
        return DB::table('settings')->where('key', 'upload18_api_key')->value('value');
    }

    /** The settings rows of a release's example.sql (the real file's format: one INSERT, a row per line). */
    private function sql(string $key): string
    {
        return "LOCK TABLES `settings` WRITE;\nINSERT INTO `settings` VALUES\n"
            ."(30,'mail_from_name','AVDBCMS','email','2026-04-25 07:17:05','2026-05-05 03:55:31'),\n"
            ."(31,'upload18_api_key','".str_replace("'", "''", $key)."','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23'),\n"
            ."(32,'upload18_api_url','https://upload18.org/api','uploads','2026-04-25 10:06:23','2026-04-25 10:06:23');\n";
    }
}
