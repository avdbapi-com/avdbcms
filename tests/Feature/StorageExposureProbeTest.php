<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StatusProbeController;
use App\Models\User;
use App\Support\Installer;
use App\Support\StorageExposureProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Security plan S03 part 1: the site checks whether anybody can download the files under storage/. It puts a file with
 * a random name and body in storage/logs, asks itself for /storage/logs/<name>, compares, and deletes the file:
 * "exposed" only for a 200 with exactly that body, "safe" for any other answer that came from this very site (round 3
 * R3-008: the same connection fetches a second random file through the site's own check address), "unknown" when it
 * cannot reach itself or only another server answered (then nothing is shown). Exposed blocks the installer's
 * requirements step and shows in red on the Health check and on Home, with the fix for the panel.
 */
class StorageExposureProbeTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://victim-site.example';

    /** @var list<string> files of the server check folder before the test (another test's or a site's) */
    private array $controlBaseline = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['app.url' => self::SITE]);
        $this->controlBaseline = $this->checkFolderFiles();
    }

    public function test_a_server_that_hands_out_storage_files_is_exposed(): void
    {
        $this->serverServesStorage(true);

        $this->assertSame(StorageExposureProbe::EXPOSED, (new StorageExposureProbe())->probe([self::SITE]));
        $this->assertSame([], glob(storage_path('logs/exposure-check-*')) ?: [], 'the test file is gone again');
        Http::assertSent(fn (HttpRequest $request): bool => preg_match('#^'.preg_quote(self::SITE, '#').'/storage/logs/exposure-check-[0-9a-f]{24}\.txt$#', $request->url()) === 1);
    }

    public function test_a_server_that_answers_404_or_other_content_is_safe(): void
    {
        // This site answers: 404 for the file, and its check address hands out the proof file.
        $this->serverServesStorage(false);
        $this->assertSame(StorageExposureProbe::SAFE, (new StorageExposureProbe())->probe([self::SITE]));
        Http::assertSent(fn (HttpRequest $request): bool => preg_match('#^'.preg_quote(self::SITE, '#').'/_avdbcms/check/clean-urls/[0-9a-f]{32}$#', $request->url()) === 1);
        $this->assertSame([], $this->controlFiles(), 'the proof file is gone again');

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn (HttpRequest $request) => $this->siteAnswer($request, false, Http::response('<html>the home page</html>', 200)));
        $this->assertSame(StorageExposureProbe::SAFE, (new StorageExposureProbe())->probe([self::SITE]), 'a 200 with another body is no proof');
    }

    /**
     * R3-008: before the installation APP_URL is still http://localhost of .env.example. When the site cannot reach its
     * own address, ANOTHER web server answered there (a panel's default site, any server on port 80; with the loopback
     * the same server's default host) and its 404 counted as "Protected", also on an nginx that hands storage/ out. An
     * answer that is not this site's proves nothing: unknown, nothing is shown.
     */
    public function test_an_answer_of_another_server_proves_nothing(): void
    {
        foreach ([
            'a 404 for everything' => fn () => Http::response('Not Found', 404),
            'a 200 page for everything' => fn () => Http::response('<html>Welcome to nginx!</html>', 200),
            'a challenge page' => fn () => Http::response('<html>Checking your browser...</html>', 403),
        ] as $case => $other) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(function (HttpRequest $request) use ($other) {
                if (str_starts_with($request->url(), self::SITE)) {
                    throw new ConnectionException('Connection timed out');
                }

                return $other();
            });

            $this->assertSame(StorageExposureProbe::UNKNOWN, (new StorageExposureProbe())->probe([self::SITE, 'http://localhost']), $case);
            Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://localhost/storage/logs/'));
            $this->assertSame([], $this->controlFiles(), $case.': the proof file is gone again');
            $this->assertSame([], glob(storage_path('logs/exposure-check-*')) ?: [], $case.': the test file is gone again');
        }

        // The same other server can still prove an exposed site: only this site's storage/ holds the random file.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return str_starts_with($path, '/storage/logs/') && is_file($file = storage_path('logs/'.basename($path)))
                ? Http::response((string) file_get_contents($file), 200)
                : Http::response('Not Found', 404);
        });
        $this->assertSame(StorageExposureProbe::EXPOSED, (new StorageExposureProbe())->probe([self::SITE]));
    }

    /** R3-008: the installer asks the address its page is open at, and APP_URL only when it names that same host. */
    public function test_the_installer_asks_its_own_address_and_not_the_placeholder_app_url(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->serverServesStorage(false);
        $installer = new Installer();
        $this->app->instance('request', \Illuminate\Http\Request::create('https://new-site.example/install?step=requirements'));

        $row = collect($installer->requirements())->firstWhere('label', 'Private files are not reachable from the web');

        $this->assertNotNull($row, 'this site answered: the check is shown');
        $this->assertTrue($row['ok']);
        $this->assertSame('Protected', $row['detail']);
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://new-site.example/storage/logs/'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'http://localhost'));

        // Nobody answers at the site's address (the site cannot reach itself): unknown, no row; APP_URL of the same
        // host is asked too.
        Http::swap(new \Illuminate\Http\Client\Factory());
        $asked = [];
        Http::fake(function (HttpRequest $request) use (&$asked) {
            $asked[] = $request->url();

            throw new ConnectionException('Connection timed out');
        });
        config(['app.url' => 'http://new-site.example']);
        $this->assertNull(collect((new Installer())->requirements())->firstWhere('label', 'Private files are not reachable from the web'));
        $this->assertNotEmpty(array_filter($asked, fn (string $url): bool => str_starts_with($url, 'https://new-site.example/storage/logs/')));
        $this->assertNotEmpty(array_filter($asked, fn (string $url): bool => str_starts_with($url, 'http://new-site.example/storage/logs/')));
    }

    public function test_every_address_is_asked_and_one_that_hands_the_file_out_is_enough(): void
    {
        // APP_URL names another server (it answers 404); the address the page came in on hands the file out.
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_starts_with($request->url(), 'http://203.0.113.10/') && is_file($file = storage_path('logs/'.basename($path)))) {
                return Http::response((string) file_get_contents($file), 200);
            }

            return Http::response('Not Found', 404);
        });

        $this->assertSame(StorageExposureProbe::EXPOSED, (new StorageExposureProbe())->probe([self::SITE, 'http://203.0.113.10']));

        // The site's name cannot be reached; its IP address answers and is this very site (its check address hands out
        // the proof): one such answer is enough for safe.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function (HttpRequest $request) {
            if (str_starts_with($request->url(), self::SITE)) {
                throw new ConnectionException('Connection refused');
            }

            return $this->siteAnswer($request, false);
        });

        $this->assertSame(StorageExposureProbe::SAFE, (new StorageExposureProbe())->probe([self::SITE, 'http://203.0.113.10']), 'one answer of this site is enough for safe');
    }

    /**
     * A CDN or firewall in front answers the request to the site's address itself (a 403 "checking your browser" page,
     * a redirect), while the server behind it hands the file out: the same address asked on this machine finds it.
     */
    public function test_an_answer_from_a_firewall_in_front_does_not_hide_an_exposed_server(): void
    {
        foreach ([
            'a challenge page' => Http::response('<html>Checking your browser...</html>', 403),
            'a redirect' => Http::response('', 302, ['Location' => 'https://victim-site.example/login']),
            'a 200 page of its own' => Http::response('<html>Just a moment...</html>', 200),
        ] as $case => $front) {
            Http::swap(new \Illuminate\Http\Client\Factory());
            $fileRequests = 0;
            Http::fake(function (HttpRequest $request) use (&$fileRequests, $front) {
                // The first request for the file goes over the internet (the front answers it, and the proof's requests on
                // that connection), the second to this machine (same URL, another connection).
                if (! str_contains($request->url(), '/storage/logs/') || ++$fileRequests === 1) {
                    return $front;
                }

                $file = storage_path('logs/'.basename((string) parse_url($request->url(), PHP_URL_PATH)));

                return is_file($file) ? Http::response((string) file_get_contents($file), 200) : Http::response('Not Found', 404);
            });

            $this->assertSame(StorageExposureProbe::EXPOSED, (new StorageExposureProbe())->probe([self::SITE]), $case);
            $this->assertSame(2, $fileRequests, $case.': the server itself is asked too');
        }

        // In front and behind both say no, the front passing the site's own answers on: safe (the file's request and the
        // proof's on the first connection).
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->serverServesStorage(false);
        $this->assertSame(StorageExposureProbe::SAFE, (new StorageExposureProbe())->probe([self::SITE]));
        Http::assertSentCount(3);

        // Only the front answers (this machine cannot be reached: the second request for the file, on this machine, gets
        // no answer) and it passes the site's answers on: safe too.
        Http::swap(new \Illuminate\Http\Client\Factory());
        $fileRequests = 0;
        Http::fake(function (HttpRequest $request) use (&$fileRequests) {
            if (str_contains($request->url(), '/storage/logs/') && ++$fileRequests === 2) {
                throw new ConnectionException('Connection refused');
            }

            return $this->siteAnswer($request, false);
        });
        $this->assertSame(StorageExposureProbe::SAFE, (new StorageExposureProbe())->probe([self::SITE]));
        $this->assertSame(2, $fileRequests);

        // Only the front answers, with its own challenge page for every address, the proof's too: nothing is proven
        // (round 3 R3-008), whatever the server behind it does.
        Http::swap(new \Illuminate\Http\Client\Factory());
        $fileRequests = 0;
        Http::fake(function (HttpRequest $request) use (&$fileRequests) {
            if (str_contains($request->url(), '/storage/logs/') && ++$fileRequests === 2) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response('<html>Checking your browser...</html>', 403);
        });
        $this->assertSame(StorageExposureProbe::UNKNOWN, (new StorageExposureProbe())->probe([self::SITE]));
        $this->assertSame([], glob(storage_path('logs/exposure-check-*')) ?: [], 'the test file is gone again');
        $this->assertSame([], $this->controlFiles(), 'the proof file is gone again');
    }

    public function test_no_answer_is_unknown_and_shows_nothing(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertSame(StorageExposureProbe::UNKNOWN, (new StorageExposureProbe())->probe([self::SITE]));
        $this->assertSame([], glob(storage_path('logs/exposure-check-*')) ?: []);
        $this->assertSame(StorageExposureProbe::UNKNOWN, (new StorageExposureProbe())->probe(['not a url']));
    }

    /**
     * R3-008: the proof never delays the requests for the file and costs little where the site cannot answer itself
     * (a busy PHP pool behind a web server that denies storage/ at once): no answer at the clean check address ends that
     * connection's proof (no /index.php request), and no proof request starts later than PROOF_SECONDS after the file's
     * requests.
     */
    public function test_the_proof_costs_little_where_the_site_cannot_answer_itself(): void
    {
        $check = '/'.\App\Support\ServerChecks::PATH.'/';

        $checks = 0;
        Http::fake(function (HttpRequest $request) use (&$checks, $check) {
            if (str_contains($request->url(), $check)) {
                $checks++;

                throw new ConnectionException('Operation timed out');
            }

            return Http::response('Forbidden', 403);
        });
        $this->assertSame(StorageExposureProbe::UNKNOWN, (new StorageExposureProbe())->probe([self::SITE]));
        $this->assertSame(2, $checks, 'one check request per connection that answered');
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/index.php'.$check));

        // Every check request takes its whole timeout (the test's clock): after PROOF_SECONDS no new one starts.
        Http::swap(new \Illuminate\Http\Client\Factory());
        $now = 1000.0;
        $checks = 0;
        Http::fake(function (HttpRequest $request) use (&$checks, &$now, $check) {
            if (str_contains($request->url(), $check)) {
                $checks++;
                $now += StorageExposureProbe::TIMEOUT;
            }

            return Http::response('Not Found', 404);
        });
        $probe = new StorageExposureProbe(function () use (&$now): float {
            return $now;
        });
        $this->assertSame(StorageExposureProbe::UNKNOWN, $probe->probe([self::SITE, 'http://203.0.113.10']));
        $this->assertSame(2, $checks, 'two requests of TIMEOUT seconds each, then the deadline (8 without it)');

        // The proof comes after every request for the file: an exposed second address is found without any proof request.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function (HttpRequest $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_starts_with($request->url(), 'http://203.0.113.10/storage/logs/') && is_file($file = storage_path('logs/'.basename($path)))) {
                return Http::response((string) file_get_contents($file), 200);
            }

            return Http::response('Not Found', 404);
        });
        $this->assertSame(StorageExposureProbe::EXPOSED, (new StorageExposureProbe())->probe([self::SITE, 'http://203.0.113.10']));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), $check));
        $this->assertSame([], $this->controlFiles(), 'the proof file is gone again');
        $this->assertSame([], glob(storage_path('logs/exposure-check-*')) ?: [], 'the test file is gone again');
    }

    public function test_the_installer_blocks_on_an_exposed_server_and_says_how_to_fix_it(): void
    {
        $exposed = $this->installerAnswering(StorageExposureProbe::EXPOSED);
        $row = collect($exposed->requirements())->firstWhere('label', 'Private files are not reachable from the web');

        $this->assertNotNull($row);
        $this->assertFalse($row['ok']);
        $this->assertTrue($row['required']);
        $this->assertStringContainsString(str_replace('\\', '/', base_path('public')), $row['fix']);
        $this->assertStringContainsString('docs/server-configs/nginx-project-root.conf', $row['fix']);
        $this->assertFalse($exposed->requirementsPass());

        $unknown = $this->installerAnswering(StorageExposureProbe::UNKNOWN);
        $this->assertNull(collect($unknown->requirements())->firstWhere('label', 'Private files are not reachable from the web'), 'unknown shows nothing');

        $safe = $this->installerAnswering(StorageExposureProbe::SAFE);
        $this->assertTrue(collect($safe->requirements())->firstWhere('label', 'Private files are not reachable from the web')['ok']);
    }

    public function test_the_health_check_and_home_show_an_exposed_server_in_red(): void
    {
        $this->serverServesStorage(true);
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        $probe = $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => 'storage']))->assertOk();
        $probe->assertJson(['status' => 'danger', 'label' => 'Private files of your site can be downloaded']);
        $this->assertStringContainsString('nginx-project-root.conf', (string) $probe->json('detail'));

        $health = $this->actingAs($admin)->get(route('admin.preflight.index'))->assertOk();
        $health->assertSee('Private files')->assertSee('nginx-project-root.conf');

        $home = (string) $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Private files of your site can be downloaded', $home);

        // One HTTP check a day, whatever the language: the answer is kept.
        $sent = count(Http::recorded());
        StatusProbeController::forget('storage');
        $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => 'storage']))->assertJson(['status' => 'danger']);
        $this->assertSame($sent, count(Http::recorded()));
    }

    public function test_a_safe_server_shows_nothing_on_home(): void
    {
        $this->serverServesStorage(false);
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson(route('admin.status.probe', ['probe' => 'storage']))->assertJson(['status' => 'ok']);
        $this->assertStringNotContainsString('Private files of your site can be downloaded', (string) $this->actingAs($admin)->get('/admin')->getContent());
    }

    /**
     * The fake server is this site: /storage/logs/<file> with the file's real content (exposed) or 404, its own check
     * address answered by the site itself (ServerCheckController).
     */
    private function serverServesStorage(bool $exposed): void
    {
        Http::fake(fn (HttpRequest $request) => $this->siteAnswer($request, $exposed));
    }

    /**
     * This site's answer: its check address through the real route (/_avdbcms/check/..., also behind /index.php), a file
     * under storage/logs when $exposed, else $other (a 404 page).
     */
    private function siteAnswer(HttpRequest $request, bool $exposed, $other = null)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (preg_match('#^(?:/index\.php)?(/_avdbcms/check/.+)$#', $path, $check) === 1) {
            $answer = $this->get($check[1]);

            return Http::response((string) $answer->getContent(), $answer->getStatusCode());
        }

        if ($exposed && str_starts_with($path, '/storage/logs/') && is_file($file = storage_path('logs/'.basename($path)))) {
            return Http::response((string) file_get_contents($file), 200, ['Content-Type' => 'text/plain']);
        }

        return $other ?? Http::response('Not Found', 404);
    }

    /** @return list<string> the proof files the probe left in the server check folder */
    private function controlFiles(): array
    {
        return array_values(array_diff($this->checkFolderFiles(), $this->controlBaseline));
    }

    /** @return list<string> */
    private function checkFolderFiles(): array
    {
        $file = \App\Support\ServerChecks::file(str_repeat('0', 32));

        return $file === null ? [] : (glob(dirname($file).'/*.txt') ?: []);
    }

    private function installerAnswering(string $answer): Installer
    {
        $installer = \Mockery::mock(Installer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $installer->shouldReceive('storageExposure')->andReturn($answer);

        return $installer;
    }
}
