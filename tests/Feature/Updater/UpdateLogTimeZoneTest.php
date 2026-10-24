<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\PlanStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * One time zone in the step log (1.2.1, B2 of the 1.2.0 tests): the maintenance hook runs without Laravel, so PHP used
 * php.ini's date.timezone for the lines of the swap while every other line used the site's time zone ("15:10:26 swap
 * the site is in maintenance", then "16:10:26 swap caches cleared"). The hook's config now carries the site's time
 * zone, and the hook and its SwapEngine write their lines (and the time of a failure) in it.
 */
class UpdateLogTimeZoneTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    /** The site's Time zone setting: UTC+14. */
    private const SITE = 'Pacific/Kiritimati';

    /** php.ini's date.timezone on the host: UTC-11, a day behind the site. */
    private const PHP_INI = 'Pacific/Pago_Pago';

    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->zone = date_default_timezone_get();
        // What AppServiceProvider does with the site's Time zone setting on every request.
        config(['app.timezone' => self::SITE]);
        date_default_timezone_set(self::SITE);
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
        date_default_timezone_set($this->zone);

        parent::tearDown();
    }

    public function test_the_swap_lines_of_the_hook_are_in_the_sites_time_zone_whatever_php_ini_says(): void
    {
        $ready = $this->driveToSwap();
        $this->assertStringContainsString("'timezone' => '".self::SITE."'", (string) file_get_contents($this->box->paths()->hook()));

        // The page's swap requests reach the hook in a PHP without Laravel, whose php.ini names another time zone.
        $runner = $this->box->path('swap-request.php');
        file_put_contents($runner, "<?php\n"
            ."\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_SERVER['HTTP_HOST'] = 'localhost';\n"
            ."\$_SERVER['HTTP_ORIGIN'] = 'http://localhost';\n\$_SERVER['HTTP_ACCEPT'] = 'application/json';\n"
            ."\$_POST = ['avdbcms_update' => 'swap', 'token' => \$argv[2]];\n"
            ."require \$argv[1];\necho 'PASSED THROUGH';\n");
        $answer = null;

        for ($i = 0; $i < 10 && ($answer['next'] ?? 'swap') === 'swap'; $i++) {
            $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'date.timezone='.self::PHP_INI, $runner, $this->box->paths()->hook(), $ready['token']], $this->box->base);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $answer = json_decode($process->getOutput(), true);
            $this->assertIsArray($answer, $process->getOutput());
        }

        $this->assertSame('swapped', $answer['step']);
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);

        $lines = $this->updater($this->next)->logLines(null, 500);
        $hookLines = array_values(array_filter($lines, fn (string $line): bool => str_contains($line, "\tswap\tcaches cleared") || str_contains($line, "\tswap\tall ")));
        $this->assertCount(2, $hookLines, implode("\n", $lines));
        $now = new DateTimeImmutable('now', new DateTimeZone(self::SITE));

        foreach ($lines as $line) {
            $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($line, 0, 19), new DateTimeZone(self::SITE));
            $this->assertNotFalse($at, $line);
            $this->assertLessThan(600, abs($now->getTimestamp() - $at->getTimestamp()), 'a line in another time zone: '.$line);
        }
    }

    public function test_the_time_of_a_failed_swap_is_in_the_sites_time_zone(): void
    {
        $ready = $this->driveToSwap();
        file_put_contents($this->jobFolder().'/staging/config/app.php', '<?php // damaged');

        // The hook as a host without Laravel runs it: php.ini's zone is PHP's default there.
        date_default_timezone_set(self::PHP_INI);

        try {
            $this->assertSame('failed', $this->swapThroughHook($ready['token'])['step']);
        } finally {
            date_default_timezone_set(self::SITE);
        }

        $failure = PlanStore::read($this->box->paths()->plan())['failure'];
        $this->assertStringEndsWith('+14:00', $failure['at']);
        $this->assertStringContainsString("\tswap\ta move failed: The new copy of config/app.php is missing or damaged.", implode("\n", $this->updater()->logLines()));

        foreach ($this->updater()->logLines() as $line) {
            $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($line, 0, 19), new DateTimeZone(self::SITE));
            $this->assertLessThan(600, abs(time() - $at->getTimestamp()), 'a line in another time zone: '.$line);
        }
    }
}
