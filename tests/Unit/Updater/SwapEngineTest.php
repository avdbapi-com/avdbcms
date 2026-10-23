<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Manifest;
use App\Support\Updater\SwapEngine;
use App\Support\Updater\SwapPlanner;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\TestCase;

/**
 * The journaled swap (F8 10, 11, 13): forward moves each changed file into the backup and the new one into place,
 * rollback puts the tree back byte for byte, a swap cut off half-way goes on from its journal, running it twice changes
 * nothing, and a move that fails (a damaged new file, a folder in the way, a file changed meanwhile) leaves a tree the
 * way back can restore.
 */
class SwapEngineTest extends TestCase
{
    private const OLD = [
        'app/A.php' => 'A 1',
        'app/B.php' => 'B 1',
        'app/C.php' => 'C 1 (removed by the release)',
        'config/x.php' => 'X 1',
        'public/index.php' => 'index 1',
        Manifest::FILE => 'manifest 1',
    ];

    private const NEW = [
        'app/A.php' => 'A 2',
        'app/B.php' => 'B 1',
        'app/D.php' => 'D 2 (added)',
        'config/x.php' => 'X 2',
        'public/index.php' => 'index 2',
        'public/build/assets/app-2.js' => 'js 2',
        Manifest::FILE => 'manifest 2',
    ];

    /** Site files no update touches. */
    private const DATA = [
        '.env' => 'APP_KEY=base64:secret',
        'storage/app/installed.json' => '{}',
        'public/uploads/a.jpg' => 'jpg',
    ];

    private UpdaterSandbox $box;

    private string $work;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = new UpdaterSandbox();
        $this->work = $this->box->path('work');
        $this->backup = $this->box->path('backup');
        $this->box->write(self::OLD + self::DATA);
    }

    protected function tearDown(): void
    {
        $this->box->delete();
        parent::tearDown();
    }

    public function test_forward_moves_every_change_and_keeps_the_old_files_in_the_backup(): void
    {
        $job = $this->job();
        $before = $this->box->snapshot([]);

        $result = (new SwapEngine($job))->forward(INF);

        $this->assertSame(['state' => 'done', 'done' => 7, 'total' => 7], $result);
        $this->assertSame($this->expectedAfter(), $this->box->snapshot([]));
        $this->assertSame([
            'app/A.php' => hash('sha256', 'A 1'),
            'app/C.php' => hash('sha256', 'C 1 (removed by the release)'),
            'avdbcms-manifest.json' => hash('sha256', 'manifest 1'),
            'config/x.php' => hash('sha256', 'X 1'),
            'public/index.php' => hash('sha256', 'index 1'),
        ], $this->box->snapshot([], $this->backup.'/files'), 'only what changed is in the backup');
        $this->assertSame($before['app/B.php'], $this->box->snapshot([])['app/B.php'], 'an unchanged file never moves');
        $this->assertSame(array_fill(0, 7, 'end'), (new SwapEngine($job))->marks());
        $this->assertSame(['app/B.php' => hash('sha256', 'B 1')], $this->box->snapshot([], $this->work.'/staging'),
            'the new files moved (not copied); only the unchanged one stays in staging');
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $job = $this->job();
        (new SwapEngine($job))->forward(INF);
        $after = $this->box->snapshot([]);
        $backup = $this->box->snapshot([], $this->backup);

        $again = (new SwapEngine($job))->forward(INF);

        $this->assertSame(['state' => 'done', 'done' => 7, 'total' => 7], $again);
        $this->assertSame($after, $this->box->snapshot([]));
        $this->assertSame($backup, $this->box->snapshot([], $this->backup));
    }

    public function test_the_way_back_restores_the_tree_byte_for_byte(): void
    {
        $job = $this->job();
        $before = $this->box->snapshot([]);
        $engine = new SwapEngine($job);
        $engine->forward(INF);

        $back = $engine->rollback(INF);

        $this->assertSame(['state' => 'done', 'left' => 0, 'kept' => []], $back);
        $this->assertSame($before, $this->box->snapshot([]));
        $this->assertSame(7, $engine->progress()['undone']);
        $this->assertSame([], $this->box->snapshot([], $this->backup.'/files'));
        $this->assertSame(hash('sha256', 'A 2'), $this->box->snapshot([], $this->work.'/staging')['app/A.php'], 'the new files are back in staging for another try');

        // Twice is safe too.
        $this->assertSame(['state' => 'done', 'left' => 0, 'kept' => []], (new SwapEngine($job))->rollback(INF));
        $this->assertSame($before, $this->box->snapshot([]));
    }

    public function test_a_swap_cut_off_half_way_goes_on_from_its_journal(): void
    {
        $job = $this->job();
        $ops = (new SwapEngine($job))->ops();

        // The request that ran the first three moves was killed in the middle of the fourth one: its old file had gone
        // into the backup, the new one was not in place yet.
        (new SwapEngine(['ops' => array_slice($ops, 0, 3)] + $job))->forward(INF);
        $fourth = $ops[3];
        file_put_contents($job['journal'], json_encode(['i' => 3, 's' => 'begin', 't' => time()])."\n", FILE_APPEND);
        File::ensureDirectoryExists(dirname($this->backup.'/files/'.$fourth['path']));
        rename($this->box->root.'/'.$fourth['path'], $this->backup.'/files/'.$fourth['path']);
        $this->assertFileDoesNotExist($this->box->root.'/'.$fourth['path']);

        $result = (new SwapEngine($job))->forward(INF);

        $this->assertSame(['state' => 'done', 'done' => 7, 'total' => 7], $result);
        $this->assertSame($this->expectedAfter(), $this->box->snapshot([]));
    }

    public function test_a_swap_cut_off_half_way_can_also_be_put_back(): void
    {
        $job = $this->job();
        $before = $this->box->snapshot([]);
        $ops = (new SwapEngine($job))->ops();
        (new SwapEngine(['ops' => array_slice($ops, 0, 4)] + $job))->forward(INF);

        $back = (new SwapEngine($job))->rollback(INF);

        $this->assertSame('done', $back['state']);
        $this->assertSame($before, $this->box->snapshot([]));
    }

    public function test_a_damaged_new_file_stops_the_swap_before_its_live_file_moves(): void
    {
        $job = $this->job();
        $before = $this->box->snapshot([]);
        file_put_contents($this->work.'/staging/config/x.php', 'X 2 but damaged');
        $engine = new SwapEngine($job);

        try {
            $engine->forward(INF);
            $this->fail('A damaged file was moved in.');
        } catch (RuntimeException $e) {
            $this->assertSame('The new copy of config/x.php is missing or damaged.', $e->getMessage());
        }

        $this->assertSame('X 1', $this->box->read('config/x.php'), 'the live file did not move');
        $this->assertSame('A 2', $this->box->read('app/A.php'), 'the moves before it happened');

        $back = $engine->rollback(INF);

        $this->assertSame('done', $back['state']);
        $this->assertSame([], $back['kept']);
        $this->assertSame($before, $this->box->snapshot([]), 'byte-identical to before');
    }

    public function test_a_folder_in_the_way_stops_the_swap_and_the_way_back_restores_everything(): void
    {
        File::ensureDirectoryExists($this->box->root.'/app/D.php');
        File::put($this->box->root.'/app/D.php/keep.txt', 'a folder where the release puts a file');
        $job = $this->job();
        $before = $this->box->snapshot([]);
        $engine = new SwapEngine($job);

        try {
            $engine->forward(INF);
            $this->fail('The move into a folder worked.');
        } catch (RuntimeException $e) {
            $this->assertSame('Cannot replace app/D.php.', $e->getMessage());
        }

        $engine->rollback(INF);

        $this->assertSame($before, $this->box->snapshot([]));
    }

    public function test_a_file_changed_while_the_update_runs_stops_it(): void
    {
        $job = $this->job();
        file_put_contents($this->box->root.'/config/x.php', 'X 1 + an edit after the plan');
        $before = $this->box->snapshot([]);
        $engine = new SwapEngine($job);

        try {
            $engine->forward(INF);
            $this->fail('A file changed since the plan was replaced.');
        } catch (RuntimeException $e) {
            $this->assertSame('config/x.php changed while the update was running.', $e->getMessage());
        }

        $engine->rollback(INF);

        $this->assertSame($before, $this->box->snapshot([]), 'the edit stays, everything else is back');
    }

    public function test_the_way_back_leaves_a_file_changed_after_the_move_and_lists_it(): void
    {
        $job = $this->job();
        $engine = new SwapEngine($job);
        $engine->forward(INF);
        file_put_contents($this->box->root.'/config/x.php', 'X 2 + an edit after the update');

        $back = $engine->rollback(INF);

        $this->assertSame(['config/x.php'], $back['kept']);
        $this->assertSame('X 2 + an edit after the update', $this->box->read('config/x.php'));
        $this->assertSame('X 1', (string) file_get_contents($this->backup.'/files/config/x.php'), 'its old copy stays in the backup');
        $this->assertSame('A 1', $this->box->read('app/A.php'));
        $this->assertNull($this->box->read('app/D.php'));
    }

    public function test_the_work_stops_at_the_deadline_and_goes_on_later(): void
    {
        $job = $this->job();
        $engine = new SwapEngine($job);

        $this->assertSame(['state' => 'more', 'done' => 0, 'total' => 7], $engine->forward(microtime(true) - 1));
        $this->assertSame(self::OLD['app/A.php'], $this->box->read('app/A.php'));
        $this->assertSame(['state' => 'done', 'done' => 7, 'total' => 7], $engine->forward(INF));
        $this->assertSame(['state' => 'more', 'left' => 7, 'kept' => []], $engine->rollback(microtime(true) - 1));
        $this->assertSame(['state' => 'done', 'left' => 0, 'kept' => []], $engine->rollback(INF));
    }

    public function test_only_the_asked_phases_run(): void
    {
        $job = $this->job();
        $engine = new SwapEngine($job);

        $this->assertSame(['state' => 'done', 'done' => 1, 'total' => 1], $engine->forward(INF, ['pre']));
        $this->assertSame('js 2', $this->box->read('public/build/assets/app-2.js'), 'the new built asset is in place');
        $this->assertSame('A 1', $this->box->read('app/A.php'), 'nothing else moved yet');
        $this->assertSame(['done' => 1, 'total' => 7, 'begun' => 1, 'undone' => 0], $engine->progress());
    }

    public function test_after_the_way_back_the_same_job_cannot_go_forward(): void
    {
        $job = $this->job();
        $engine = new SwapEngine($job);
        $engine->forward(INF);
        $engine->rollback(INF);

        $this->expectExceptionMessage('This update was put back already. Start it again.');
        (new SwapEngine($job))->forward(INF);
    }

    public function test_settle_clears_laravels_compiled_caches_only(): void
    {
        $this->box->write([
            'bootstrap/cache/config.php' => '<?php return [];',
            'bootstrap/cache/routes-v7.php' => '<?php',
            'bootstrap/cache/events.php' => '<?php return [];',
            'bootstrap/cache/packages.php' => '<?php return [];',
            'bootstrap/cache/services.php' => '<?php return [];',
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        ]);
        $engine = new SwapEngine($this->job());

        $settle = $engine->settle();

        $this->assertSame(5, $settle['caches']);
        $this->assertSame([], glob($this->box->root.'/bootstrap/cache/*.php'));
        $this->assertFileExists($this->box->root.'/bootstrap/cache/.gitignore');
        $this->assertContains($settle['opcache'], ['off', 'reset', 'invalidated', 'wait', 'stale']);
        $this->assertSame(0, $settle['views'], 'a job without a compiled-views folder deletes none');
        $this->assertStringContainsString('caches cleared: 5', (string) file_get_contents($this->work.'/update.log'));
    }

    public function test_settle_deletes_the_compiled_views_and_moved_files_get_the_time_of_the_move(): void
    {
        // Blade recompiles a template only when it is newer than its compiled copy; rename() keeps a file's time.
        $views = $this->box->path('views');
        $this->box->write(['a1b2c3.php' => '<?php echo "compiled from the old template";', '.gitignore' => "*\n"], $views);
        $engine = new SwapEngine($this->job() + ['views' => $views]);
        touch($this->work.'/staging/app/A.php', time() - 86400);

        $engine->forward(INF);
        $settle = $engine->settle();

        $this->assertSame(1, $settle['views']);
        $this->assertFileDoesNotExist($views.'/a1b2c3.php');
        $this->assertFileExists($views.'/.gitignore', 'only compiled views go');
        clearstatcache();
        $this->assertGreaterThanOrEqual(time() - 60, filemtime($this->box->root.'/app/A.php'), 'the new file has the time of the move');
        $this->assertStringContainsString('compiled views: 1', (string) file_get_contents($this->work.'/update.log'));

        // The way back (an undo, a failed swap): the old file comes back with a new time, the new views go too.
        $this->box->write(['d4e5f6.php' => '<?php echo "compiled from the new template";'], $views);
        touch($this->backup.'/files/app/A.php', time() - 86400);

        $engine->rollback(INF);
        $this->assertSame(1, $engine->settle()['views']);

        $this->assertFileDoesNotExist($views.'/d4e5f6.php');
        clearstatcache();
        $this->assertSame('A 1', file_get_contents($this->box->root.'/app/A.php'));
        $this->assertGreaterThanOrEqual(time() - 60, filemtime($this->box->root.'/app/A.php'), 'the old file has the time it came back');
    }

    public function test_new_folders_are_made_0755_whatever_the_umask(): void
    {
        $this->assertTrue(SwapEngine::makeFolder($this->box->root.'/public/new/deeper'));
        $this->assertDirectoryExists($this->box->root.'/public/new/deeper');
        $this->assertTrue(SwapEngine::makeFolder($this->box->root.'/public/new/deeper'), 'a folder that is there is fine');

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows has no Unix folder modes (the folders are made; their mode is checked on Unix).');
        }

        $umask = umask(0077);

        try {
            $this->assertTrue(SwapEngine::makeFolder($this->box->root.'/public/strict/deeper'));
        } finally {
            umask($umask);
        }

        clearstatcache();
        // A restrictive umask (suPHP, per-user PHP-FPM) would leave them 0700: the web server could not read their files.
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->box->root.'/public/strict')), -3));
        $this->assertSame('755', substr(sprintf('%o', fileperms($this->box->root.'/public/strict/deeper')), -3));
    }

    public function test_a_damaged_list_of_moves_is_refused(): void
    {
        $job = $this->job();
        $ops = (new SwapEngine($job))->ops();
        $ops[0]['path'] = '../outside.php';

        $this->expectExceptionMessage('The list of files to move is damaged.');
        (new SwapEngine(['ops' => $ops] + $job))->forward(INF);
    }

    public function test_a_job_needs_its_folders(): void
    {
        $this->expectExceptionMessage('The update job has no journal.');
        new SwapEngine(['root' => $this->box->root, 'source' => $this->work, 'backup' => $this->backup]);
    }

    /** A job like Updater writes it: the moves of SwapPlanner, the new files in <work>/staging, swap.json, a journal. */
    private function job(): array
    {
        $hashes = fn (array $files): array => array_map(fn (string $c): string => hash('sha256', $c), array_diff_key($files, [Manifest::FILE => true]));
        $plan = SwapPlanner::plan(
            new Manifest('1.0.1', '8.3.0', '1.0.0', $hashes(self::NEW), ['app/C.php']),
            new Manifest('1.0.0', '8.3.0', '1.0.0', $hashes(self::OLD), []),
            hash('sha256', self::NEW[Manifest::FILE]),
            fn (string $path): ?string => SwapEngine::hash($this->box->root.'/'.$path),
            fn (string $path): ?string => $this->box->read($path),
            fn (string $path): ?string => self::NEW[$path] ?? null,
        );

        $this->box->write(self::NEW, $this->work.'/staging');
        File::put($this->work.'/swap.json', (string) json_encode(['ops' => $plan['ops']]));

        return [
            'root' => $this->box->root,
            'source' => $this->work,
            'backup' => $this->backup,
            'journal' => $this->work.'/journal.jsonl',
            'ops' => $this->work.'/swap.json',
            'log' => $this->work.'/update.log',
        ];
    }

    /** @return array<string, string> the site after the update: the release's files + the site's own data */
    private function expectedAfter(): array
    {
        $tree = array_map(fn (string $c): string => hash('sha256', $c), self::NEW + self::DATA);
        ksort($tree, SORT_STRING);

        return $tree;
    }
}
