<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\PendingMigrations;
use App\Support\StorageHider;
use App\Support\TimeBudget;
use App\Support\Updater\InstallData;
use App\Support\Updater\Manifest;
use App\Support\Updater\PlanStore;
use App\Support\Updater\TranslationMerge;
use App\Support\Updater\Updater;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\ReleaseServer;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\Feature\Updater\Support\ZipBuilder;
use Tests\Support\WaitsForChildProcess;
use Tests\TestCase;

/**
 * Finish on a strict host (round 1 of 1.2.3: R1-002, R1-010, R1-098, R1-100, R1-101, R1-102, R1-103). A host with a
 * 30-second limit that set_time_limit() cannot raise ended Finish half-way (the old file cache of 1.2.2 took 20 seconds
 * to delete, the recount 10 more), and the lock of the database step then held every retry for five minutes:
 *
 *  - the database step runs one migration at a time until the request's deadline, the next request goes on, and the
 *    result counts every database change of the update, also of a request the host ended;
 *  - a request ended by PHP's time limit or killed from outside (PHP-FPM's request_terminate_timeout runs no PHP at all,
 *    not even a shutdown function) never holds the next one up: it goes on at once (App\Support\FinishLock), and while
 *    another request holds the database step the progress counts what the database recorded;
 *  - the stale cache folders are deleted by the clean-up slices (Tools > Updates, the scheduler), also those an earlier
 *    run left; tidy() says something is left while a leftover cannot go;
 *  - Finish gives the starter data of new installs the release's copy where the site has a shipped one, the Admin
 *    actions row counts the files like the result, and the old 1.2.0 hook's lines read in the site's time zone.
 */
class UpdateFinishStrictHostTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;
    use WaitsForChildProcess;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        Updater::$finishSeconds = null;
        InstallData::$shippedHashes = null;
        TimeBudget::clear();

        if (class_exists(\App\Support\FinishLock::class)) {
            \App\Support\FinishLock::$file = null;
        }

        $this->removeSite();

        parent::tearDown();
    }

    public function test_the_database_step_runs_one_migration_at_a_time_and_stops_at_the_deadline(): void
    {
        $names = $this->pendingMigrations(3, 300);
        // Room for one migration only (the first of a request always runs).
        TimeBudget::until(microtime(true) + PendingMigrations::RESERVE + 0.2);

        $first = PendingMigrations::run();

        $this->assertTrue($first['ok']);
        $this->assertTrue($first['more']);
        $this->assertSame([$names[0]], $first['ran']);
        $this->assertSame([$names[1], $names[2]], $first['left']);
        $this->assertSame('Some of the database changes are done. Click Finish update again for the rest.', $first['message']);
        $this->assertSame([$names[1], $names[2]], PendingMigrations::pendingNow(), 'in their order, the first one recorded');

        // Without a deadline (the console) the rest run in one go.
        TimeBudget::clear();
        $rest = PendingMigrations::run();

        $this->assertSame([true, false, [$names[1], $names[2]], []], [$rest['ok'], $rest['more'], $rest['ran'], $rest['left']]);
        $this->assertSame('The update is finished.', $rest['message']);
        $this->assertSame([], PendingMigrations::pendingNow());
        $this->assertLessThanOrEqual(120, PendingMigrations::LOCK_SECONDS, 'a lock nobody released holds two minutes at most');
    }

    public function test_a_finish_that_runs_out_of_time_goes_on_in_the_next_request_and_counts_every_change(): void
    {
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);
        $names = $this->pendingMigrations(3, 300);
        // A request of Finish has room for one migration (a host's 30 seconds, here scaled down).
        Updater::$finishSeconds = PendingMigrations::RESERVE + 0.2;
        $answers = [];

        for ($i = 0; $i < 10; $i++) {
            $answer = $this->updater($this->next)->finish($this->admin);
            $answers[] = $answer;

            if ($answer['next'] !== 'finish') {
                break;
            }
        }

        $this->assertSame('done', $answer['status'], (string) $answer['message']);
        $this->assertSame('reload', $answer['next']);
        $this->assertGreaterThanOrEqual(3, count($answers), 'one migration per request');
        $this->assertTrue(collect(array_slice($answers, 0, -1))->every(fn (array $a): bool => $a['ok'] && $a['status'] === 'migrating' && $a['next'] === 'finish'));
        $this->assertSame(['done' => 1, 'total' => 3], $answers[0]['progress']);
        $this->assertSame(['done' => 2, 'total' => 3], $answers[1]['progress']);
        $this->assertSame([], PendingMigrations::pendingNow());
        $this->assertSame($names, $this->storedPlan()['migrations_before']);
        $this->assertSame(3, $this->storedPlan()['migrations']);
        $finished = array_values(array_filter($this->auditRows(), fn (array $row): bool => $row['action'] === 'update.finished'));
        $this->assertSame([AppVersion::CURRENT.' -> '.$this->next.' (3 database changes)'], array_column($finished, 'details'));
        $log = implode("\n", $this->updater($this->next)->logLines(null, 500));
        $this->assertStringContainsString('1 database change done so far; the next request goes on', $log);
        $this->assertStringContainsString('database step done (3 migrations); AVDBCMS '.$this->next.' is installed', $log);
    }

    /** R1-101: the first request of Finish named the migrations, ran two and was ended by the host before it said so. */
    public function test_a_finish_the_host_ended_half_way_still_counts_every_database_change(): void
    {
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);
        $names = $this->pendingMigrations(3, 0);
        $this->changePlan(['status' => 'migrating', 'migrations_before' => $names, 'migrations_base' => 0]);
        Artisan::call('migrate', ['--force' => true, '--path' => array_slice($this->migrationFiles(), 0, 2), '--realpath' => true]);
        PendingMigrations::forget();
        $this->assertSame([$names[2]], PendingMigrations::pendingNow());

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(3, $this->storedPlan()['migrations']);
        $this->assertSame(AppVersion::CURRENT.' -> '.$this->next.' (3 database changes)', array_values(array_filter($this->auditRows(), fn (array $row): bool => $row['action'] === 'update.finished'))[0]['details']);
        $this->assertStringContainsString('database step done (3 migrations)', implode("\n", $this->updater($this->next)->logLines(null, 500)));
    }

    /**
     * R1-002: PHP's time limit ends the request of Finish in the middle of a migration (a host that does not let
     * set_time_limit() raise it). The lock of the database step is released at once, so the next click goes on: in
     * 1.2.3 RC1 it stayed taken for five minutes ("The update is already being finished").
     */
    public function test_php_s_time_limit_ending_the_database_step_releases_its_lock_at_once(): void
    {
        $dir = $this->box->path('killed');
        File::ensureDirectoryExists($dir.'/cache');
        File::ensureDirectoryExists($dir.'/migrations');
        touch($dir.'/db.sqlite');
        File::put($dir.'/migrations/2099_09_09_000000_takes_too_long.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n"
            ."    public function up(): void\n    {\n        echo \"migration started\\n\";\n        set_time_limit(1);\n        \$x = 0.0;\n"
            ."        while (true) {\n            \$x += sqrt(2.0);\n        }\n    }\n};\n");
        File::put($dir.'/run.php', <<<'PHP'
            <?php
            // A request of Finish on a strict host, in a PHP of its own: the copy's app on a SQLite file and a file cache of
            // the test's own, every migration of the copy marked as run, one more migration that runs past PHP's limit.
            [$self, $base, $cache, $db, $migrations] = $argv;
            require $base.'/vendor/autoload.php';
            $app = require $base.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['cache.default' => 'file', 'cache.stores.file.path' => $cache, 'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $db, 'database.connections.sqlite.foreign_key_constraints' => false]);
            app('cache')->forgetDriver('file');
            Illuminate\Support\Facades\DB::purge('sqlite');
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
            echo "lock taken: ".(App\Support\PendingMigrations::run()['busy'] ? 'busy' : 'no')."\n";
            echo "NOT REACHED\n";
            PHP);

        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=stderr', $dir.'/run.php', base_path(), $dir.'/cache', $dir.'/db.sqlite', $dir.'/migrations'],
            base_path(), ['APP_ENV' => 'testing', 'APP_INSTALLED' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dir.'/db.sqlite',
                'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array']);
        $process->setTimeout(120);
        $process->run();

        $out = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('migration started', $out, $out);
        $this->assertStringContainsString('Maximum execution time', $out, 'PHP ended the request in the migration: '.$out);
        $this->assertStringNotContainsString('NOT REACHED', $out);

        // The next request of Finish gets the lock at once.
        $store = Cache::build(['driver' => 'file', 'path' => $dir.'/cache']);
        $lock = $store->lock(PendingMigrations::LOCK, 10);
        $this->assertTrue($lock->get(), 'the lock of the ended request was released');
        $lock->release();
    }

    /**
     * R1-002 / round 1b: the request of Finish is killed from OUTSIDE PHP in the middle of a migration (PHP-FPM's
     * request_terminate_timeout, an out-of-memory kill): no PHP code of it runs any more, not even a shutdown function.
     * The next request of Finish goes on at once. In 1.2.3 RC1 every click answered "The update is already being
     * finished" for five minutes (the cache lock's time), after the first fix of this round for two minutes.
     */
    public function test_a_finish_killed_from_outside_goes_on_with_the_next_request(): void
    {
        $dir = $this->box->path('killed-outside');
        $names = $this->pendingMigrations(1, 0);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir.'/cache']);
        app('cache')->forgetDriver('file');

        if (class_exists(\App\Support\FinishLock::class)) {
            \App\Support\FinishLock::$file = $dir.'/finish.lock';
        }

        $process = $this->finishInAnotherProcess($dir);

        try {
            $busy = PendingMigrations::run();
            $this->assertTrue($busy['busy'], 'while the other request works, this one waits');
            $this->assertSame($names, $this->notRunYet());

            // Killed hard: SIGKILL, on Windows TerminateProcess (taskkill /F); no PHP shutdown function runs.
            $process->signal(9);
            $process->wait();
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        $out = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('migration started', $out, $out);
        $this->assertStringNotContainsString('shutdown ran', $out, 'no PHP ran after the kill');

        $at = microtime(true);
        $next = PendingMigrations::run();

        $this->assertFalse($next['busy'], 'the next request goes on at once: '.$next['message']);
        $this->assertTrue($next['ok'], $next['message']);
        $this->assertSame($names, $next['ran']);
        $this->assertSame([], $this->notRunYet());
        $this->assertLessThan(10.0, microtime(true) - $at);
        $this->assertTrue($next['took_over'] ?? false, 'it took over the lock the killed request left');
    }

    /**
     * FU-REV-02: while another request holds the database step, Finish answers "already being finished" and asks again
     * in 10 seconds; its progress counts the database changes the database has recorded (a request that was ended never
     * stored its own count: the progress said 0 of 13 with 12 done).
     */
    public function test_while_another_request_holds_the_database_step_the_progress_counts_what_the_database_recorded(): void
    {
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);
        $names = $this->pendingMigrations(3, 0);
        $this->changePlan(['status' => 'migrating', 'migrations_before' => $names, 'migrations_base' => 0]);
        Artisan::call('migrate', ['--force' => true, '--path' => array_slice($this->migrationFiles(), 0, 2), '--realpath' => true]);
        PendingMigrations::forget();
        // Another request is still in the database step (Home's Finish update, or a request the host ended while the
        // database still works on its statement).
        $held = Cache::lock(PendingMigrations::LOCK, 60);
        $this->assertTrue($held->get());

        try {
            $busy = $this->updater($this->next)->finish($this->admin);
        } finally {
            $held->release();
        }

        // Asks again in 10 seconds (Updater::BUSY_RETRY_SECONDS; 60 in 1.2.3 RC1).
        $this->assertSame([false, 'migrating', 'finish', 10], [$busy['ok'], $busy['status'], $busy['next'], $busy['retry_after'] ?? null]);
        $this->assertSame('The update is already being finished. Wait a minute, then reload this page.', $busy['message']);
        $this->assertSame(['done' => 2, 'total' => 3], $busy['progress']);

        $done = $this->finishOn($this->updater($this->next));
        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(3, $this->storedPlan()['migrations']);
    }

    /** Home's Finish update (after an upload by hand) has the same deadline: a click with database changes left says so. */
    public function test_home_finish_update_goes_on_with_the_next_click(): void
    {
        $names = $this->pendingMigrations(2, 300);
        Updater::$finishSeconds = PendingMigrations::RESERVE + 0.2;

        $this->actingAs($this->admin)->postJson(route('admin.update.finish'))
            ->assertOk()
            ->assertJson(['ok' => true, 'more' => true, 'message' => 'Some of the database changes are done. Click Finish update again for the rest.']);
        $this->assertSame([$names[1]], PendingMigrations::pendingNow());

        $this->actingAs($this->admin)->post(route('admin.update.finish'))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_starts_with($message, 'The update is finished.'));
        $this->assertSame([], PendingMigrations::pendingNow());
    }

    /** R1-010 + R1-002: the stale cache folders (also those an earlier run left, with no old cache folder left to move). */
    public function test_stale_cache_folders_are_deleted_by_the_clean_up_slices(): void
    {
        $storage = $this->box->root.'/storage';
        File::ensureDirectoryExists($storage.'/framework/cache/data/0123456789abcdef');
        file_put_contents($storage.'/framework/cache/data/0123456789abcdef/current', 'the current cache');

        foreach (['00112233aabbccdd', 'ffeeddcc44332211'] as $hex) {
            for ($i = 0; $i < 400; $i++) {
                $folder = $storage.'/framework/cache/stale-'.$hex.'/'.sprintf('%02x/%02x', $i % 16, intdiv($i, 16));
                File::ensureDirectoryExists($folder);
                file_put_contents($folder.'/entry'.$i, 'expired');
            }
        }

        $this->assertCount(2, StorageHider::staleCacheFolders($storage));
        $this->assertTrue($this->updater()->tidyPending(), 'the clean-up knows them');
        $this->app->instance(Updater::class, $this->updater());
        $events = array_values(array_filter($this->app->make(Kernel::class)->resolveConsoleSchedule()->events(),
            fn ($event): bool => str_contains((string) $event->command, 'updates:tidy')));
        $this->assertTrue($events[0]->filtersPass($this->app), 'the scheduler starts updates:tidy for them');

        $slices = [];

        do {
            $tidy = $this->updater()->tidy(0.02);
            $slices[] = $tidy['deleted'];
        } while ($tidy['pending'] && count($slices) < 3000);

        $this->assertFalse($tidy['pending']);
        $this->assertGreaterThan(1, count($slices), 'more than one slice');
        $this->assertSame([], StorageHider::staleCacheFolders($storage));
        $this->assertSame([], glob($storage.'/framework/cache/stale-*') ?: []);
        $this->assertFileExists($storage.'/framework/cache/data/0123456789abcdef/current', 'the current cache stays');
        $this->assertFalse($this->updater()->tidyPending());
        $this->assertFalse($events[0]->filtersPass($this->app));

        // While another request of the updater holds its lock, a stale cache folder still goes (no update step uses it).
        File::ensureDirectoryExists($storage.'/framework/cache/stale-0000111122223333/ab');
        file_put_contents($storage.'/framework/cache/stale-0000111122223333/ab/entry', 'expired');
        $held = $this->holdUpdateLock();

        try {
            $tidy = $this->updater()->tidy(2.0);
        } finally {
            $this->releaseUpdateLock($held);
        }

        $this->assertSame([], StorageHider::staleCacheFolders($storage));
        $this->assertFalse($tidy['pending']);
    }

    /** R1-102: a leftover that cannot go to the trash (and had no time to be deleted in place) is still "pending". */
    public function test_tidy_says_something_is_left_while_a_leftover_cannot_go(): void
    {
        $paths = $this->box->paths();
        File::ensureDirectoryExists($paths->job('0.9.8').'/staging/app');
        File::put($paths->job('0.9.8').'/staging/app/A.php', 'a');
        // The trash cannot be made (a file has its name): the leftover could only be deleted where it is.
        File::put($paths->trash(), 'not a folder');

        $tidy = $this->updater()->tidy(0.0);

        $this->assertDirectoryExists($paths->job('0.9.8'));
        $this->assertTrue($tidy['pending'], 'the leftover is still there: the page and updates:tidy go on');
        $this->assertTrue($this->updater()->tidyPending());

        // With time, the slice deletes it in place, and then nothing is left.
        $tidy = $this->updater()->tidy(2.0);

        $this->assertDirectoryDoesNotExist($paths->job('0.9.8'));
        $this->assertFalse($tidy['pending']);
        $this->assertGreaterThan(0, $tidy['deleted'], 'what it deleted in place counts');
    }

    /** R1-100: the starter data of new installs. */
    public function test_finish_gives_a_shipped_copy_of_the_starter_data_the_releases_copy(): void
    {
        $old = "-- starter data of an earlier release\nCREATE TABLE `settings` (`id` int);\nINSERT INTO `settings` VALUES (1,'upload18_api_key','retired');\n";
        $new = "-- starter data of the release\nCREATE TABLE `settings` (`id` int);\nINSERT INTO `settings` VALUES (1,'upload18_api_key','new-public-key-0123456789');\n";
        InstallData::$shippedHashes = [InstallData::hash($old)];
        // The site has it with Windows line endings: still the shipped copy.
        $this->box->write(['database/install/example.sql' => str_replace("\n", "\r\n", $old)]);
        $this->releaseWithInstallData($new);

        $this->updateToNext();

        $this->assertSame($new, $this->box->read('database/install/example.sql'));
        $this->assertSame('replaced', $this->storedPlan()['install_data']);
        $this->assertStringContainsString('database/install/example.sql: the starter data of new installs is now the copy of '.$this->next,
            implode("\n", $this->updater($this->next)->logLines(null, 500)));
        $this->assertSame([], glob($this->box->root.'/database/install/*.tmp') ?: []);
    }

    public function test_finish_keeps_starter_data_the_owner_changed(): void
    {
        $old = "-- starter data of an earlier release\nCREATE TABLE `settings` (`id` int);\n";
        InstallData::$shippedHashes = [InstallData::hash($old)];
        $mine = $old."INSERT INTO `settings` VALUES (9,'my_own','yes');\n";
        $this->box->write(['database/install/example.sql' => $mine]);
        $this->releaseWithInstallData("-- new\nCREATE TABLE `settings` (`id` int);\n");

        $this->updateToNext();

        $this->assertSame($mine, $this->box->read('database/install/example.sql'));
        $this->assertSame('kept', $this->storedPlan()['install_data']);
    }

    /** R1-098: the Admin actions row counts the files as the result on Tools > Updates does. */
    public function test_the_files_row_counts_like_the_result_with_a_merged_language_file(): void
    {
        // The owner changed lang/en.json: the update merges it (their text stays, the new key comes in).
        $this->box->write(['lang/en.json' => TranslationMerge::encode(['Hello' => 'Hi there'])]);
        $this->app->instance(Updater::class, $this->updater($this->next));

        $this->updateToNext();

        $report = $this->storedPlan()['report'];
        $this->assertCount(1, $report['merged']);
        $row = array_values(array_filter($this->auditRows(), fn (array $row): bool => $row['action'] === 'update.files'))[0];
        $this->assertMatchesRegularExpression('/^'.$report['replaced'].' replaced, '.$report['added'].' added, '.$report['removed']
            .' removed, 1 language file merged \(zip engine (ZipArchive|PharData), OPcache [a-z]+\)$/', (string) $row['details']);
        $this->actingAs($this->admin)->get(route('admin.updates.index'))->assertOk()
            ->assertSee('Files replaced: '.$report['replaced'].'. Added: '.$report['added'].'. Removed: '.$report['removed'].'.')
            ->assertSee('lang/en.json');
    }

    /** R1-103: the lines the maintenance hook of 1.2.0 wrote in PHP's own time zone read in the site's. */
    public function test_the_hook_lines_of_a_swap_prepared_by_1_2_0_read_in_the_sites_time_zone(): void
    {
        config(['app.timezone' => 'UTC']);
        $paths = $this->box->paths();
        $log = "2026-10-26 19:11:21\tprepare\t652 files to replace\n"
            ."2026-10-26 19:11:23\tswap\tthe site is in maintenance while 954 files move\n"
            ."2026-10-26 21:11:29\tswap\tcaches cleared: 2, compiled views: 90; opcache: reset (invalidated 556, failed 0)\n"
            ."2026-10-26 21:11:29\tswap\tall 954 moves done; the site is up again\n"
            ."2026-10-26 19:11:33\tfinish\tdatabase step done (15 migrations); AVDBCMS 1.2.3 is installed\n";
        File::ensureDirectoryExists($paths->logs());
        file_put_contents($paths->logs('64af284f7613aec8.log'), $log);
        $plan = ['format' => 1, 'id' => '64af284f7613aec8', 'kind' => 'update', 'version' => '1.2.3', 'from' => '1.2.0', 'status' => 'done',
            'admin_id' => 1, 'admin_name' => 'owner', 'started_at' => time(), 'step_at' => time(), 'job' => '', 'hook_zone' => 'Europe/Kaliningrad'];
        PlanStore::write($paths->plan(), $plan);

        $lines = $this->updater()->logLines();

        $this->assertSame("2026-10-26 19:11:23\tswap\tthe site is in maintenance while 954 files move", $lines[1], 'Laravel\'s own line stays');
        $this->assertSame("2026-10-26 19:11:29\tswap\tcaches cleared: 2, compiled views: 90; opcache: reset (invalidated 556, failed 0)", $lines[2]);
        $this->assertSame("2026-10-26 19:11:29\tswap\tall 954 moves done; the site is up again", $lines[3]);
        $times = array_map(fn (string $line): string => substr($line, 0, 19), $lines);
        $this->assertSame($times, collect($times)->sort()->values()->all(), 'the log reads in order');

        // A swap of 1.2.1 or later: its hook had the site's zone, nothing is shifted.
        PlanStore::write($paths->plan(), ['from' => '1.2.1'] + array_diff_key($plan, ['hook_zone' => true]));
        $this->assertSame(preg_split('/\R/', trim($log)), $this->updater()->logLines());

        // A plan of 1.2.0 finished before Finish kept the zone: PHP's own zone (php.ini) of this server.
        $ini = ini_get('date.timezone');

        try {
            ini_set('date.timezone', 'Europe/Kaliningrad');
            PlanStore::write($paths->plan(), array_diff_key($plan, ['hook_zone' => true]));
            $this->assertSame("2026-10-26 19:11:29\tswap\tall 954 moves done; the site is up again", $this->updater()->logLines()[3]);
        } finally {
            ini_set('date.timezone', (string) $ini);
        }
    }

    /**
     * A migration that skips itself (Laravel's shouldRun() false) is never recorded as run: it stays "pending". The
     * database step then does not say "more" (the page would ask again and again): Finish ends and counts no change.
     */
    public function test_a_migration_that_skips_itself_never_keeps_finish_asking_again(): void
    {
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        File::put($folder.'/2099_09_09_000001_skips_itself.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\n"
            ."return new class extends Migration\n{\n    public function shouldRun(): bool\n    {\n        return false;\n    }\n\n"
            ."    public function up(): void\n    {\n    }\n};\n");
        app('migrator')->path($folder);
        PendingMigrations::forget();

        $result = PendingMigrations::run();

        $this->assertSame([true, false, []], [$result['ok'], $result['more'], $result['ran']]);
        $this->assertSame(['2099_09_09_000001_skips_itself'], $result['left']);

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(0, $this->storedPlan()['migrations']);
    }

    /** R1-103: Finish of a swap the updater of 1.2.0 prepared keeps the zone its hook wrote in (PHP's own, php.ini). */
    public function test_finish_keeps_the_zone_the_hook_of_1_2_0_wrote_its_lines_in(): void
    {
        $this->assertSame('swapped', $this->swapThroughHook($this->driveToSwap()['token'])['step']);
        $this->changePlan(['from' => '1.2.0']);
        $ini = ini_get('date.timezone');

        try {
            ini_set('date.timezone', 'Asia/Bangkok');
            $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        } finally {
            ini_set('date.timezone', (string) $ini);
        }

        $this->assertSame('Asia/Bangkok', $this->storedPlan()['hook_zone']);
    }

    public function test_finish_keeps_no_hook_zone_for_a_swap_of_1_2_1_or_later(): void
    {
        $this->updateToNext();

        $this->assertArrayNotHasKey('hook_zone', $this->storedPlan());
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * $count pending migrations (each $ms milliseconds), after every migration of the copy.
     *
     * @return list<string> their names in order
     */
    private function pendingMigrations(int $count, int $ms): array
    {
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        $names = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('2099_09_0%d_000000_create_strict_host_marker_%d_table', $i, $i);
            File::put($folder.'/'.$name.'.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
                ."use Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n"
                ."        usleep({$ms} * 1000);\n        Schema::create('strict_host_marker_{$i}', fn (Blueprint \$table) => \$table->id());\n    }\n};\n");
            $names[] = $name;
        }

        app('migrator')->path($folder);
        PendingMigrations::forget();

        return $names;
    }

    /**
     * A request of Finish in a PHP of its own, on the copy's app with a SQLite file of its own and the test's file cache
     * (and lock file): every migration of the copy marked as run, one more that keeps working (sleeps) until the process
     * is killed. Returns once that migration runs, with the lock of the database step taken: when the migration has
     * written its marker file (R1R-16, Tests\Support\WaitsForChildProcess; its "migration started" line could miss
     * Process::waitUntil()'s callback, which then waited for the sleep(120): 121.7 s and a failure on a busy machine).
     */
    private function finishInAnotherProcess(string $dir): Process
    {
        $marker = $dir.'/migration-started';
        File::ensureDirectoryExists($dir.'/cache');
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
            if (class_exists(App\Support\FinishLock::class)) {
                App\Support\FinishLock::$file = $lockFile;
            }
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
            echo "pending: ".json_encode(array_values(array_diff(array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()))), $repository->getRan())))."\n";
            echo "NOT REACHED: ".json_encode(App\Support\PendingMigrations::run())."\n";
            PHP);

        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'display_errors=stderr', '-d', 'max_execution_time=0',
            $dir.'/run.php', base_path(), $dir.'/cache', $dir.'/db.sqlite', $dir.'/migrations', $dir.'/finish.lock'],
            base_path(), ['APP_ENV' => 'testing', 'APP_INSTALLED' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dir.'/db.sqlite',
                'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array']);
        $process->setTimeout(180);
        $process->start();
        $this->waitForMarker($process, $marker, 45, 'the other request (a request of Finish in its migration)');
        $this->assertTrue($process->isRunning(), 'the other request runs its migration: '.trim($process->getOutput().$process->getErrorOutput()));

        return $process;
    }

    /**
     * The migrations that have not run yet, asked of the migrator itself (the same answer as PendingMigrations::pendingNow(),
     * which RC1 does not have: these tests run on RC1's code too, for the before/after record).
     *
     * @return list<string>
     */
    private function notRunYet(): array
    {
        $migrator = app('migrator');

        return array_values(array_diff(array_keys($migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()))),
            $migrator->getRepository()->getRan()));
    }

    /** @return list<string> the paths of the test's migrations, in order */
    private function migrationFiles(): array
    {
        $files = glob($this->box->path('migrations').'/*.php') ?: [];
        sort($files);

        return array_values($files);
    }

    /** The release served again, its zip carrying database/install/example.sql of $sql. */
    private function releaseWithInstallData(string $sql): void
    {
        $files = self::releaseFiles();
        $removed = ['app/Support/Old.php'];
        ksort($files, SORT_STRING);
        $zip = (new ZipBuilder())->dir('app')->dir('storage/app/public');

        foreach ($files as $path => $content) {
            $zip->file((string) $path, $content);
        }

        $zip->file(Manifest::FILE, UpdaterSandbox::manifest($this->next, $files, $removed, '8.3.0', AppVersion::CURRENT))
            ->file('storage/logs/.gitkeep', '')
            ->file(InstallData::PATH, $sql);
        $file = $zip->save($this->box->path('release-install-data/'.AppVersion::updateAsset($this->next)));
        Http::swap(new HttpFactory());
        $this->github = (new ReleaseServer($this->next, $file))->install();
    }
}
