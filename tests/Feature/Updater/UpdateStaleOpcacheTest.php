<?php

namespace Tests\Feature\Updater;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * H7 (1.2.2): the swap reported OPcache "stale" (it could not drop every moved PHP file from OPcache, and PHP does not
 * reread changed files by their time stamps), so PHP may go on running old files from memory. Finish then clears
 * OPcache again from its own request before it says the update is installed:
 *
 *  - where that works (or OPcache is off for the request), Finish goes on and the admin log says how OPcache ended;
 *  - where PHP rereads changed files after a moment (validate_timestamps on), Finish waits that long;
 *  - where it cannot be cleared (a second PHP whose OPcache API is restricted, time stamps not checked), Finish does not
 *    complete: the plan stays "swapped", the page says plainly to have PHP restarted, nothing is recorded as installed;
 *    after the restart, Continue finishes the update.
 */
class UpdateStaleOpcacheTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const RESTART = 'PHP still keeps old files of AVDBCMS in its memory (OPcache), and they could not be cleared. Ask your host to restart PHP, then click Continue.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();
        $ready = $this->driveToSwap();
        $this->assertSame('swapped', $this->swapThroughHook($ready['token'])['step']);
        $this->changePlan(['settle' => array_replace((array) $this->storedPlan()['settle'], ['opcache' => 'stale', 'wait' => 0])]);
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_finish_clears_opcache_again_and_goes_on_where_that_works(): void
    {
        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertContains($this->storedPlan()['settle']['opcache'], ['off', 'reset', 'invalidated']);
        $this->assertStringContainsString('OPcache was stale after the swap; cleared again: ', implode("\n", $this->updater($this->next)->logLines(null, 500)));
        $files = array_values(array_filter($this->auditRows(), fn (array $row): bool => $row['action'] === 'update.files'))[0];
        $this->assertDoesNotMatchRegularExpression('/OPcache stale/', (string) $files['details']);
    }

    public function test_finish_does_not_complete_while_opcache_cannot_be_cleared_and_says_so(): void
    {
        $answer = $this->finishInAnotherPhp(['opcache.validate_timestamps=0']);

        $this->assertFalse($answer['ok']);
        $this->assertNull($answer['next'], 'the page stops and offers Continue');
        $this->assertSame(self::RESTART, $answer['message']);
        $plan = $this->storedPlan();
        $this->assertSame(['swapped', 'stale', self::RESTART], [$plan['status'], $plan['settle']['opcache'], $plan['last_error']]);
        $this->assertNull($this->updater($this->next)->state(), 'nothing says the update is installed');
        $this->assertNotContains('update.finished', array_column($this->auditRows(), 'action'));
        $this->assertStringContainsString('waiting for PHP to be restarted', implode("\n", $this->updater($this->next)->logLines(null, 500)));

        // PHP restarted (here: a request whose OPcache can be refreshed): Continue finishes the update.
        $done = $this->finishOn($this->updater($this->next));
        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertArrayNotHasKey('last_error', $this->storedPlan());
    }

    public function test_finish_waits_where_php_rereads_changed_files_by_their_time_stamps(): void
    {
        $answer = $this->finishInAnotherPhp(['opcache.validate_timestamps=1', 'opcache.revalidate_freq=2']);

        $this->assertTrue($answer['ok'], (string) $answer['message']);
        $this->assertSame(['finish', 3, 'Waiting for PHP to load the new files...'], [$answer['next'], $answer['retry_after'], $answer['label']]);
        $plan = $this->storedPlan();
        $this->assertSame(['swapped', 'wait', 3], [$plan['status'], $plan['settle']['opcache'], $plan['settle']['wait']]);
        $this->assertNull($this->updater($this->next)->state());
    }

    /**
     * Finish of the new code in a second PHP whose OPcache API is restricted to a folder its script is not in (so
     * opcache_invalidate() and opcache_reset() may not run), with the given extra settings.
     *
     * @param  list<string>  $settings
     * @return array<string, mixed> Finish's answer
     */
    private function finishInAnotherPhp(array $settings): array
    {
        $script = $this->box->path('finish-in-another-php.php');
        file_put_contents($script, <<<'PHP'
            <?php
            // Updater::finish() in a PHP set up by the test (UpdateStaleOpcacheTest); argv: base path, site root, version.
            [, $base, $root, $version] = $argv;
            require $base.'/vendor/autoload.php';
            $app = require $base.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $admin = (new App\Models\User())->forceFill(['id' => 7, 'username' => 'owner']);
            $updater = new App\Support\Updater\Updater(new App\Support\Updater\UpdatePaths($root), new App\Support\Updater\ReleaseCheck(), new App\Support\DatabaseBackup(), new App\Support\Updater\Downloader(), $version);
            echo json_encode(['opcache' => App\Support\Updater\SwapEngine::opcache(), 'answer' => $updater->finish($admin)], JSON_UNESCAPED_SLASHES);
            PHP);

        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_cache=', '-d', 'opcache.restrict_api='.$this->box->path('not-this-script')];

        foreach ($settings as $setting) {
            array_push($command, '-d', $setting);
        }

        $process = new Process([...$command, $script, base_path(), $this->box->root, $this->next], base_path(), [
            'APP_ENV' => 'testing', 'APP_INSTALLED' => 'true', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'CACHE_DRIVER' => 'array', 'LIMITER_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        ], null, 120);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'the second PHP failed: '.$process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim($process->getOutput()), true);
        $this->assertIsArray($result, 'the second PHP answered: '.$process->getOutput());

        if (! $result['opcache']['enabled']) {
            $this->markTestSkipped('This PHP has no OPcache for the command line, so a server whose OPcache cannot be cleared cannot be played here.');
        }

        $this->assertFalse($result['opcache']['invalidate'], 'the OPcache API is restricted there');

        return $result['answer'];
    }
}
