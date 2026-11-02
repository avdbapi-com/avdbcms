<?php

namespace Tests\Feature;

use App\Support\FinishLock;
use App\Support\PendingMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\WaitsForChildProcess;
use Tests\TestCase;

/**
 * The lock of the database step of Finish update (round 1 of 1.2.3, R1-002, and round 1b: PHP-FPM's
 * request_terminate_timeout ends the PHP process without any shutdown function). A request the host ended must never
 * hold the next one up, and a request that is still working must never lose the lock to another:
 *
 *  - a request killed from outside (no PHP runs any more: no "finally", no shutdown function) leaves the cache lock
 *    taken, and the next request takes it over at once: the file lock the killed process held was released by the
 *    operating system, and the note next to the cache lock says that its holder had that file lock;
 *  - while the holder still works, nobody takes it (its file lock is still held);
 *  - a lock held on another server sharing the cache store (the file lock here says nothing about it) and a lock
 *    without a note wait for their time (FinishLock::SECONDS), as in 1.2.2.
 *
 * MySQL's and MariaDB's named lock (GET_LOCK, the part that waits for a statement the ended request had started) is
 * checked end to end on a MariaDB site (the lane's e2e): the suite runs on SQLite.
 */
class FinishLockTest extends TestCase
{
    use WaitsForChildProcess;

    /**
     * How long the holder may take to reach its migration (start PHP, boot the app, take the lock) before the test
     * fails with what the holder printed. A few seconds on a busy machine; well under the holder's sleep(120).
     */
    private const HOLDER_READY_SECONDS = 45;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = str_replace('\\', '/', storage_path('framework/testing/finish-lock-'.bin2hex(random_bytes(4))));
        FinishLock::$file = $this->dir.'/finish.lock';
        File::ensureDirectoryExists($this->dir.'/cache');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        if (class_exists(FinishLock::class)) {
            FinishLock::$file = null;
        }

        parent::tearDown();
    }

    /**
     * R1-002 / round 1b: the request of Finish is killed from outside in the middle of a migration (PHP-FPM's
     * request_terminate_timeout, an out-of-memory kill): no PHP code of it runs any more. The next request gets the lock
     * at once (1.2.3 RC1: every click said "The update is already being finished" for five minutes; the first fix lane
     * pass: for two minutes, its shutdown function never runs on such a kill).
     */
    public function test_a_finish_killed_from_outside_never_holds_the_next_request_up(): void
    {
        $this->useFileCache();
        $process = $this->holderInAnotherProcess();

        try {
            // The holder is still working: nobody takes its lock.
            $this->assertNull(FinishLock::acquire(), 'the request that holds it is still working');
            $this->assertTrue(Cache::has(FinishLock::NOTE), 'the holder noted how it is locked');

            // Killed hard: SIGKILL, on Windows TerminateProcess (taskkill /F); no PHP shutdown function can run.
            $process->signal(9);
            $process->wait();
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        $out = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('migration started', $out, $out);
        $this->assertStringNotContainsString('NOT REACHED', $out);
        $this->assertStringNotContainsString('shutdown ran', $out, 'no PHP ran after the kill');

        // Nothing released the cache lock: the process was gone before any PHP could.
        $probe = Cache::lock(PendingMigrations::LOCK, 10);
        $this->assertFalse($probe->get(), 'the killed request left its cache lock taken');

        // The next request goes on at once (the operating system released the file lock with the process).
        $at = microtime(true);
        $lock = null;

        while ($lock === null && microtime(true) - $at < 5.0) {
            $lock = FinishLock::acquire();

            if ($lock === null) {
                usleep(50_000);
            }
        }

        $this->assertNotNull($lock, 'the next request took the lock over');
        $this->assertLessThan(5.0, microtime(true) - $at, 'at once, not after '.FinishLock::SECONDS.' seconds');
        $this->assertTrue($lock->tookOver());
        $this->assertSame(['file' => true, 'session' => false, 'cache' => true], $lock->holds());

        // And it is a lock like any other: a third request waits for it.
        $this->assertNull(FinishLock::acquire());
        $lock->release();
        $again = FinishLock::acquire();
        $this->assertNotNull($again);
        $this->assertFalse($again->tookOver(), 'released normally: nothing to take over');
        $again->release();
        $this->assertFalse(Cache::has(FinishLock::NOTE), 'the note goes with the lock');
    }

    public function test_a_request_that_is_still_working_keeps_the_lock(): void
    {
        $first = FinishLock::acquire();
        $this->assertNotNull($first);
        $this->assertFalse($first->tookOver());

        // Another click, another tab: busy (the first one's file lock is held), whatever the note says.
        $this->assertNull(FinishLock::acquire());
        $this->assertNull(FinishLock::acquire());

        $first->release();
        $first->release();

        $second = FinishLock::acquire();
        $this->assertNotNull($second);
        $this->assertFalse($second->tookOver());
        $second->release();
    }

    /**
     * A web server that shares this cache store (redis, the database) holds the lock: the file lock of this server says
     * nothing about it, so the lock is not taken; it is released by its holder, or after FinishLock::SECONDS.
     */
    public function test_a_lock_held_on_another_server_waits_for_its_time(): void
    {
        $this->assertTrue(Cache::lock(FinishLock::NAME, FinishLock::SECONDS)->get());
        Cache::put(FinishLock::NOTE, ['owner' => 'elsewhere', 'file' => sha1('another server|/srv/site/storage/framework/avd-finish-update.lock'),
            'session' => false, 'at' => time()], FinishLock::SECONDS);

        $this->assertNull(FinishLock::acquire());
        $this->travel(FinishLock::SECONDS - 5)->seconds();
        $this->assertNull(FinishLock::acquire(), 'still within its time');

        $this->travel(10)->seconds();
        $lock = FinishLock::acquire();
        $this->assertNotNull($lock, 'its time is over');
        $this->assertFalse($lock->tookOver());
        $lock->release();
    }

    /** A holder that left no note (it was ended between taking the lock and writing the note): its time, as in 1.2.2. */
    public function test_a_lock_without_a_note_waits_for_its_time(): void
    {
        $this->assertTrue(Cache::lock(FinishLock::NAME, FinishLock::SECONDS)->get());

        $this->assertNull(FinishLock::acquire());

        $this->travel(FinishLock::SECONDS + 1)->seconds();
        $lock = FinishLock::acquire();
        $this->assertNotNull($lock);
        $lock->release();
    }

    /* ----------------------------------------------------------------- helpers */

    /** The test's own file cache (another PHP process reads and writes the same one). */
    private function useFileCache(): void
    {
        config(['cache.default' => 'file', 'cache.stores.file.path' => $this->dir.'/cache']);
        app('cache')->forgetDriver('file');
    }

    /**
     * A request of Finish in a PHP of its own: the copy's app on a SQLite file and the test's file cache and lock file,
     * every migration of the copy marked as run, one more that keeps working (sleeps) until it is killed. Returns once
     * that migration runs, with the lock taken.
     *
     * Ready means: the migration wrote its marker file (R1R-16), which this process polls for at most
     * HOLDER_READY_SECONDS (Tests\Support\WaitsForChildProcess). A line of the holder's output is no signal: its
     * "migration started" could miss Process::waitUntil()'s callback, which then waited for the holder's sleep(120) (the
     * c1 baseline run: 121.9 s, then a failure). The product never waits for a child's output this way: Finish runs its
     * migrations in the request itself.
     */
    private function holderInAnotherProcess(): Process
    {
        $dir = $this->dir;
        $marker = $dir.'/migration-started';
        File::ensureDirectoryExists($dir.'/migrations');
        touch($dir.'/db.sqlite');
        // The line first (the test reads it back after the kill), then the marker file the test waits for.
        File::put($dir.'/migrations/2099_09_09_000000_keeps_working.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n"
            ."    public function up(): void\n    {\n        echo \"migration started\\n\";\n        flush();\n"
            ."        file_put_contents(".var_export($marker, true).", (string) getmypid());\n        sleep(120);\n    }\n};\n");
        File::put($dir.'/run.php', <<<'PHP'
            <?php
            [$self, $base, $cache, $db, $migrations, $lockFile] = $argv;
            require $base.'/vendor/autoload.php';
            $app = require $base.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['cache.default' => 'file', 'cache.stores.file.path' => $cache, 'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $db, 'database.connections.sqlite.foreign_key_constraints' => false]);
            app('cache')->forgetDriver('file');
            Illuminate\Support\Facades\DB::purge('sqlite');
            App\Support\FinishLock::$file = $lockFile;
            register_shutdown_function(static function (): void {
                echo "shutdown ran\n";
            });
            $migrator = app('migrator');
            $repository = $migrator->getRepository();
            $repository->setSource('sqlite');
            if (! $repository->repositoryExists()) {
                $repository->createRepository();
            }
            foreach (array_keys($migrator->getMigrationFiles([database_path('migrations')])) as $name) {
                $repository->log($name, 1);
            }
            $migrator->path($migrations);
            echo "pending: ".json_encode(App\Support\PendingMigrations::pendingNow())."\n";
            echo "NOT REACHED: ".json_encode(App\Support\PendingMigrations::run())."\n";
            PHP);

        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=stderr', '-d', 'max_execution_time=0',
            $dir.'/run.php', base_path(), $dir.'/cache', $dir.'/db.sqlite', $dir.'/migrations', $dir.'/finish.lock'],
            base_path(), ['APP_ENV' => 'testing', 'APP_INSTALLED' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dir.'/db.sqlite',
                'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array']);
        $process->setTimeout(180);
        $process->start();
        $this->waitForMarker($process, $marker, self::HOLDER_READY_SECONDS, 'the holder (a request of Finish in its migration)');
        $this->assertTrue($process->isRunning(), 'the holder runs its migration: '.trim($process->getOutput().$process->getErrorOutput()));

        return $process;
    }
}
