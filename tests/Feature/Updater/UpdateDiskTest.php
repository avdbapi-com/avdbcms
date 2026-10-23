<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * Disk space and failed writes (F8 6, 18 "Disk"): before the first byte is downloaded the update needs room for the
 * zip, the unpacked files and the database dump (+ 20 %), and asks again once the zip says how big its files are;
 * every write is checked, so a full disk or a hidden account quota stops the update in the download or the unpack,
 * where nothing live has changed, and the next step goes on once there is room again.
 */
class UpdateDiskTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    /** A database whose dump no disk has room for (1 PB). */
    private const HUGE = 1_000_000_000_000_000;

    private const NO_SPACE = '/^Not enough disk space: the update needs about [\d,]+ MB free\.$/';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_too_little_free_space_is_refused_before_anything_is_downloaded(): void
    {
        $this->database->databaseSize = self::HUGE;

        $answer = $this->updater()->start($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertMatchesRegularExpression(self::NO_SPACE, (string) $answer['message']);
        preg_match('/about ([\d,]+) MB/', (string) $answer['message'], $needed);
        $this->assertGreaterThan(self::HUGE * 0.35 / 1048576, (float) str_replace(',', '', $needed[1]), 'the dump is counted in');
        $this->assertNull($this->storedPlan());
        $this->assertDirectoryDoesNotExist($this->jobFolder());
        $this->assertSame([], $this->github->requestsTo($this->github->assetUrl('')), 'no asset was asked for');
        $this->assertSame([], $this->auditRows());
        $this->assertSiteUnchanged();

        // The same answer through the page (the password was right; the refusal is a message, not an error page).
        $this->app->instance(Updater::class, $this->updater());
        $this->actingAs($this->admin)
            ->postJson(route('admin.updates.start'), ['password' => 'password'])
            ->assertOk()
            ->assertJson(['ok' => false, 'next' => null])
            ->assertJsonPath('message', $answer['message']);
        $this->assertNull($this->storedPlan());
    }

    public function test_skipping_the_database_backup_leaves_the_dump_out_of_the_estimate(): void
    {
        $this->database->databaseSize = self::HUGE;
        File::ensureDirectoryExists($this->box->root.'/storage/app/backups');
        file_put_contents($this->box->root.'/storage/app/backups/backup_2026-10-23_090000.sql', str_repeat("-- a recent backup\n", 20));

        $answer = $this->updater()->start($this->admin, skipBackup: true);

        $this->assertTrue($answer['ok'], (string) $answer['message']);
        $this->assertSame('downloading', $answer['status']);
        $this->assertTrue($this->storedPlan()['skip_backup']);
    }

    public function test_free_space_is_checked_again_once_the_zip_says_how_big_its_files_are(): void
    {
        $this->updater()->start($this->admin);
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
        $this->database->databaseSize = self::HUGE;

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame('verifying', $answer['status']);
        $this->assertMatchesRegularExpression(self::NO_SPACE, (string) $answer['message']);
        $this->assertSame($answer['message'], $this->storedPlan()['last_error']);
        $this->assertFileExists($this->zipFile(), 'the checked zip stays for the next try');
        $this->assertDirectoryDoesNotExist($this->jobFolder().'/staging');
        $this->assertSiteUnchanged();

        // Room again: the update goes on from where it stopped.
        $this->database->databaseSize = 4096;
        $this->assertSame('unpacking', $this->updater()->step($this->admin)['status']);
    }

    public function test_a_write_that_fails_while_unpacking_stops_and_the_site_stays_as_it_was(): void
    {
        $this->updater()->start($this->admin);
        $unpacking = $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'unpacking');
        $this->assertTrue($unpacking['ok']);
        // The disk refuses: where the staging folder must go, nothing can be made (a file stands there).
        file_put_contents($this->jobFolder().'/staging', 'not a folder');

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame('unpacking', $answer['status']);
        $this->assertMatchesRegularExpression('/^Could not write \S+ while unpacking\. The disk may be full\. Nothing on your site was changed\.$/', (string) $answer['message']);
        $this->assertSame(0, $this->storedPlan()['unpack']['next'], 'no file counts as unpacked');
        $this->assertFileExists($this->zipFile());
        $this->assertSiteUnchanged('by a failed unpack');
        $this->assertSame(['update.started'], array_column($this->auditRows(), 'action'), 'a step that can be tried again is no failure of the update');

        // Room again: the next step unpacks every file.
        unlink($this->jobFolder().'/staging');
        $answer = $this->updater()->step($this->admin);

        $this->assertTrue($answer['ok'], (string) $answer['message']);
        $this->assertSame('backing-up', $answer['status']);
        $this->assertSame(self::releaseFiles()['config/app.php'], file_get_contents($this->jobFolder().'/staging/config/app.php'));
        $this->assertSiteUnchanged();
    }

    public function test_a_write_that_fails_while_downloading_stops_and_the_site_stays_as_it_was(): void
    {
        $this->updater()->start($this->admin);
        // Where the download goes, nothing can be written (a folder stands there).
        File::ensureDirectoryExists($this->zipFile().'.part');

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame(['downloading', 'Could not write the download. The disk may be full. Nothing on your site was changed.'], [$answer['status'], $answer['message']]);
        $this->assertSame(0, $this->storedPlan()['download']['done']);
        $this->assertStringContainsString('[cannot open the .part file]', implode("\n", $this->updater()->logLines()));
        $this->assertSiteUnchanged('by a failed download');

        File::deleteDirectory($this->zipFile().'.part');
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);
        $this->assertSame($this->github->bytes, file_get_contents($this->zipFile()));
    }
}
