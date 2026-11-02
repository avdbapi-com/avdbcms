<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Trash;
use Illuminate\Support\Facades\File;
use LogicException;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The updater's trash (1.2.1): a folder leaves its place in one rename, everything it held
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
        Trash::$slowDisk = null;
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

        // Slices of 10 ms until the trash is empty. How many that takes is the disk's matter, not the trash's: NTFS
        // needs several, ext4 deletes all of it inside one slice (R1B-020: the suite failed on Linux when it counted
        // the slices). What the trash promises is checked: the slices end soon after their time, and together they
        // delete everything exactly once; a deletion that took longer than ten slices (NTFS: about a second) was cut
        // into slices. How many files a slice may delete is checked on a slow disk, below.
        // A single deletion can block far longer than a slice (a virus scanner, NTFS on a loaded machine: 2.6 s seen in
        // a full suite, UP-T2), and the trash cannot interrupt it: the typical (median) slice must end far under a
        // second, and a slice that ran a second or more is allowed now and then - at most one in twenty, never more
        // than two. That the trash takes nothing after its time is checked exactly on a slow disk, below.
        $slices = [];

        do {
            $started = microtime(true);
            $result = $this->trash->purge(0.01);
            $slices[] = [$result['deleted'], microtime(true) - $started];
        } while ($result['pending'] && count($slices) < 5000);

        $this->assertFalse($result['pending']);
        $this->assertFalse($this->trash->pending());
        $counts = json_encode(array_column($slices, 0));
        // 1,200 files, 12 folders and the entry with its staging folder.
        $this->assertSame(1200 + 12 + 2, array_sum(array_column($slices, 0)), 'every file and folder deleted once: '.$counts);

        $times = array_column($slices, 1);
        sort($times);
        $median = $times[intdiv(count($times), 2)];
        $this->assertLessThan(0.25, $median, sprintf('the typical slice ends soon after its 10 ms (median %.3f s): %s', $median, $counts));
        $slow = count(array_filter($times, static fn (float $seconds): bool => $seconds >= 1.0));
        $this->assertLessThanOrEqual(min(2, max(1, intdiv(count($times), 20))), $slow,
            sprintf('%d of %d slices took a second or more (one deletion may block that long now and then, not more often): %s', $slow, count($times), $counts));

        $total = array_sum(array_column($slices, 1));

        if ($total > 10 * 0.01) {
            $this->assertGreaterThan(1, count($slices), sprintf('%.2f s of deleting in slices of 10 ms: %s', $total, $counts));
        }

        $this->assertDirectoryExists($this->box->paths()->trash(), 'the trash folder itself stays');
    }

    /**
     * R1B-020 (review): on a disk that takes 20 ms for every file and every folder (Trash::$slowDisk), a purge of 50 ms
     * stops once its time is up, on any disk: it takes no file or folder after its deadline, so it deletes at most
     * ceil(50 / 20) = 3, and the folder the last one emptied (deleted right after its last file). A purge that ignored
     * its time would delete all 23 in one go.
     */
    public function test_on_a_slow_disk_a_purge_takes_no_file_after_its_time(): void
    {
        $this->files($this->box->paths()->job('1.0.1').'/staging', 20);
        $this->assertTrue($this->trash->put($this->box->paths()->job('1.0.1')));
        Trash::$slowDisk = 0.02;
        $budget = 0.05;
        $most = (int) ceil($budget / Trash::$slowDisk) + 1;
        $slices = [];

        do {
            $started = microtime(true);
            $result = $this->trash->purge($budget);
            $slices[] = [$result['deleted'], microtime(true) - $started];
        } while ($result['pending'] && count($slices) < 200);

        Trash::$slowDisk = null;
        $counts = json_encode(array_column($slices, 0));
        $this->assertFalse($result['pending'], $counts);
        // 20 files, the folder d0, staging and the entry.
        $this->assertSame(20 + 3, array_sum(array_column($slices, 0)), 'every file and folder deleted once: '.$counts);
        $this->assertGreaterThanOrEqual((int) ceil(23 / $most), count($slices), 'in slices: '.$counts);

        foreach ($slices as [$deleted, $seconds]) {
            $this->assertLessThanOrEqual($most, $deleted, "no file or folder taken after the slice's 50 ms (the folder the last one emptied aside): ".$counts);
            $this->assertLessThan(1.0, $seconds, $counts);
        }
    }

    /** count(): what the trash holds as purge() counts it (the bar of an undo back to 1.2.0), or null when out of time. */
    public function test_count_says_how_many_files_and_folders_a_purge_deletes(): void
    {
        $this->assertSame(0, $this->trash->count(1.0), 'no trash yet');
        $job = $this->box->paths()->job('1.0.1');
        $this->files($job.'/staging', 250);
        File::put($job.'/avdbcms-update-1.0.1.zip', 'zip');
        $this->assertTrue($this->trash->put($job));
        File::put($this->box->paths()->trash('loose-file-20261027000000-abcdef'), 'x');

        $count = $this->trash->count(10.0);
        // The entry, staging, 3 folders d0-d2, 250 files, the zip; and the loose file.
        $this->assertSame(1 + 1 + 3 + 250 + 1 + 1, $count);
        $this->assertNull($this->trash->count(0.0), 'no time to count');

        $deleted = 0;

        do {
            $purge = $this->trash->purge(5.0);
            $deleted += $purge['deleted'];
        } while ($purge['pending']);

        $this->assertSame($count, $deleted);
        $this->assertSame(0, $this->trash->count(1.0));
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
