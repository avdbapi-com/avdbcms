<?php

namespace Tests\Feature;

use App\Support\Environment;
use App\Support\SendFile;
use App\Support\ServerChecks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Round 1b R1B-003 and R1B-007: the site checks its own web server over HTTP instead of guessing from its name.
 * App\Support\ServerChecks writes a check file, asks /_avdbcms/check/<check>/<name> (App\Http\Controllers\
 * ServerCheckController) and compares: YES (the file's body came back with 200), NO (answers without it), UNKNOWN (no
 * answer; an earlier YES or NO stays). The answers live in a file of their own (a cache clear keeps them) and count on
 * this server only. Here the site's answers are faked with Http::fake(); the route itself is called like nginx would.
 */
class ServerChecksTest extends TestCase
{
    private string $state;

    private ?string $software;

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->state = storage_path('framework/testing/server-checks-'.bin2hex(random_bytes(4)).'.json');
        ServerChecks::$stateFile = $this->state;
        ServerChecks::reset();
        $this->software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.24.0';
        Environment::reset();
        config(['app.url' => 'https://site.test']);
    }

    protected function tearDown(): void
    {
        @unlink($this->state);
        ServerChecks::$stateFile = null;
        ServerChecks::reset();

        if ($this->software === null) {
            unset($_SERVER['SERVER_SOFTWARE']);
        } else {
            $_SERVER['SERVER_SOFTWARE'] = $this->software;
        }

        Environment::reset();

        parent::tearDown();
    }

    public function test_a_clean_address_that_answers_with_the_check_file_is_a_yes(): void
    {
        $asked = [];
        Http::fake(function (ClientRequest $request) use (&$asked) {
            $asked[] = $request->url();

            return Http::response($this->answerAsTheSiteWould($request->url()), 200);
        });

        $this->assertSame(ServerChecks::YES, ServerChecks::run(ServerChecks::CLEAN_URLS));
        $this->assertMatchesRegularExpression('#^https://site\.test/_avdbcms/check/clean-urls/[a-f0-9]{32}$#', $asked[0], 'a clean address, no /index.php');
        $this->assertSame(ServerChecks::YES, ServerChecks::answer(ServerChecks::CLEAN_URLS));
        $this->assertSame([], glob(Storage::disk('local')->path(ServerChecks::FOLDER).'/*.txt') ?: [], 'the check file is gone');
    }

    public function test_nginx_s_own_404_page_is_a_no(): void
    {
        // A server without rewrite rules: nginx answers the clean address itself (the fleet's nodes 2, 4 and 7).
        Http::fake(fn () => Http::response('<html><center><h1>404 Not Found</h1></center><hr><center>nginx</center></html>', 404));

        $this->assertSame(ServerChecks::NO, ServerChecks::run(ServerChecks::CLEAN_URLS));
        $this->assertStringContainsString('HTTP 404', (string) ServerChecks::entry(ServerChecks::CLEAN_URLS)['detail']);
    }

    public function test_an_error_page_that_shows_the_site_but_keeps_the_404_status_is_a_no(): void
    {
        // nginx's "error_page 404 /index.php": PHP answers, the status stays 404 (search engines drop such pages).
        Http::fake(fn (ClientRequest $request) => Http::response($this->answerAsTheSiteWould($request->url()), 404));

        $this->assertSame(ServerChecks::NO, ServerChecks::run(ServerChecks::CLEAN_URLS));
    }

    public function test_no_answer_at_all_is_unknown_and_never_replaces_an_earlier_answer(): void
    {
        $site = 'down';
        Http::fake(function () use (&$site) {
            return match ($site) {
                'down' => throw new ConnectionException('cURL error 7: Failed to connect'),
                'maintenance' => Http::response('Service Unavailable', 503),
                default => Http::response('Not Found', 404),
            };
        });

        $this->assertSame(ServerChecks::UNKNOWN, ServerChecks::run(ServerChecks::CLEAN_URLS));
        $this->assertFalse(ServerChecks::due(ServerChecks::CLEAN_URLS), 'tried again in an hour');

        ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::YES, 'earlier');
        $this->assertSame(ServerChecks::YES, ServerChecks::run(ServerChecks::CLEAN_URLS), 'a site that cannot reach itself keeps its links');

        // A server error (maintenance mode, an overloaded PHP) says nothing either.
        $site = 'maintenance';
        $this->assertSame(ServerChecks::YES, ServerChecks::run(ServerChecks::CLEAN_URLS));

        // Only a real answer without the file turns it into a NO.
        $site = 'up without rewrite rules';
        $this->assertSame(ServerChecks::NO, ServerChecks::run(ServerChecks::CLEAN_URLS));
    }

    public function test_the_nginx_check_asks_the_index_php_address_and_needs_the_file_from_the_internal_location(): void
    {
        $asked = [];
        $location = true;
        // nginx with the internal location: it answers with the file X-Accel-Redirect named; without it try_files sends
        // the address back to PHP, which answers 404 (no file).
        Http::fake(function (ClientRequest $request) use (&$asked, &$location) {
            $asked[] = $request->url();

            return $location ? Http::response($this->answerAsTheSiteWould($request->url()), 200) : Http::response('', 404);
        });

        $this->assertSame(ServerChecks::YES, ServerChecks::run(ServerChecks::NGINX_STREAM));
        $this->assertMatchesRegularExpression('#^https://site\.test/index\.php/_avdbcms/check/stream/[a-f0-9]{32}$#', $asked[0]);
        $this->assertTrue(ServerChecks::nginxStreamWorks());

        $location = false;
        $this->assertSame(ServerChecks::NO, ServerChecks::run(ServerChecks::NGINX_STREAM));
        $this->assertFalse(ServerChecks::nginxStreamWorks());
    }

    public function test_the_check_route_answers_only_for_a_check_file_that_exists(): void
    {
        $name = bin2hex(random_bytes(16));
        $file = ServerChecks::file($name);
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, 'avdbcms-check-body');

        try {
            $clean = $this->get('/'.ServerChecks::PATH.'/clean-urls/'.$name);
            $clean->assertOk();
            $this->assertSame('avdbcms-check-body', $clean->getContent());
            $this->assertStringContainsString('no-store', (string) $clean->headers->get('Cache-Control'));
            $this->assertSame([], $clean->headers->getCookies(), 'no session, no cookie');

            // stream: the file through nginx's internal location, exactly like a video, with the loop mark.
            $stream = $this->get('/'.ServerChecks::PATH.'/stream/'.$name);
            $stream->assertOk();
            $accel = (string) $stream->headers->get('X-Accel-Redirect');
            $this->assertMatchesRegularExpression('#^/_internal/'.ServerChecks::FOLDER.'/'.$name.'\.txt\?'.SendFile::LOOP_PARAMETER.'=[a-f0-9]{32}$#', $accel);
            $this->assertSame('', $stream->getContent());
            $this->assertStringStartsWith('text/plain', (string) $stream->headers->get('Content-Type'), 'the type of the file, never the default text/html');

            // nginx sent that address back to PHP (no internal location, only try_files): 404, never the file, never a loop.
            parse_str((string) parse_url($accel, PHP_URL_QUERY), $query);
            $this->get('/'.ServerChecks::PATH.'/stream/'.$name.'?'.http_build_query($query))->assertNotFound();
        } finally {
            @unlink($file);
        }

        $this->get('/'.ServerChecks::PATH.'/clean-urls/'.$name)->assertNotFound();
        $this->get('/'.ServerChecks::PATH.'/clean-urls/not-a-check-name')->assertNotFound();
        $this->get('/'.ServerChecks::PATH.'/other/'.$name)->assertNotFound();
        $this->assertNull(ServerChecks::file('../../../.env'));
    }

    public function test_answers_are_kept_per_server_and_checked_again_in_time(): void
    {
        ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::YES, 'test');
        $this->assertFalse(ServerChecks::due(ServerChecks::CLEAN_URLS));

        $state = json_decode((string) file_get_contents($this->state), true);
        $state[ServerChecks::CLEAN_URLS]['checked_at'] = time() - ServerChecks::KEEP_YES_SECONDS - 1;
        file_put_contents($this->state, json_encode($state));
        ServerChecks::reset();
        $this->assertTrue(ServerChecks::due(ServerChecks::CLEAN_URLS), 'a yes is checked again after 10 minutes');
        $this->assertSame(ServerChecks::YES, ServerChecks::answer(ServerChecks::CLEAN_URLS), 'and counts until then');

        ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::NO, 'test');
        $state = json_decode((string) file_get_contents($this->state), true);
        $state[ServerChecks::CLEAN_URLS]['checked_at'] = time() - ServerChecks::RECHECK_ON_CLEAN_ARRIVAL_SECONDS - 1;
        file_put_contents($this->state, json_encode($state));
        ServerChecks::reset();
        $this->assertFalse(ServerChecks::due(ServerChecks::CLEAN_URLS), 'a no waits an hour');
        $this->assertTrue(ServerChecks::due(ServerChecks::CLEAN_URLS, true), '... unless a clean address just reached the site');

        // Another server (a copy moved elsewhere, another web server): no answer.
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        Environment::reset();
        ServerChecks::reset();
        $this->assertNull(ServerChecks::answer(ServerChecks::CLEAN_URLS));

        ServerChecks::forget();
        $this->assertSame([], array_intersect(array_keys(json_decode((string) file_get_contents($this->state), true)), ServerChecks::CHECKS));
    }

    public function test_a_yes_is_checked_again_within_minutes_and_at_once_when_somebody_needed_index_php(): void
    {
        // Round 1b review BWR-01: a yes was kept 6 hours, so a rewrite rule removed later (a panel that wrote the vhost
        // again) left every link on nginx's 404 page for hours. Now 10 minutes, and a request that came in through
        // /index.php (somebody needed that form) makes the site check again when the yes is older than a minute.
        $this->assertLessThanOrEqual(600, ServerChecks::KEEP_YES_SECONDS);
        $this->assertSame(60, ServerChecks::RECHECK_ON_INDEX_PHP_ARRIVAL_SECONDS);

        ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::YES, 'test');
        $this->assertFalse(ServerChecks::due(ServerChecks::CLEAN_URLS, false, true), 'just checked: not again for every /index.php visit');

        $state = json_decode((string) file_get_contents($this->state), true);
        $state[ServerChecks::CLEAN_URLS]['checked_at'] = time() - ServerChecks::RECHECK_ON_INDEX_PHP_ARRIVAL_SECONDS - 1;
        file_put_contents($this->state, json_encode($state));
        ServerChecks::reset();
        $this->assertFalse(ServerChecks::due(ServerChecks::CLEAN_URLS), 'a visit at a clean address waits the 10 minutes');
        $this->assertTrue(ServerChecks::due(ServerChecks::CLEAN_URLS, false, true), 'a visit through /index.php checks again');

        // A NO is not checked again because somebody used /index.php (that is the form it gives anyway).
        ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::NO, 'test');
        $state = json_decode((string) file_get_contents($this->state), true);
        $state[ServerChecks::CLEAN_URLS]['checked_at'] = time() - ServerChecks::RECHECK_ON_INDEX_PHP_ARRIVAL_SECONDS - 1;
        file_put_contents($this->state, json_encode($state));
        ServerChecks::reset();
        $this->assertFalse(ServerChecks::due(ServerChecks::CLEAN_URLS, false, true));
    }

    public function test_a_visit_only_ever_makes_the_site_ask_its_own_address(): void
    {
        // Never the Host header a visitor sends (anybody could make the server ask another address), and nothing while
        // APP_URL is still the example's localhost of an install in progress (round 3 R3-040: an installed site whose
        // address is local is asked: tests/Feature/SubFolderSiteTest covers both).
        $this->assertSame('https://site.test', ServerChecks::siteAddress());
        $installed = $_ENV['APP_INSTALLED'] ?? null;
        $_ENV['APP_INSTALLED'] = 'false';
        ServerChecks::$installLock = storage_path('framework/testing/no-installer-lock-'.bin2hex(random_bytes(4)).'.json');

        try {
            foreach (['http://localhost', 'http://127.0.0.1:8223', 'http://[::1]', '', 'not a url'] as $url) {
                config(['app.url' => $url]);
                $this->assertNull(ServerChecks::siteAddress(), $url);
            }
        } finally {
            if ($installed === null) {
                unset($_ENV['APP_INSTALLED']);
            } else {
                $_ENV['APP_INSTALLED'] = $installed;
            }

            ServerChecks::$installLock = null;
        }

        config(['app.url' => 'http://pub.localhost:8244/']);
        $this->assertSame('http://pub.localhost:8244', ServerChecks::siteAddress());
    }

    public function test_a_web_request_queues_a_due_check_after_its_answer_and_a_console_process_never(): void
    {
        // The test process is a console process: nothing is queued (the scheduler and the tests never ask the site).
        $before = count($this->terminatingCallbacks());
        ServerChecks::runSoon(ServerChecks::CLEAN_URLS);
        ServerChecks::runSoon(ServerChecks::NGINX_STREAM);
        $this->assertCount($before, $this->terminatingCallbacks());
    }

    public function test_a_check_after_a_visit_gives_its_lock_back_and_is_not_made_twice(): void
    {
        // Box (lane BW pass 2, runs/box/p2_stale.txt step C): the lock of a check made after a visit stayed for its whole
        // minute, so a "yes" that went out of date was not checked again by the visits of that minute.
        $asked = 0;
        Http::fake(function (ClientRequest $request) use (&$asked) {
            $asked++;

            return Http::response($this->answerAsTheSiteWould($request->url()), 200);
        });
        $console = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $before = $console->getValue($this->app);
        $console->setValue($this->app, false);

        try {
            $callbacks = count($this->terminatingCallbacks());
            ServerChecks::runSoon(ServerChecks::CLEAN_URLS);
            $this->assertCount($callbacks + 1, $this->terminatingCallbacks(), 'a web request queues the due check');
            $this->runTerminatingCallbacksFrom($callbacks);

            $this->assertSame(1, $asked);
            $this->assertSame(ServerChecks::YES, ServerChecks::answer(ServerChecks::CLEAN_URLS));
            $this->assertFalse(\Illuminate\Support\Facades\Cache::has('avdbcms.server-check.running.'.ServerChecks::CLEAN_URLS), 'the lock is given back');

            // Another visit of the same moment (queued before that answer was written) does not check again.
            $this->ageAnswer(ServerChecks::KEEP_YES_SECONDS + 1);
            ServerChecks::reset();
            $callbacks = count($this->terminatingCallbacks());
            ServerChecks::runSoon(ServerChecks::CLEAN_URLS);
            ServerChecks::record(ServerChecks::CLEAN_URLS, ServerChecks::YES, 'another process checked meanwhile');
            $this->runTerminatingCallbacksFrom($callbacks);
            $this->assertSame(1, $asked);

            // A "yes" older than the 10 minutes is checked again by the next visit (no lock left behind).
            $this->ageAnswer(ServerChecks::KEEP_YES_SECONDS + 1);
            ServerChecks::reset();
            $callbacks = count($this->terminatingCallbacks());
            ServerChecks::runSoon(ServerChecks::CLEAN_URLS);
            $this->runTerminatingCallbacksFrom($callbacks);
            $this->assertSame(2, $asked);
        } finally {
            $console->setValue($this->app, $before);
        }
    }

    private function ageAnswer(int $seconds): void
    {
        $state = json_decode((string) file_get_contents($this->state), true);
        $state[ServerChecks::CLEAN_URLS]['checked_at'] = time() - $seconds;
        file_put_contents($this->state, json_encode($state));
    }

    /** Runs the callbacks queued for after the answer since $from (as the end of a web request would). */
    private function runTerminatingCallbacksFrom(int $from): void
    {
        foreach (array_slice($this->terminatingCallbacks(), $from) as $callback) {
            $callback();
        }
    }

    /** What the site answers for a check address: the check file's body (as the route would, or nginx through it). */
    private function answerAsTheSiteWould(string $url): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $file = ServerChecks::file($name);

        return $file !== null && is_file($file) ? (string) file_get_contents($file) : '';
    }

    /** @return list<mixed> */
    private function terminatingCallbacks(): array
    {
        $property = new \ReflectionProperty($this->app, 'terminatingCallbacks');

        return array_values((array) $property->getValue($this->app));
    }
}
