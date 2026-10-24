<?php

namespace Tests\Feature\Updater;

use App\Support\Updater\HookWriter;
use App\Support\Updater\MaintenanceHook;
use App\Support\Updater\PlanStore;
use App\Support\Updater\SwapEngine;
use App\Support\Updater\Updater;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * Requests that reach the maintenance hook while the files are swapped (F8 11, 15, 18 "Maintenance hook"): the
 * generated storage/framework/maintenance.php, loaded the way public/index.php loads it (before vendor/autoload.php).
 *
 *  - a visitor (any method, any address, with or without a wrong update cookie) gets 503 "This site is being updated.
 *    Please come back in a minute." with Retry-After: 60, as a page or as JSON, and nothing moves;
 *  - a swap POST without the token, with a wrong, malformed or expired token, or not from this site (a foreign or
 *    "null" Origin, no Origin at all, Sec-Fetch-Site cross-site or same-site) gets the same 503 and moves nothing;
 *  - the page's own swap POST runs the swap (also when the hook file runs in a PHP process without Laravel);
 *  - a swap POST while another step holds the update lock answers 409 busy and moves nothing;
 *  - a hook whose swap is over steps aside and deletes itself;
 *  - a swap whose every move ended goes on to Finish although the hook could not save that (a full disk or quota),
 *    without the site going down again; a swap that has to go back is put back even when every move ended.
 */
class MaintenanceHookRequestTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const VISITOR = 'This site is being updated. Please come back in a minute.';

    /** @var array{token: string, cookie: string} the secrets the page got when the swap was made ready */
    private array $ready;

    /** @var array<string, string> the site right before the swap (the new built assets are in already) */
    private array $beforeSwap;

    /** The journal right before the swap: only the moves made before the site went down (the new built assets). */
    private string $journalBeforeSwap;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->makeSite();
        $ready = $this->driveToSwap();
        $this->ready = ['token' => $ready['token'], 'cookie' => $ready['cookie']];
        $this->beforeSwap = $this->box->snapshot();
        $this->journalBeforeSwap = (string) @file_get_contents($this->jobFolder().'/journal.jsonl');
    }

    protected function tearDown(): void
    {
        $this->removeSite();

        parent::tearDown();
    }

    public function test_a_visitor_gets_the_being_updated_page_with_retry_after_and_nothing_moves(): void
    {
        $page = $this->hookRequest(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/videos/123', 'HTTP_ACCEPT' => 'text/html']);

        $this->assertSame(503, $page['status']);
        $this->assertSame('60', $page['headers']['Retry-After']);
        $this->assertSame('text/html; charset=utf-8', $page['headers']['Content-Type']);
        $this->assertStringContainsString('no-store', $page['headers']['Cache-Control']);
        $this->assertSame('noindex', $page['headers']['X-Robots-Tag']);
        $this->assertStringContainsString('<p>'.self::VISITOR.'</p>', $page['body']);
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $page['body']);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $page['body']);
        $this->assertStringContainsString('<title>'.e((string) config('app.name')).'</title>', $page['body']);
        // A page of its own: no Laravel, no /build assets, no secrets, no form.
        foreach (['/build/', '<link', '<script', '<form', $this->ready['token'], $this->ready['cookie'], $this->storedPlan()['id']] as $absent) {
            $this->assertStringNotContainsString($absent, $page['body']);
        }

        // As JSON for a script; for HEAD; for an ordinary form; for a cookie that is not the update's.
        $json = $this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame([503, '60', ['message' => self::VISITOR]], [$json['status'], $json['headers']['Retry-After'], $json['json']]);
        $this->assertSame(503, $this->hookRequest(['REQUEST_METHOD' => 'HEAD'])['status']);
        $this->assertSame(503, $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost'], ['email' => 'someone@example.com', 'password' => 'x'])['status']);
        $this->assertSame(503, $this->hookRequest(['REQUEST_METHOD' => 'GET'], [], [Updater::COOKIE => str_repeat('0', 64)])['status']);
        $this->assertSame(503, $this->hookRequest(['REQUEST_METHOD' => 'GET'], [], [Updater::COOKIE => $this->ready['token']])['status'], 'the token is not the cookie');

        $this->assertNothingMoved();
    }

    public static function refusedSwapRequests(): array
    {
        $same = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost'];

        return [
            'no token' => [fn (string $token): array => [$same, ['avdbcms_update' => 'swap']]],
            'a wrong token' => [fn (string $token): array => [$same, ['avdbcms_update' => 'swap', 'token' => str_repeat('a', 64)]]],
            'a malformed token' => [fn (string $token): array => [$same, ['avdbcms_update' => 'swap', 'token' => substr($token, 0, 63)]]],
            'the token in capitals' => [fn (string $token): array => [$same, ['avdbcms_update' => 'swap', 'token' => strtoupper($token)]]],
            'the token as a list' => [fn (string $token): array => [$same, ['avdbcms_update' => 'swap', 'token' => [$token]]]],
            'another site' => [fn (string $token): array => [['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://evil.example'], ['avdbcms_update' => 'swap', 'token' => $token]]],
            'an Origin of null' => [fn (string $token): array => [['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'null'], ['avdbcms_update' => 'swap', 'token' => $token]]],
            'another port' => [fn (string $token): array => [['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost:8080'], ['avdbcms_update' => 'swap', 'token' => $token]]],
            'no Origin at all' => [fn (string $token): array => [['REQUEST_METHOD' => 'POST'], ['avdbcms_update' => 'swap', 'token' => $token]]],
            'Sec-Fetch-Site cross-site' => [fn (string $token): array => [$same + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], ['avdbcms_update' => 'swap', 'token' => $token]]],
            'Sec-Fetch-Site same-site' => [fn (string $token): array => [$same + ['HTTP_SEC_FETCH_SITE' => 'same-site'], ['avdbcms_update' => 'swap', 'token' => $token]]],
            'a GET' => [fn (string $token): array => [['REQUEST_METHOD' => 'GET', 'HTTP_ORIGIN' => 'http://localhost'], ['avdbcms_update' => 'swap', 'token' => $token]]],
        ];
    }

    #[DataProvider('refusedSwapRequests')]
    public function test_a_swap_request_without_the_token_or_from_another_site_gets_the_visitors_page_and_moves_nothing(Closure $request): void
    {
        [$server, $post] = $request($this->ready['token']);

        foreach (['text/html', 'application/json'] as $accept) {
            $answer = $this->hookRequest($server + ['HTTP_ACCEPT' => $accept], $post);

            $this->assertSame(503, $answer['status'], $accept);
            $this->assertSame('60', $answer['headers']['Retry-After']);
            $this->assertStringContainsString($accept === 'text/html' ? '<p>'.self::VISITOR.'</p>' : json_encode(['message' => self::VISITOR]), $answer['body']);
        }

        $this->assertNothingMoved();
    }

    public function test_an_expired_swap_token_is_refused_once_the_swap_began(): void
    {
        // The first move of the swap is done (a request that was cut off after it): from now on only the token or the
        // administrator's page may go on.
        $job = (array) PlanStore::read($this->jobFolder().'/job.json');
        $ops = (new SwapEngine($job))->ops();
        $first = array_search('main', array_column($ops, 'phase'), true);
        (new SwapEngine(['ops' => array_slice($ops, 0, $first + 1)] + $job))->forward(INF);
        $began = $this->box->snapshot();
        $journal = (string) file_get_contents($this->jobFolder().'/journal.jsonl');
        $this->expireToken();

        $answer = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
            ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]);

        $this->assertSame(503, $answer['status']);
        $this->assertSame(503, $this->hookRequest(['REQUEST_METHOD' => 'GET'])['status'], 'visitors still wait');
        $this->assertSame($began, $this->box->snapshot(), 'nothing moved');
        $this->assertSame($journal, (string) file_get_contents($this->jobFolder().'/journal.jsonl'));
        $this->assertSame('swapping', $this->storedPlan()['status']);
        $this->assertFileExists($this->box->paths()->hook());
    }

    public function test_a_swap_token_that_ran_out_before_the_swap_began_takes_the_maintenance_page_away(): void
    {
        // The answer that carried the token never arrived (a closed tab, a timeout): nobody can start the swap, and
        // without this the site would show "being updated" until somebody deletes the hook by hand.
        $this->expireToken();
        $cookie = $this->storedPlan()['cookie'];

        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'GET']), 'the visitor gets the site again');

        // The site is up (Laravel's down file is gone); the hook file stays only for a Finish / Undo click of the page it
        // may have shown before (1.2.2, C2): every other request goes through it to the site.
        $this->assertFileDoesNotExist($this->box->paths()->down());
        $this->assertFileExists($this->box->paths()->hook());
        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'text/html']));
        $this->assertNull($this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json'], ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]));
        $plan = $this->storedPlan();
        $this->assertSame('preparing', $plan['status']);
        $this->assertIsInt($plan['lapsed_at']);
        $this->assertArrayNotHasKey('swap', $plan);
        $this->assertSame($cookie, $plan['cookie'], 'the administrator keeps the cookie the start handed out');
        $this->assertFileDoesNotExist($this->jobFolder().'/live.json', 'the live files are compared again');
        $this->assertSame($this->beforeSwap, $this->box->snapshot(), 'no file of the site moved (only the new built assets were in)');
        $this->assertStringContainsString('the swap token ran out before the swap began', implode("\n", $this->updater()->logLines()));

        // The page's next step prepares the swap again: a new hook, a new token; the old token is worth nothing.
        $again = $this->stepUntil($this->updater(), fn (array $a): bool => $a['next'] !== 'step');
        $this->assertSame('swap', $again['next']);
        $this->assertNotSame($this->ready['token'], $again['token']);
        $this->assertArrayNotHasKey('lapsed_at', $this->storedPlan(), 'the new hook is not a standing-by one');
        $old = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
            ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]);
        $this->assertSame(503, $old['status']);
        $this->assertSame('swapped', $this->swapThroughHook($again['token'])['step']);
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
        $this->assertSame(1, count(glob($this->box->paths()->backups('*')) ?: []), 'the backup folder of the first try went');
    }

    public static function choicesAfterTheToken(): array
    {
        return [
            'Finish the update' => ['finish', 'The new files are in place', 'swapped', null, '2'],
            'Undo the update' => ['undo', 'The update was stopped', 'failed', MaintenanceHook::STOPPED, '1'],
        ];
    }

    #[DataProvider('choicesAfterTheToken')]
    public function test_the_administrators_choice_on_the_hook_page_still_runs_after_the_token_ran_out(string $action, string $title, string $status, ?string $reason, string $index): void
    {
        // The administrator's page was shown before the swap token ran out and the click comes after it. The safety
        // net that brings the site back when nobody can go on never overrules a choice somebody made: without this the
        // hook stood down, the click reached an address that takes no POST, and Tools > Updates then went on with
        // the update the administrator had asked to undo.
        $cookies = [Updater::COOKIE => $this->ready['cookie']];
        $page = $this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'text/html'], [], $cookies);
        $this->assertSame(1, preg_match('/name="form_token" value="([0-9a-f]{64})"/', $page['body'], $match));
        $this->expireToken();

        $answer = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'text/html'],
            ['avdbcms_update' => $action, 'form_token' => $match[1]], $cookies);

        $this->assertSame(200, $answer['status']);
        $this->assertStringContainsString('<h1>'.$title.'</h1>', $answer['body']);
        $this->assertSame($status, $this->storedPlan()['status']);
        $this->assertSame($reason, $this->storedPlan()['failure']['reason'] ?? null);
        $this->assertSame("<?php // public/index.php {$index}\n", $this->box->read('public/index.php'));
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());
    }

    public static function pageRequests(): array
    {
        return [
            'Origin of the site' => [['HTTP_ORIGIN' => 'http://localhost']],
            'Origin with the default port' => [['HTTP_ORIGIN' => 'http://localhost:80']],
            'Sec-Fetch-Site same-origin, no Origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']],
        ];
    }

    #[DataProvider('pageRequests')]
    public function test_the_pages_own_swap_request_runs_the_swap(array $headers): void
    {
        $answer = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json'] + $headers,
            ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame('application/json; charset=utf-8', $answer['headers']['Content-Type']);
        $this->assertSame(['ok' => true, 'step' => 'swapped', 'next' => 'finish'], array_intersect_key($answer['json'], array_flip(['ok', 'step', 'next'])));
        $this->assertSame($answer['json']['total'], $answer['json']['done']);
        $this->assertSame('The new files are in place.', $answer['json']['message']);
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());
        $this->assertSame(self::releaseFiles()['public/index.php'], $this->box->read('public/index.php'));
        $this->assertSame('swapped', $this->storedPlan()['status']);

        // The site answers again: the hook is gone, so the next request is Laravel's (the new code finishes).
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
    }

    /** @param  list<string>  $settings  PHP's start-up settings of the host */
    #[DataProvider('hostPhps')]
    public function test_the_hook_file_runs_the_swap_in_a_php_process_without_laravel(array $settings): void
    {
        $runner = $this->box->path('swap-request.php');
        file_put_contents($runner, "<?php\n"
            ."\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_SERVER['HTTP_HOST'] = 'localhost';\n"
            ."\$_SERVER['HTTP_ORIGIN'] = 'http://localhost';\n\$_SERVER['HTTP_ACCEPT'] = 'application/json';\n"
            ."\$_POST = ['avdbcms_update' => 'swap', 'token' => \$argv[2]];\n"
            ."require \$argv[1];\necho 'PASSED THROUGH';\n");

        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=0', ...$settings, $runner, $this->box->paths()->hook(), $this->ready['token']], $this->box->base);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $answer = json_decode($process->getOutput(), true);
        $this->assertIsArray($answer, 'the hook answered JSON and stopped there: '.$process->getOutput());
        $this->assertSame(['ok' => true, 'step' => 'swapped', 'next' => 'finish'], array_intersect_key($answer, array_flip(['ok', 'step', 'next'])));
        $this->assertSame(self::releaseFiles()['config/app.php'], $this->box->read('config/app.php'));
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertSame('swapped', $this->storedPlan()['status']);
        $this->assertStringNotContainsString('undefined function', implode("\n", $this->updater()->logLines()));
        $this->assertSame('done', $this->finishOn($this->updater($this->next))['status']);
    }

    public static function hostPhps(): array
    {
        return [
            'this PHP' => [[]],
            // On many shared hosts' lists. Since PHP 8 a disabled function does not exist at all: a call throws, "@"
            // does not help, and before the fix every swap answered "stuck" and the site stayed down.
            'set_time_limit, ignore_user_abort, chmod and touch disabled' => [['-d', 'disable_functions=set_time_limit,ignore_user_abort,chmod,touch']],
        ];
    }

    public function test_a_plan_that_cannot_be_read_keeps_the_site_down_until_it_can(): void
    {
        // plan.json is there but cannot be read for a moment (an I/O error, no free file handle): files may be half
        // moved, so it is never a reason to let visitors in, and the page is told to try again.
        $file = $this->box->paths()->plan();
        $plan = (string) file_get_contents($file);
        file_put_contents($file, substr($plan, 0, 40));

        $this->assertSame(503, $this->hookRequest(['REQUEST_METHOD' => 'GET'])['status'], 'visitors wait');
        $swap = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
            ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]);
        $this->assertSame(409, $swap['status']);
        $this->assertSame(['ok' => false, 'busy' => true, 'next' => 'swap'], array_intersect_key($swap['json'], array_flip(['ok', 'busy', 'next'])));
        $page = $this->hookRequest(['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT' => 'text/html'], [], [Updater::COOKIE => $this->ready['cookie']]);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Another step of the update is running. Wait a moment.', $page['body']);
        $this->assertFileExists($this->box->paths()->hook(), 'the hook stays');
        $this->assertFileExists($this->box->paths()->down());
        $this->assertSame($this->beforeSwap, $this->box->snapshot(), 'nothing moved');

        // Readable again: the swap goes on. A plan that is gone (the work folder deleted) lets the site answer again.
        file_put_contents($file, $plan);
        $this->assertSame('swapped', $this->swapThroughHook($this->ready['token'])['step']);
    }

    public function test_a_plan_that_cannot_be_saved_never_stops_the_files_from_going_back(): void
    {
        // A full disk or quota: a move fails (here: a new file damaged on the way) and the plan cannot be saved either
        // (storage/app/updates takes no new file, so plan.json can be neither written nor replaced).
        file_put_contents($this->jobFolder().'/staging/config/app.php', 'damaged on the way');
        $folder = $this->box->paths()->work();
        $plan = (string) file_get_contents($this->box->paths()->plan());
        $this->denyWrites($folder);

        try {
            $answer = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
                ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]);
        } finally {
            $this->allowWrites($folder);
        }

        $this->assertSame('failed', $answer['json']['step'], 'not "stuck": every file went back');
        $this->assertSame('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.', $answer['json']['message']);
        $this->assertSiteUnchanged('after a swap whose plan could not be saved');
        $this->assertSame($plan, (string) file_get_contents($this->box->paths()->plan()), 'the plan could not say so');
        $this->assertFileDoesNotExist($this->jobFolder().'/journal.jsonl', 'set aside: no later swap may skip a file that went back');
        $this->assertFileExists($this->jobFolder().'/journal.rolled-back.jsonl');
        $log = implode("\n", $this->updater()->logLines());
        $this->assertStringContainsString('the update plan could not be saved', $log);

        // With room again, the page's next step finds the plan still "swapping": a new hook, the swap from its first
        // file, the same damaged file, and this time the plan says how it ended.
        $again = $this->updater()->step($this->admin);
        $this->assertSame('swap', $again['next'], (string) $again['message']);
        $this->assertSame('failed', $this->swapThroughHook($again['token'])['step']);
        $this->assertSame('failed', $this->updater()->plan()['status']);
        $this->assertSiteUnchanged('after the second try');
    }

    public function test_a_swap_whose_every_move_ended_goes_on_to_finish_although_the_hook_could_not_save_it(): void
    {
        // A full disk or quota right after the last move: the hook cannot save "swapped" and stands down, as it should
        // (every new file is in place, so the site answers again). The plan still says swapping. (The plan is read
        // before the writes are taken away, as in the test above: on Windows a deny entry also keeps PHP from looking
        // up a file of that folder it has not looked up before.)
        $work = $this->box->paths()->work();
        $plan = (string) file_get_contents($this->box->paths()->plan());
        $this->denyWrites($work);

        try {
            $answer = $this->swapThroughHook($this->ready['token']);
        } finally {
            $this->allowWrites($work);
        }

        $this->assertSame(['step' => 'swapped', 'next' => 'finish'], array_intersect_key($answer, array_flip(['step', 'next'])));
        $this->assertSame($plan, (string) file_get_contents($this->box->paths()->plan()), 'the plan could not say so');
        $this->assertSame('swapping', $this->storedPlan()['status']);
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertSame(self::releaseFiles()['config/app.php'], $this->box->read('config/app.php'));

        // The page's next swap request reaches Laravel, now the new code. The swap is over: no new hook (here it could
        // not even be written: storage/framework takes no new file), the site never goes down again, Finish follows.
        // Before 1.2.1's fix the step wrote the hook again, failed, and the update stopped with the new files in place
        // but without its database step and without an Undo.
        $framework = dirname($this->box->paths()->hook());
        $this->denyWrites($framework);

        try {
            $step = $this->updater($this->next)->swap($this->admin);
        } finally {
            $this->allowWrites($framework);
        }

        $this->assertSame(['ok' => true, 'status' => 'swapped', 'next' => 'finish'], array_intersect_key($step, array_flip(['ok', 'status', 'next'])), (string) $step['message']);
        $this->assertArrayNotHasKey('token', $step, 'no new swap');
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());
        $plan = $this->storedPlan();
        $this->assertSame('swapped', $plan['status']);
        $this->assertGreaterThanOrEqual($plan['started_at'], $plan['swapped_at']);
        $this->assertArrayHasKey('opcache', $plan['settle']);
        $this->assertSame($plan['swap']['total'], $plan['swap']['done']);
        $this->assertStringContainsString('the swap is over', implode("\n", $this->updater($this->next)->logLines()));

        $done = $this->finishOn($this->updater($this->next));

        $this->assertSame('done', $done['status'], (string) $done['message']);
        $this->assertSame(['update.started', 'update.database_backup', 'update.files', 'update.finished'], array_column($this->auditRows(), 'action'));
        $this->assertSame(self::releaseFiles()['public/index.php'], $this->box->read('public/index.php'));
        $this->assertTrue($this->updater($this->next)->backups()[0]['undo'], 'Undo this update is offered');
    }

    public function test_a_swap_that_has_to_go_back_is_put_back_even_when_every_move_ended(): void
    {
        // Every move ended, then the administrator chose "Undo the update" on the hook's page: the plan says the files go
        // back. The hook is gone before it moved one back (deleted by hand, a host's clean-up), and the step that writes
        // it again cannot (storage/framework takes no new file). The update stops, and its files go back as in 1.2.0:
        // never a site left with the new files, no database step and no backup to undo them.
        $job = (array) PlanStore::read($this->jobFolder().'/job.json');
        (new SwapEngine($job))->forward(INF);
        $this->assertSame(self::releaseFiles()['config/app.php'], $this->box->read('config/app.php'));
        $this->changePlan(['status' => 'rolling-back', 'failure' => ['step' => 'swap', 'reason' => MaintenanceHook::STOPPED, 'at' => date('c'), 'audited' => false]]);
        (new HookWriter($this->box->paths()))->remove();
        $framework = dirname($this->box->paths()->hook());
        $this->denyWrites($framework);

        try {
            $answer = $this->updater($this->next)->swap($this->admin);
        } finally {
            $this->allowWrites($framework);
        }

        $this->assertFalse($answer['ok']);
        $this->assertSame('failed', $this->storedPlan()['status']);
        $this->assertSiteUnchanged('after a swap that had to go back');
        $this->assertContains('update.failed', array_column($this->auditRows(), 'action'));
    }

    public function test_every_folder_the_swap_moves_a_file_into_is_there_before_the_site_goes_down(): void
    {
        // A disk or account quota that is full when the swap runs: the swap needs no new folder (no free inode), only
        // renames into folders made before the site went down, in the site and in the file backup.
        $job = (array) PlanStore::read($this->jobFolder().'/job.json');
        $backedUp = 0;

        foreach ((new SwapEngine($job))->ops() as $op) {
            if (($op['phase'] ?? 'main') === 'pre') {
                continue;
            }

            $this->assertDirectoryExists(dirname($job['root'].'/'.$op['path']), $op['path']);

            if ($op['old'] !== null) {
                $this->assertDirectoryExists(dirname($job['backup'].'/files/'.$op['path']), 'the backup folder of '.$op['path']);
                $backedUp++;
            }
        }

        $this->assertGreaterThan(3, $backedUp, 'files of several folders move out');
        $this->assertSame($this->beforeSwap, $this->box->snapshot(), 'making the folders moved no file');
    }

    public function test_a_swap_request_while_another_step_holds_the_lock_answers_busy_and_moves_nothing(): void
    {
        $lock = $this->holdUpdateLock();

        try {
            $busy = $this->hookRequest(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'],
                ['avdbcms_update' => 'swap', 'token' => $this->ready['token']]);

            $this->assertSame(409, $busy['status']);
            $this->assertSame(['ok' => false, 'busy' => true, 'step' => 'swapping', 'next' => 'swap', 'message' => 'Another step of the update is running. Wait a moment.'], $busy['json']);
            $this->assertNothingMoved();
        } finally {
            $this->releaseUpdateLock($lock);
        }

        $this->assertSame('swapped', $this->swapThroughHook($this->ready['token'])['step']);
    }

    public function test_a_hook_whose_swap_is_over_steps_aside(): void
    {
        // Another plan took the place of this swap's (or the swap ended and the hook could not delete itself).
        $this->changePlan(['id' => 'another0plan0id']);

        $answer = $this->hookRequest(['REQUEST_METHOD' => 'GET']);

        $this->assertNull($answer, 'the request goes on to the site');
        $this->assertFileDoesNotExist($this->box->paths()->hook());
        $this->assertFileDoesNotExist($this->box->paths()->down());
    }

    /** The swap did not move a file: the tree as it was right before it, the plan still waiting, nothing journaled. */
    /** Time runs out for the swap token (the hook reads time(), which a test cannot move: its stored expiry goes back). */
    private function expireToken(): void
    {
        $hook = $this->box->paths()->hook();
        $code = (string) file_get_contents($hook);
        $expired = preg_replace("/'token_expires' => \\d+/", "'token_expires' => ".(time() - 1), $code, 1, $count);
        $this->assertSame(1, $count, 'the hook carries the expiry of its token');
        file_put_contents($hook, $expired);
    }

    private function assertNothingMoved(): void
    {
        $this->assertSame($this->beforeSwap, $this->box->snapshot());
        $plan = $this->storedPlan();
        $this->assertSame('swapping', $plan['status']);
        $this->assertSame(0, (int) ($plan['swap']['done'] ?? 0));
        $this->assertSame($this->journalBeforeSwap, (string) @file_get_contents($this->jobFolder().'/journal.jsonl'), 'not a single move of the swap was journaled');
        $this->assertFileExists($this->box->paths()->hook(), 'the site stays in maintenance');

        // Of the moves that wait for the site to be down, none began.
        $engine = new SwapEngine((array) json_decode((string) file_get_contents($this->jobFolder().'/job.json'), true));
        $marks = $engine->marks();
        $main = array_filter($engine->ops(), fn (array $op): bool => ($op['phase'] ?? 'main') !== 'pre');
        $this->assertNotSame([], $main);

        foreach ($main as $op) {
            $this->assertArrayNotHasKey((int) $op['i'], $marks, $op['path'].' was touched');
        }
    }
}
