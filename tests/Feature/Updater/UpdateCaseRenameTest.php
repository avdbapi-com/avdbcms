<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\PlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * H10 (1.2.2): a release renames app/Http/Controllers/SEOController.php to SeoController.php (case only). A real update
 * and its Undo, on the folder as it is on this system (Windows and macOS ignore case) and on a folder that tells case
 * apart (Linux does; on Windows the test makes the folder case-sensitive with fsutil, which needs an administrator):
 *
 *  - where case does not count, the file is replaced in place: one file, under the new name;
 *  - where it counts, the old name goes too (into the update backup), and Undo brings it back and takes the new one
 *    away: never two controllers that differ in case only;
 *  - an old file the owner changed is never deleted: it stays and Tools > Updates lists it next to the new name.
 */
class UpdateCaseRenameTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario {
        shippedFiles as private scenarioShipped;
        releaseFiles as private scenarioRelease;
    }

    private const FOLDER = 'app/Http/Controllers';

    private const OLD = 'app/Http/Controllers/SEOController.php';

    private const NEW = 'app/Http/Controllers/SeoController.php';

    protected static function shippedFiles(): array
    {
        return self::scenarioShipped() + [self::OLD => "<?php // SEOController 1\n", 'app/Http/Controllers/HomeController.php' => "<?php // Home 1\n"];
    }

    protected static function releaseFiles(): array
    {
        return self::scenarioRelease() + [self::NEW => "<?php // SeoController 2\n", 'app/Http/Controllers/HomeController.php' => "<?php // Home 1\n"];
    }

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

    public function test_where_case_does_not_count_the_file_is_replaced_in_place(): void
    {
        if (! is_file($this->box->root.'/'.strtolower(self::OLD))) {
            $this->markTestSkipped('This file system tells case apart (the other test covers it).');
        }

        $this->updateToNext();

        $this->assertSame(['HomeController.php', 'SeoController.php'], $this->names(), 'one file, under its new name');
        $this->assertSame(self::releaseFiles()[self::NEW], $this->box->read(self::NEW));
        $this->assertNotContains(self::OLD, array_column($this->backupEntries(), 'path'), 'nothing deleted under the old name');
    }

    public function test_where_case_counts_the_old_name_goes_and_undo_brings_it_back(): void
    {
        $this->caseSensitiveFolder();

        $this->updateToNext();

        $this->assertSame(['HomeController.php', 'SeoController.php'], $this->names(), 'the old name did not stay behind');
        $this->assertSame(self::releaseFiles()[self::NEW], $this->box->read(self::NEW));
        $removed = array_values(array_filter($this->backupEntries(), fn (array $entry): bool => $entry['path'] === self::OLD))[0] ?? null;
        $this->assertSame(['removed', hash('sha256', self::shippedFiles()[self::OLD])], [$removed['action'] ?? null, $removed['old'] ?? null]);
        $this->assertSame(self::shippedFiles()[self::OLD], (string) file_get_contents($this->backupFolder().'/files/'.self::OLD), 'kept in the update backup');

        // Undo (the new code's steps and hook, then the old code's Finish): the old name back, the new one gone.
        $undo = $this->undoToSwap($this->updater($this->next));
        $this->assertSame('swapped', $this->swapThroughHook($undo['token'])['step']);
        $this->assertSame('undone', $this->finishOn($this->updater())['status']);

        $this->assertSame(['HomeController.php', 'SEOController.php'], $this->names());
        $this->assertSame(self::shippedFiles()[self::OLD], $this->box->read(self::OLD));
    }

    public function test_where_case_counts_an_old_file_the_owner_changed_stays_and_is_listed(): void
    {
        $this->caseSensitiveFolder();
        $this->box->write([self::OLD => "<?php // SEOController 1, changed by the owner\n"]);

        $this->updateToNext();

        $this->assertSame(['HomeController.php', 'SEOController.php', 'SeoController.php'], $this->names());
        $this->assertSame("<?php // SEOController 1, changed by the owner\n", $this->box->read(self::OLD), 'never deleted');
        $this->assertContains(['path' => self::OLD, 'copy' => self::NEW], $this->storedPlan()['report']['kept']);
    }

    /* ----------------------------------------------------------------- helpers */

    /** The names in the controllers folder exactly as they are on the disk. */
    private function names(): array
    {
        $names = array_values(array_diff(scandir($this->box->root.'/'.self::FOLDER) ?: [], ['.', '..']));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return list<array<string, mixed>> the entries of the update's file backup */
    private function backupEntries(): array
    {
        return (array) (PlanStore::read($this->backupFolder().'/backup.json')['entries'] ?? []);
    }

    private function backupFolder(): string
    {
        return $this->box->paths()->backups((string) $this->storedPlan()['backup_id']);
    }

    /**
     * Make the controllers folder tell case apart: Linux does already; on Windows fsutil sets the folder's case-sensitive
     * flag, which needs an empty folder (its files go out and back in) and an administrator.
     */
    private function caseSensitiveFolder(): void
    {
        $folder = $this->box->root.'/'.self::FOLDER;

        if (PHP_OS_FAMILY === 'Windows') {
            $aside = $folder.'-aside';
            rename($folder, $aside);
            mkdir($folder);
            $flag = new Process(['fsutil.exe', 'file', 'setCaseSensitiveInfo', str_replace('/', '\\', $folder), 'enable']);
            $flag->run();

            foreach (array_diff(scandir($aside) ?: [], ['.', '..']) as $name) {
                rename($aside.'/'.$name, $folder.'/'.$name);
            }

            rmdir($aside);

            if (! $flag->isSuccessful()) {
                $this->markTestSkipped('fsutil could not make the folder case-sensitive: '.trim($flag->getOutput().$flag->getErrorOutput()));
            }
        }

        clearstatcache();

        if (is_file($folder.'/seocontroller.php')) {
            $this->markTestSkipped('This file system ignores case.');
        }
    }
}
