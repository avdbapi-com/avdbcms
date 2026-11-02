<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\InstallData;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Updater\Support\ZipBuilder;
use Tests\TestCase;

/**
 * Round 1 of 1.2.3 (R1-100): the starter data of new installs (database/install/example.sql) is never part of an
 * update's moves. Finish gives a site that still has exactly a copy an earlier release shipped (line endings aside) the
 * copy of the update's zip, read with ZipArchive or, on a host without it, PharData; any other copy stays.
 */
class InstallDataTest extends TestCase
{
    private const OLD = "-- an earlier release\nCREATE TABLE `settings` (`id` int);\nINSERT INTO `settings` VALUES (1,'upload18_api_key','retired');\n";

    private const NEW = "-- this release\nCREATE TABLE `settings` (`id` int);\nINSERT INTO `settings` VALUES (1,'upload18_api_key','public-download-key');\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', storage_path('framework/testing/install-data-'.Str::lower(Str::random(8))));
        File::ensureDirectoryExists($this->root.'/site/database/install');
        InstallData::$shippedHashes = [InstallData::hash(self::OLD)];
    }

    protected function tearDown(): void
    {
        InstallData::$shippedHashes = null;
        InstallData::$withoutZipArchive = false;
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_the_known_copies_are_those_the_releases_shipped(): void
    {
        InstallData::$shippedHashes = null;

        $this->assertCount(8, InstallData::SHIPPED);
        // 1.2.2 (a3c3b24a...) and 1.2.0 = 1.2.1 (3e938eec...): the copies with the retired Upload18 key.
        $this->assertContains('a3c3b24acda63fe3ca75ee1d622a18b7e4fa77234887fbc781982a6383ddf829', InstallData::SHIPPED);
        $this->assertContains('3e938eec8a2dbb17dab7c8204c44171fc610fd4cb1d633f8a1612049232dc136', InstallData::SHIPPED);
        $this->assertFalse(InstallData::shipped(self::OLD));
    }

    public function test_a_shipped_copy_gets_the_one_of_the_zip_with_either_zip_engine(): void
    {
        foreach ([false, true] as $withoutZipArchive) {
            InstallData::$withoutZipArchive = $withoutZipArchive;
            file_put_contents($this->site(), str_replace("\n", "\r\n", self::OLD));

            $this->assertSame('replaced', InstallData::refresh($this->root.'/site', $this->zip(self::NEW)), $withoutZipArchive ? 'PharData' : 'ZipArchive');
            $this->assertSame(self::NEW, file_get_contents($this->site()));
            $this->assertSame([], glob($this->root.'/site/database/install/*.tmp') ?: [], 'no temporary file left');

            // Again: it is this release's copy now.
            $this->assertSame('current', InstallData::refresh($this->root.'/site', $this->zip(self::NEW)));
        }
    }

    public function test_any_other_copy_stays(): void
    {
        $mine = self::OLD."INSERT INTO `settings` VALUES (9,'mine','1');\n";
        file_put_contents($this->site(), $mine);

        $this->assertSame('kept', InstallData::refresh($this->root.'/site', $this->zip(self::NEW)));
        $this->assertSame($mine, file_get_contents($this->site()));

        // No file at all (a site may have deleted it): nothing is added.
        unlink($this->site());
        $this->assertSame('kept', InstallData::refresh($this->root.'/site', $this->zip(self::NEW)));
        $this->assertFileDoesNotExist($this->site());
    }

    public function test_a_zip_without_plausible_install_data_changes_nothing(): void
    {
        file_put_contents($this->site(), self::OLD);

        $this->assertSame('missing', InstallData::refresh($this->root.'/site', $this->zip("-- example\n")));
        $this->assertSame('missing', InstallData::refresh($this->root.'/site', $this->zip(null)));
        $this->assertSame('missing', InstallData::refresh($this->root.'/site', $this->root.'/no-such.zip'));
        $this->assertSame(self::OLD, file_get_contents($this->site()));
    }

    private function site(): string
    {
        return $this->root.'/site/'.InstallData::PATH;
    }

    private function zip(?string $sql): string
    {
        $zip = (new ZipBuilder())->file('app/A.php', '<?php // a');

        if ($sql !== null) {
            $zip->file(InstallData::PATH, $sql);
        }

        return $zip->save($this->root.'/zips/'.Str::random(6).'.zip');
    }
}
