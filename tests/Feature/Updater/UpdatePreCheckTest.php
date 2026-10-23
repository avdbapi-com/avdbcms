<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\PendingMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterSandbox;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * The pre-checks (F8 12, 14, 18 "Pre-checks"): an update is refused, with its reason and before anything changes, when
 * an import is running, an earlier update still waits for Finish update, the owner put the site into Laravel's own
 * maintenance mode, the release needs a newer PHP or a newer installed version, or OPcache keeps old PHP files and may
 * not be cleared while it does not check time stamps either. The checks run again right before the swap.
 *
 * The OPcache case needs a PHP whose OPcache API is restricted (opcache.restrict_api) and set not to check time
 * stamps: a setting PHP takes only at start-up, so that part runs Updater::start() in a second PHP process started with
 * those settings, on the same throwaway site.
 */
class UpdatePreCheckTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const IMPORT = 'An import is running. Wait until it ends or stop it, then try again.';

    private const OPCACHE = 'This server keeps old PHP files in memory and does not let AVDBCMS clear them. Ask your host to restart PHP after a manual update.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public static function runningImports(): array
    {
        return [
            'AVDBAPI import running' => ['avdb_import_runs', 'running'],
            'XVIDAPI import starting' => ['xvid_import_runs', 'starting'],
        ];
    }

    #[DataProvider('runningImports')]
    public function test_a_running_import_refuses_the_update_and_nothing_changes(string $table, string $status): void
    {
        $this->makeSite();
        DB::table($table)->insert(['status' => $status, 'created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(2)]);

        $this->assertRefusedBeforeAnything(self::IMPORT);
    }

    public function test_an_import_that_stopped_answering_or_waits_in_the_queue_does_not_block(): void
    {
        $this->makeSite();
        DB::table('avdb_import_runs')->insert(['status' => 'running', 'created_at' => now()->subHour(), 'updated_at' => now()->subMinutes(11)]);
        DB::table('xvid_import_runs')->insert(['status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('xvid_import_runs')->insert(['status' => 'finished', 'created_at' => now(), 'updated_at' => now()]);

        $answer = $this->updater()->start($this->admin);

        $this->assertTrue($answer['ok'], (string) $answer['message']);
    }

    public function test_an_earlier_update_waiting_for_finish_update_refuses_the_update(): void
    {
        $this->makeSite();
        $this->addPendingMigration();
        $this->assertNotSame([], PendingMigrations::pending());

        $this->assertRefusedBeforeAnything('An earlier update still waits for its database step. Click Finish update on Home first, then try again.');

        // Home > Finish update first: then the update may start.
        $this->assertTrue(PendingMigrations::run()['ok']);
        $this->assertTrue($this->updater()->start($this->admin)['ok']);
    }

    public function test_the_owners_own_maintenance_mode_refuses_the_update_and_stays_as_it_is(): void
    {
        $this->makeSite();
        $down = (string) json_encode(['except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'secret' => 'owners-bypass', 'status' => 503, 'template' => null]);
        File::ensureDirectoryExists(dirname($this->box->paths()->down()));
        file_put_contents($this->box->paths()->down(), $down);

        $answer = $this->updater()->start($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame('The site is in Laravel maintenance mode (artisan down). Bring it back up, then try again.', $answer['message']);
        $this->assertSame($down, file_get_contents($this->box->paths()->down()), 'the owner\'s down file is not touched');
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertNull($this->storedPlan());
        $this->assertSame($this->before, $this->box->snapshot());
    }

    public static function releaseRequirements(): array
    {
        return [
            'a newer PHP' => [['requires_php' => '99.0.0'], fn (string $next): string => 'AVDBCMS '.$next.' needs PHP 99.0.0 or newer, and this server runs PHP '.PHP_VERSION
                .'. Ask your host to switch the site to a newer PHP, then try again.'],
            'a newer installed version' => [['min_from' => 'next'], fn (string $next): string => 'AVDBCMS '.$next.' can only be installed over '.$next
                .' or newer, and this site has '.AppVersion::CURRENT.'. Install '.$next.' first.'],
        ];
    }

    #[DataProvider('releaseRequirements')]
    public function test_a_release_this_site_cannot_run_is_refused_before_anything_is_unpacked(array $manifest, \Closure $message): void
    {
        $this->makeSite(manifest: array_map(fn (string $value): string => $value === 'next' ? $this->nextOfCurrent() : $value, $manifest));
        $this->assertTrue($this->updater()->start($this->admin)['ok']);
        $this->assertSame('verifying', $this->updater()->step($this->admin)['status']);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertFalse($answer['retry'], 'trying again cannot help');
        $this->assertSame(['failed', $message($this->next)], [$answer['status'], $answer['message']]);
        $this->assertSame(['update.log'], $this->jobFiles(), 'the zip is deleted, nothing was unpacked');
        $this->assertSame(['update.started', 'update.failed'], array_column($this->auditRows(), 'action'));
        $this->assertSame('verify: '.$message($this->next), $this->auditRows()[1]['details']);
        $this->assertSiteUnchanged();
    }

    public function test_the_checks_run_again_right_before_the_swap(): void
    {
        $this->makeSite();
        $this->updater()->start($this->admin);
        $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'preparing');
        // The live files are compared; the rest of the preparation starts in a request of its own (a whole budget).
        $compared = $this->updater()->step($this->admin);
        $this->assertTrue($compared['ok'], (string) $compared['message']);
        $this->assertSame(['preparing', 'step'], [$compared['status'], $compared['next']]);
        $this->assertSame($compared['progress']['total'], $compared['progress']['done']);
        $this->assertFileExists($this->jobFolder().'/live.json');
        // An import starts while the update downloads, backs up and compares.
        $run = DB::table('avdb_import_runs')->insertGetId(['status' => 'running', 'created_at' => now(), 'updated_at' => now()]);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame(['preparing', self::IMPORT], [$answer['status'], $answer['message']]);
        $this->assertSame([], glob($this->box->paths()->backups('*')) ?: [], 'no file backup is left behind');
        $this->assertFileDoesNotExist($this->jobFolder().'/live.json', 'the live files are hashed again next time');
        $this->assertSiteUnchanged('before the swap');

        // The import ends: the next steps compare the files again and make the swap ready.
        DB::table('avdb_import_runs')->where('id', $run)->update(['status' => 'finished', 'updated_at' => now()]);
        $ready = $this->stepUntil($this->updater(), fn (array $a): bool => $a['next'] !== 'step');
        $this->assertSame('swap', $ready['next'], (string) $ready['message']);
    }

    public function test_a_folder_php_may_not_write_to_refuses_the_update_before_the_swap(): void
    {
        $this->makeSite();
        $this->updater()->start($this->admin);
        $this->assertSame('preparing', $this->stepUntil($this->updater(), fn (array $a): bool => $a['status'] === 'preparing')['status']);
        // The comparison of the live files only reads; the write test comes with the step after it.
        $this->assertSame('step', $this->updater()->step($this->admin)['next']);
        $folder = $this->box->root.'/config';
        $this->denyWrites($folder);

        try {
            $answer = $this->updater()->step($this->admin);
        } finally {
            $this->allowWrites($folder);
        }

        $this->assertFalse($answer['ok']);
        $this->assertTrue($answer['retry']);
        $this->assertSame(['preparing', 'AVDBCMS cannot write to config. Ask your host to give PHP write access to the site files, then try again.'], [$answer['status'], $answer['message']]);
        $this->assertSame([], glob($this->box->paths()->backups('*')) ?: [], 'no file backup is left behind');
        $this->assertSiteUnchanged('by a refused update');
        $this->assertSame([], glob($folder.'/.avdbcms-write-test-*') ?: [], 'no test file is left in a folder');

        // The host fixed the permissions: the next steps (the files compared again) make the swap ready.
        $this->assertSame('swap', $this->stepUntil($this->updater(), fn (array $a): bool => $a['next'] !== 'step')['next']);
    }

    public function test_opcache_that_cannot_be_cleared_and_does_not_check_time_stamps_refuses_the_update(): void
    {
        $this->makeSite();

        $refused = $this->startInAnotherPhp(['opcache.validate_timestamps=0', 'opcache.revalidate_freq=7']);

        $this->assertSame(['enabled' => true, 'invalidate' => false, 'reset' => false, 'validate_timestamps' => false, 'revalidate_freq' => 7], $refused['opcache']);
        $this->assertFalse($refused['answer']['ok']);
        $this->assertSame(self::OPCACHE, $refused['answer']['message']);
        $this->assertFalse($refused['plan'], 'no plan');
        $this->assertSame(1, $refused['requests'], 'only the release check was asked; nothing downloaded');
        $this->assertSiteUnchanged();

        // Where OPcache checks time stamps, the update may go on: the swap then waits revalidate_freq + 1 seconds.
        $allowed = $this->startInAnotherPhp(['opcache.validate_timestamps=1', 'opcache.revalidate_freq=7']);

        $this->assertTrue($allowed['answer']['ok'], (string) $allowed['answer']['message']);
        $this->assertSame('downloading', $allowed['answer']['status']);
        $this->assertSame(['wait' => 8, 'opcache' => 'wait'], array_intersect_key($allowed['settle'], ['wait' => 1, 'opcache' => 1]));
    }

    public function test_opcache_counts_as_on_when_only_its_status_function_is_disabled(): void
    {
        // Shared hosts often disable opcache_get_status() (it lists other accounts' scripts) while OPcache runs: taking
        // that for "OPcache off" let a site that never rereads PHP files mix old and new code after the swap.
        $this->makeSite();

        $refused = $this->startInAnotherPhp(['opcache.validate_timestamps=0', 'opcache.revalidate_freq=7', 'disable_functions=opcache_get_status']);

        $this->assertSame(['enabled' => true, 'invalidate' => false, 'reset' => false, 'validate_timestamps' => false, 'revalidate_freq' => 7], $refused['opcache']);
        $this->assertFalse($refused['answer']['ok']);
        $this->assertSame(self::OPCACHE, $refused['answer']['message']);
        $this->assertFalse($refused['plan'], 'no plan');
        $this->assertSiteUnchanged();
    }

    public function test_a_host_without_set_time_limit_disk_free_space_or_chmod_can_start_an_update(): void
    {
        // Since PHP 8 a disabled function does not exist (a call throws): the space check and the time budget do
        // without them (the writes check themselves), instead of every start answering "The update stopped".
        $this->makeSite();

        $started = $this->startInAnotherPhp(['opcache.validate_timestamps=1', 'disable_functions=set_time_limit,ignore_user_abort,disk_free_space,chmod,touch']);

        $this->assertTrue($started['answer']['ok'], (string) $started['answer']['message']);
        $this->assertSame(['downloading', 'step'], [$started['answer']['status'], $started['answer']['next']]);
        $this->assertTrue($started['plan']);
    }

    /* ----------------------------------------------------------------- helpers */

    /** The update is refused with $message, and nothing happened: no plan, no work folder, no asset asked for. */
    private function assertRefusedBeforeAnything(string $message): void
    {
        $answer = $this->updater()->start($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame($message, $answer['message']);
        $this->assertNull($answer['next']);
        $this->assertNull($this->storedPlan());
        $this->assertDirectoryDoesNotExist($this->jobFolder());
        $this->assertSame([], $this->github->requestsTo(AppVersion::downloadBase($this->next)), 'nothing was downloaded');
        $this->assertSame([], $this->auditRows());
        $this->assertSiteUnchanged();
    }

    /** The version after CURRENT (makeSite() has not set $this->next yet when the release is described). */
    private function nextOfCurrent(): string
    {
        return UpdaterSandbox::nextVersion();
    }

    /** A migration of "the uploaded version" that has not run: Home shows Finish update for it. */
    private function addPendingMigration(): void
    {
        $folder = $this->box->path('migrations');
        File::ensureDirectoryExists($folder);
        File::put($folder.'/2099_02_02_000000_create_updater_precheck_marker_table.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('updater_precheck_marker', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('updater_precheck_marker');
                }
            };
            PHP);
        app('migrator')->path($folder);
        PendingMigrations::forget();
    }

    /**
     * Updater::start() on this site in a second PHP process whose OPcache API is restricted to a folder this script is
     * not in (so opcache_invalidate() and opcache_reset() may not run), with the given extra settings. The release check
     * is faked there the same way (the next version, both update assets).
     *
     * @param  list<string>  $settings  ini settings ("name=value")
     * @return array{opcache: array<string, mixed>, answer: array<string, mixed>, plan: bool, requests: int, settle: array<string, mixed>}
     */
    private function startInAnotherPhp(array $settings): array
    {
        $script = $this->box->path('start-in-another-php.php');
        file_put_contents($script, <<<'PHP'
            <?php
            // Updater::start() in a PHP set up by the test (lane F, UpdatePreCheckTest); argv: base path, site root, release JSON file.
            [, $base, $root, $releaseFile] = $argv;
            require $base.'/vendor/autoload.php';
            $app = require $base.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            config(['admin.operational_tools_enabled' => true, 'app.url' => 'http://localhost']);
            $release = json_decode((string) file_get_contents($releaseFile), true);
            Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response($release, 200));
            $admin = (new App\Models\User())->forceFill(['id' => 7, 'username' => 'owner']);
            $database = new class extends App\Support\DatabaseBackup
            {
                public function size(): ?int
                {
                    return 4096;
                }

                public function recent(int $hours = 24): ?array
                {
                    return null;
                }
            };
            $paths = new App\Support\Updater\UpdatePaths($root);
            $answer = (new App\Support\Updater\Updater($paths, new App\Support\Updater\ReleaseCheck(), $database))->start($admin);
            $settle = (new App\Support\Updater\SwapEngine(['root' => $root, 'source' => $root, 'backup' => $root, 'journal' => $root.'/none.jsonl', 'ops' => []]))->settle();
            echo json_encode([
                'opcache' => App\Support\Updater\SwapEngine::opcache(),
                'answer' => $answer,
                'plan' => is_file($paths->plan()),
                'requests' => count(Illuminate\Support\Facades\Http::recorded()),
                'settle' => $settle,
            ], JSON_UNESCAPED_SLASHES);
            PHP);
        file_put_contents($this->box->path('release.json'), json_encode($this->github->releaseJson()));

        // No on-disk OPcache file cache: with validate_timestamps=0 a php.ini opcache.file_cache would hand the second PHP
        // the code compiled before the last change of the site's files (seen after the AVDBCMS rename: old texts).
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_cache=', '-d', 'opcache.restrict_api='.$this->box->path('not-this-script')];

        foreach ($settings as $setting) {
            array_push($command, '-d', $setting);
        }

        $process = new Process([...$command, $script, base_path(), $this->box->root, $this->box->path('release.json')], base_path(), [
            'APP_ENV' => 'testing', 'APP_INSTALLED' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'CACHE_DRIVER' => 'array', 'LIMITER_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        ], null, 120);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'the second PHP failed: '.$process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($result, 'the second PHP answered: '.$process->getOutput());

        if (! $result['opcache']['enabled']) {
            $this->markTestSkipped('This PHP has no OPcache for the command line, so a host whose OPcache cannot be cleared cannot be played here.');
        }

        // The plan the second PHP wrote belongs to that run only.
        File::deleteDirectory($this->box->paths()->work());

        return $result;
    }
}
