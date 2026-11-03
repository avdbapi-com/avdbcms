<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Support\PhpBinary;
use App\Support\SafeCache;
use Tests\TestCase;

/**
 * CLF-SCHED-01 follow-up: PhpBinary prefers a real command-line (CLI) build for the cron line, the site's own scheduler
 * runs and the queue worker. A non-CLI build (LiteSpeed's lsphp, a CGI build) stays usable as a last choice -- artisan
 * runs under it -- but its output starts with the shebang line a cron line then logs, so a CLI build must win when one
 * is there. The "php" status probe shows which build was chosen (its PHP_SAPI).
 */
class PhpBinaryCliPreferenceTest extends TestCase
{
    protected function tearDown(): void
    {
        PhpBinary::forget();

        parent::tearDown();
    }

    public function test_this_php_reports_its_sapi_as_cli(): void
    {
        // The tests run under a command-line PHP: the probe reads its SAPI without even running a child process.
        $this->assertSame('cli', PhpBinary::sapiOf((string) PHP_BINARY));
        $this->assertNull(PhpBinary::sapiOf(''), 'an empty path has no SAPI');
    }

    public function test_a_cli_build_outranks_a_non_cli_build_with_the_same_version_and_memory(): void
    {
        $key = new \ReflectionMethod(PhpBinary::class, 'key');
        $signature = new \ReflectionMethod(PhpBinary::class, 'signature');
        $score = new \ReflectionMethod(PhpBinary::class, 'candidateScore');

        PhpBinary::forget();

        $cli = '/nonexistent/avd-r6up-'.bin2hex(random_bytes(4)).'/php';
        $lsphp = '/nonexistent/avd-r6up-'.bin2hex(random_bytes(4)).'/lsphp';

        // Both builds: same version and memory limit; only the SAPI differs. (Seeded so no child process is run.)
        foreach ([[$cli, 'cli'], [$lsphp, 'litespeed']] as [$path, $sapi]) {
            $sig = $signature->invoke(null, $path);
            SafeCache::put($key->invoke(null, 'version', [$sig]), ['v' => 80330], 600);
            SafeCache::put($key->invoke(null, 'memory', [$sig]), ['v' => 512 * 1024 * 1024], 600);
            SafeCache::put($key->invoke(null, 'sapi', [$sig]), ['v' => $sapi], 600);
        }

        $this->assertGreaterThan(
            (int) $score->invoke(null, $lsphp),
            (int) $score->invoke(null, $cli),
            'a CLI build scores above a non-CLI build of the same version and memory (CLF-SCHED-01)'
        );
    }

    public function test_the_status_probe_shows_the_chosen_build_s_sapi(): void
    {
        $probe = StatusProbeController::result('php', true);

        $this->assertArrayHasKey('sapi', $probe['data'] ?? [], 'the php probe reports which build it chose');

        if ($probe['data']['usable'] ?? false) {
            // In this CLI test run the resolved binary is a command-line build.
            $this->assertSame('cli', $probe['data']['sapi'], 'the chosen build is the CLI one');
            $this->assertNull($probe['detail'] ?? null, 'a CLI build needs no non-CLI warning');
        }
    }
}
