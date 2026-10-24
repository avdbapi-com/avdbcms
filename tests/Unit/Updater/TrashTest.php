<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Trash;
use Illuminate\Support\Facades\File;
use LogicException;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The updater's trash (1.2.1, B1 of the 1.2.0 tests): a folder leaves its place in one rename, everything it held
 * goes with it, and purge() deletes the trash in slices of at most the seconds it is given, one purge at a time. Only
 * the updater's own work files are taken, and a link is removed, never followed.
 */
class TrashTest extends TestCase
{
    private UpdaterSandbox $box;

    private Trash $trash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->box = new UpdaterSandbox();
        $this->trash = new Trash($this->box->paths());
    }

    protected function tearDown(): void
    {
        $this->box->delete();

        parent::tearDown();
    }

    public function test_a_folder_leaves_in_one_rename_with_everything_it_held(): void
    {
        $job = $this->box->paths()->job('1.0.1');
        $this->files($job.'/staging', 300);
        File::put($job.'/avdbcms-update-1.0.1.zip', 'zip bytes');
        $inode = fileinode($job.'/staging/d0/f0.txt');

        $this->assertFalse($this->trash->pending());
        $this->assertTrue($this->trash->put($job));

        $this->assertDirectoryDoesNotExist($job);
        $entries = glob($this->box->paths()->trash('1.0.1-*')) ?: [];
        $this->assertCount(1, $entries);
        $this->assertMatchesRegularExpression('/^1\.0\.1-\d{14}-[0-9a-f]{6}$/', basename($entries[0]));
        $this->assertSame(300, count(glob($entries[0].'/staging/d*/*.txt') ?: []), 'nothing was deleted');
        $this->assertSame($inode, fileinode($entries[0].'/staging/d0/f0.txt'), 'moved, not copied');
        $this->assertTrue($this->trash->pending());

        // A path that is not there is out of the way already.
        $this->assertTrue($this->trash->put($job));
    }

    public function test_the_children_of_a_folder_go_together_and_the_kept_ones_stay(): void
    {
        $job = $this->box->paths()->job('1.0.1');
        $this->files($job.'/staging', 20);
        File::put($job.'/update.log', 'log');
        File::put($job.'/avdbcms-update-1.0.1.zip', 'zip');
        File::put($job.'/entries.json', '{}');

        $this->assertTrue($this->trash->putChildren($job, ['update.log']));

        $this->assertSame(['update.log'], array_values(array_diff(scandir($job), ['.', '..'])));
        $entries = glob($this->box->paths()->trash('1.0.1-*')) ?: [];
        $this->assertCount(1, $entries, 'one trash entry for all of them');
        $this->assertSame(['avdbcms-update-1.0.1.zip', 'entries.json', 'staging'], array_values(array_diff(scandir($entries[0]), ['.', '..'])));
    }

    public function test_purge_deletes_in_slices_of_at_most_its_seconds(): void
    {
        $this->files($this->box->paths()->job('1.0.1').'/staging', 1200);
        $this->trash->put($this->box->paths()->job('1.0.1'));

        $nothing = $this->trash->purge(0.0);
        $this->assertSame(['pending' => true, 'deleted' => 0, 'busy' => false], $nothing, 'no time, nothing deleted');

        $slices = [];

        do {
            $started = microtime(true);
            $result = $this->trash->purge(0.01);
            $slices[] = [$result['deleted'], microtime(true) - $started];
        } while ($result['pending'] && count($slices) < 5000);

        $this->assertFalse($result['pending']);
        $this->assertFalse($this->trash->pending());
        $this->assertGreaterThan(1, count($slices));
        // 1,200 files, 12 folders and the entry with its staging folder.
        $this->assertSame(1200 + 12 + 2, array_sum(array_column($slices, 0)));

        foreach ($slices as [$deleted, $seconds]) {
            $this->assertLessThan(1.0, $seconds, 'a slice keeps to its time (one file over at most)');
        }

        $this->assertDirectoryExists($this->box->paths()->trash(), 'the trash folder itself stays');
    }

    public function test_one_purge_at_a_time(): void
    {
        $this->files($this->box->paths()->job('1.0.1'), 5);
        $this->trash->put($this->box->paths()->job('1.0.1'));
        $lock = fopen($this->box->paths()->trashLock(), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->assertSame(['pending' => true, 'deleted' => 0, 'busy' => true], $this->trash->purge(5.0));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertFalse($this->trash->purge(5.0)['pending']);
    }

    public function test_only_the_updaters_own_work_files_are_taken(): void
    {
        $paths = $this->box->paths();
        File::put($this->box->root.'/keep.txt', 'site file');

        foreach ([$this->box->root.'/keep.txt', $this->box->root, $paths->work(), $paths->trash(), $paths->trash('x'), $paths->work('../app'), $paths->work('a/../../b')] as $path) {
            try {
                $this->trash->put($path);
                $this->fail('took '.$path);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFileExists($this->box->root.'/keep.txt');
    }

    public function test_a_link_in_the_trash_is_removed_and_never_followed(): void
    {
        $outside = $this->box->path('outside');
        File::ensureDirectoryExists($outside);
        File::put($outside.'/precious.txt', 'not the updater\'s');
        $entry = $this->box->paths()->trash('1.0.1-20261024000000-abcdef');
        File::ensureDirectoryExists($entry);

        if (! @symlink($outside, $entry.'/link')) {
            // Windows without the right to make symbolic links: a junction does the same harm if followed.
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->markTestSkipped('This system cannot make a link.');
            }

            exec('mklink /J '.escapeshellarg(str_replace('/', '\\', $entry.'/link')).' '.escapeshellarg(str_replace('/', '\\', $outside)).' 2>NUL', $out, $code);

            if ($code !== 0 || ! file_exists($entry.'/link')) {
                $this->markTestSkipped('This system cannot make a link or a junction.');
            }
        }

        $this->assertFileExists($entry.'/link/precious.txt');

        $this->assertFalse($this->trash->purge(5.0)['pending']);

        $this->assertFileExists($outside.'/precious.txt', 'what the link pointed to is untouched');
        $this->assertFalse(file_exists($entry.'/link') || is_link($entry.'/link'));
    }

    /** $count small files in folders of 100 under $folder. */
    private function files(string $folder, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $sub = $folder.'/d'.intdiv($i, 100);

            if ($i % 100 === 0) {
                File::ensureDirectoryExists($sub);
            }

            file_put_contents($sub.'/f'.$i.'.txt', (string) $i);
        }
    }
}
