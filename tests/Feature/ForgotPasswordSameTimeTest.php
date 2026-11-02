<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ResetLinkTiming;
use Carbon\CarbonInterval;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Round 1 remainder R1R-14: "Forgot password" told by its timing whether an address has an account. R1-043 made the
 * answer the same for every address, but the reset link (looking the address up, making the token, sending the mail)
 * was done inside the request for known addresses only, so they answered measurably slower (0.8 s with a remote mail
 * server here).
 *
 * Now the answer leaves first and the link is made and sent right after it, in the same web process (which has the
 * admin's mail settings): every address gets the same answer after the same work. Unchanged: the answer ("If an account
 * uses this e-mail ..."), the broker's own throttle (one link a minute per account), the form's limit, and the log line
 * for a mail server that refuses the link.
 *
 * On servers that can hold the answer until PHP has finished (php-cgi behind mod_fcgid or IIS, nginx's FastCGI
 * buffering, Apache's compression in front of mod_php: review finding BXR-01) the answer asks to be passed on, and a
 * request that mailed nothing waits as long as a recent link mail took (ResetLinkTiming). Those tests run on a test
 * clock (microseconds) that moves only when the test mail server "sends" and when anything sleeps (Sleep is faked).
 */
class ForgotPasswordSameTimeTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = 'If an account uses this e-mail, we sent a link to it. The link works for 60 minutes.';

    /** The test clock, in microseconds. */
    public int $clock = 5_000_000_000;

    /** How long the test mail server takes per mail and to say goodbye (QUIT) on the test clock; whether it refuses. */
    public int $mailTakes = 0;

    public int $quitTakes = 0;

    /** How often the test mail server's connection was closed (QUIT). */
    public int $goodbyes = 0;

    public bool $mailRefuses = false;

    /** @var list<string> recipients of the mails the test mail server accepted */
    public array $mailedTo = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.captcha_provider' => 'none', 'site.captcha_on_password_reset' => false]);
    }

    public function test_the_answer_leaves_before_the_address_is_looked_up_and_the_link_follows_it(): void
    {
        Notification::fake();
        $member = User::factory()->create(['email' => 'member@example.test']);

        [$request, $response] = $this->answerOnly('member@example.test');

        $this->assertTrue($response->isRedirection());
        $this->assertSame(self::ANSWER, session('status'));
        // its exact length: a server API that only flushes the answer (mod_php, CGI) lets the browser finish with it
        $this->assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count(), 'no token is made before the answer has left');

        $this->app->make(HttpKernel::class)->terminate($request, $response);

        Notification::assertSentTo($member, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'member@example.test')->count());
    }

    public function test_known_and_unknown_addresses_do_the_same_work_before_the_answer(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'member@example.test']);

        // warm-up (settings, caches of the first request)
        $this->answerOnly('warm-up@example.test');

        $work = [];
        foreach (['member@example.test', 'nobody@example.test', 'member@example.test', 'someone@example.test'] as $n => $email) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            [, $response] = $this->answerOnly($email, '198.51.100.'.($n + 1));
            $queries = collect(DB::getQueryLog())->pluck('query')->all();
            DB::disableQueryLog();

            $this->assertSame(self::ANSWER, session('status'), $email);
            $this->assertSame('/forgot-password', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), $email);
            $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"users"') || str_contains($sql, 'password_reset_tokens'))),
                $email.': the address is not looked up before the answer');
            $work[$email][] = count($queries);
        }

        $this->assertSame($work['member@example.test'][0], $work['nobody@example.test'][0], 'the same queries before the answer');
        $this->assertSame($work['nobody@example.test'][0], $work['someone@example.test'][0]);
        Notification::assertNothingSent();
    }

    public function test_one_answer_the_brokers_throttle_and_every_link_sent_once(): void
    {
        Notification::fake();
        $first = User::factory()->create(['email' => 'first@example.test']);
        $second = User::factory()->create(['email' => 'second@example.test']);

        foreach (['first@example.test', 'first@example.test', 'nobody@example.test', 'second@example.test'] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirect('/forgot-password')
                ->assertSessionHasNoErrors();
            $this->assertSame(self::ANSWER, session('status'), $email);
        }

        // The second request for "first" within the minute is the broker's throttle: one link each, never twice.
        Notification::assertSentToTimes($first, ResetPassword::class, 1);
        Notification::assertSentToTimes($second, ResetPassword::class, 1);
        Notification::assertSentTimes(ResetPassword::class, 2);
    }

    public function test_a_mail_server_that_refuses_the_link_is_logged_and_answered_the_same_way(): void
    {
        $this->app['mail.manager']->extend('refusing', static fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection refused by the test mail server.');
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
        config(['mail.default' => 'refusing', 'mail.mailers.refusing' => ['transport' => 'refusing']]);
        User::factory()->create(['email' => 'member@example.test']);
        Log::spy();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'member@example.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors();
        $this->assertSame(self::ANSWER, session('status'));

        Log::shouldHaveReceived('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'Password reset link could not be sent.'
                && ($context['exception'] ?? null) === TransportException::class
                && str_contains((string) ($context['message'] ?? ''), 'Connection refused by the test mail server.');
        });
    }

    public function test_the_forms_own_limit_is_unchanged(): void
    {
        Notification::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone'.$i.'@example.test'])
                ->assertRedirect('/forgot-password')
                ->assertSessionMissing('auth_throttled');
        }

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone@example.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('auth_throttled');
    }

    public function test_on_this_command_line_the_answer_counts_as_leaving_first_and_is_not_held_up(): void
    {
        // PHP-FPM / LiteSpeed (their finish functions), PHP's built-in server and the command line: no wait, no hints.
        $this->assertTrue(ResetLinkTiming::serverEndsTheAnswerFirst());
        $this->assertTrue($this->app->make(ResetLinkTiming::class)->answerLeavesFirst());

        Notification::fake();
        [, $response] = $this->answerOnly('nobody@example.test');

        $this->assertFalse($response->headers->has('X-Accel-Buffering'));
        $this->assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
    }

    public function test_where_the_server_holds_the_answer_it_asks_to_pass_it_on_at_once(): void
    {
        $timing = $this->heldAnswerTiming();
        $this->useTestMailServer(0);

        [$request, $response] = $this->answerOnly('nobody@example.test');

        $this->assertSame(self::ANSWER, session('status'));
        $this->assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'), 'nginx: do not buffer this answer');
        $this->assertSame(['no-gzip' => '1', 'no-brotli' => '1'], $timing->apacheEnv, 'mod_php: do not compress it');

        $this->terminateOn($request, $response);
    }

    public function test_where_the_server_holds_the_answer_every_address_takes_as_long_as_a_link_mail(): void
    {
        $timing = $this->heldAnswerTiming();
        $this->useTestMailServer(800_000);
        $this->quitTakes = 120_000;
        User::factory()->create(['email' => 'member@example.test']);

        // A known address: the link mail takes the mail server's 0.8 s and its goodbye 0.12 s (plus what is left of the
        // broker's timebox). The goodbye is said inside the measured time, not when PHP shuts down.
        [$request, $response] = $this->answerOnly('member@example.test', '198.51.100.1');
        $known = $this->terminateOn($request, $response);

        $this->assertSame(['member@example.test'], $this->mailedTo);
        $this->assertSame(1, $this->goodbyes, 'the mail server connection is closed inside the measured work');
        $this->assertSame([$known], $timing->samples(), 'the link mail\'s time is kept');
        $this->assertGreaterThanOrEqual(800_000 + 120_000, $known);

        // An unknown address mails nothing and waits exactly as long.
        [$request, $response] = $this->answerOnly('nobody@example.test', '198.51.100.2');
        $this->assertEqualsWithDelta($known, $this->terminateOn($request, $response), 1, 'unknown = known');

        // The same known address again within the minute: the broker's throttle mails nothing, and it waits too.
        [$request, $response] = $this->answerOnly('member@example.test', '198.51.100.3');
        $this->assertEqualsWithDelta($known, $this->terminateOn($request, $response), 1, 'throttled = known');

        $this->assertSame(['member@example.test'], $this->mailedTo, 'one link, as before');
        $this->assertSame(1, $this->goodbyes, 'nothing to close where nothing was mailed');
        $this->assertSame([$known], $timing->samples(), 'only real link mails are kept');
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertSame(self::ANSWER, session('status'));
    }

    public function test_the_wait_is_one_of_the_last_twenty_link_mail_times(): void
    {
        $timing = $this->heldAnswerTiming();
        $this->useTestMailServer(0);

        $kept = [];
        for ($i = 1; $i <= 24; $i++) {
            $this->mailTakes = 300_000 + $i * 10_000;
            User::factory()->create(['email' => "member{$i}@example.test"]);
            [$request, $response] = $this->answerOnly("member{$i}@example.test", '198.51.100.'.$i);
            $kept[] = $this->terminateOn($request, $response);
        }

        $this->assertCount(24, $this->mailedTo);
        $this->assertSame(array_slice($kept, -ResetLinkTiming::SAMPLES), $timing->samples(), 'the last 20, oldest first');

        $picked = [];
        for ($i = 1; $i <= 30; $i++) {
            [$request, $response] = $this->answerOnly("nobody{$i}@example.test", '203.0.113.'.$i);
            $wait = $this->terminateOn($request, $response);

            // each wait is one of the kept mail times (to the microsecond the fake sleep rounds to)
            $nearest = null;
            foreach ($timing->samples() as $index => $sample) {
                if (abs($sample - $wait) <= 1) {
                    $nearest = $index;
                }
            }
            $this->assertNotNull($nearest, "wait {$wait} is one of the kept mail times");
            $picked[] = $nearest;
        }

        $this->assertGreaterThan(1, count(array_unique($picked)), 'picked at random, not always the same one');
    }

    public function test_the_wait_is_bounded_and_never_runs_into_the_time_limit(): void
    {
        $timing = $this->heldAnswerTiming();
        User::factory()->create(['email' => 'member@example.test']);

        // A mail server that took a minute: the wait stops at 10 seconds.
        $this->useTestMailServer(60_000_000);
        [$request, $response] = $this->answerOnly('member@example.test', '198.51.100.1');
        $this->assertGreaterThanOrEqual(60_000_000, $this->terminateOn($request, $response));

        [$request, $response] = $this->answerOnly('nobody@example.test', '198.51.100.2');
        $this->assertEqualsWithDelta(ResetLinkTiming::MAX_WAIT_US, $this->terminateOn($request, $response), 2);

        // 1.5 s left before the request's time limit (less its margin): the wait after the broker's timebox (at most
        // 0.2 s) ends there.
        $timing->left = 1_500_000;
        [$request, $response] = $this->answerOnly('nobody2@example.test', '198.51.100.3');
        $taken = $this->terminateOn($request, $response);
        $this->assertGreaterThanOrEqual(1_500_000, $taken);
        $this->assertLessThanOrEqual(1_500_000 + 200_000, $taken, 'waited only what was left');
    }

    public function test_before_the_first_link_mail_nothing_is_waited(): void
    {
        $timing = $this->heldAnswerTiming();
        $this->useTestMailServer(800_000);

        [$request, $response] = $this->answerOnly('nobody@example.test');
        $taken = $this->terminateOn($request, $response);

        $this->assertSame([], $timing->samples());
        $this->assertLessThanOrEqual(200_000, $taken, 'only the broker\'s own timebox (0.2 s)');
        $this->assertSame([], $this->mailedTo);
    }

    public function test_where_the_server_ends_the_answer_first_nothing_waits_and_nothing_is_kept(): void
    {
        $timing = $this->heldAnswerTiming(answerLeavesFirst: true);
        $this->useTestMailServer(800_000);
        $this->quitTakes = 120_000;
        User::factory()->create(['email' => 'member@example.test']);

        [$request, $response] = $this->answerOnly('member@example.test', '198.51.100.1');
        $this->assertFalse($response->headers->has('X-Accel-Buffering'));
        $this->assertSame([], $timing->apacheEnv);
        $this->assertGreaterThanOrEqual(800_000, $this->terminateOn($request, $response));

        [$request, $response] = $this->answerOnly('nobody@example.test', '198.51.100.2');
        $this->assertLessThanOrEqual(200_000, $this->terminateOn($request, $response), 'only the broker\'s timebox');

        $this->assertSame(['member@example.test'], $this->mailedTo);
        $this->assertSame(0, $this->goodbyes, 'the connection is left to PHP\'s shutdown, as before');
        $this->assertSame([], $timing->samples(), 'nothing kept where nothing waits');
    }

    public function test_where_the_server_holds_the_answer_a_refused_link_mail_counts_as_a_mail_and_is_logged(): void
    {
        $timing = $this->heldAnswerTiming();
        $this->useTestMailServer(300_000, refuses: true);
        User::factory()->create(['email' => 'member@example.test']);
        Log::spy();

        [$request, $response] = $this->answerOnly('member@example.test', '198.51.100.1');
        $refused = $this->terminateOn($request, $response);

        $this->assertSame([$refused], $timing->samples(), 'a refused mail took a mail\'s time too');
        Log::shouldHaveReceived('warning')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'Password reset link could not be sent.'
                && ($context['exception'] ?? null) === TransportException::class;
        });

        [$request, $response] = $this->answerOnly('nobody@example.test', '198.51.100.2');
        $this->assertEqualsWithDelta($refused, $this->terminateOn($request, $response), 1);
        $this->assertSame([], $this->mailedTo);
    }

    /**
     * The request up to its answer only (the kernel's handle(), as PHP-FPM sends it), without the work that follows
     * the answer (terminate()).
     *
     * @return array{Request, Response}
     */
    private function answerOnly(string $email, string $ip = '198.51.100.250'): array
    {
        $request = Request::create('/forgot-password', 'POST', ['email' => $email], [], [], [
            'HTTP_REFERER' => 'http://localhost/forgot-password',
            'REMOTE_ADDR' => $ip,
        ]);

        $response = $this->app->make(HttpKernel::class)->handle($request);

        return [$request, $response];
    }

    /** The work after the answer (the kernel's terminate()); returns how long it took on the test clock. */
    private function terminateOn(Request $request, Response $response): int
    {
        $before = $this->clock;
        $this->app->make(HttpKernel::class)->terminate($request, $response);

        return $this->clock - $before;
    }

    /**
     * A ResetLinkTiming on the test clock, as on a server that may hold the answer (php-cgi, mod_php) or, with
     * $answerLeavesFirst, as on PHP-FPM; it records what it would tell Apache and can be given a time limit. Sleep is
     * faked: every sleep (the broker's timebox, the wait) moves the test clock.
     */
    private function heldAnswerTiming(bool $answerLeavesFirst = false): ResetLinkTiming
    {
        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration): void {
            $this->clock += (int) round($duration->totalMicroseconds);
        });

        $timing = new class($answerLeavesFirst, fn (): int => $this->clock) extends ResetLinkTiming
        {
            /** @var array<string, string> */
            public array $apacheEnv = [];

            public ?int $left = null;

            protected function apacheSetenv(string $name, string $value): void
            {
                $this->apacheEnv[$name] = $value;
            }

            protected function timeLeft(): int
            {
                return $this->left ?? parent::timeLeft();
            }
        };

        $this->app->instance(ResetLinkTiming::class, $timing);

        return $timing;
    }

    /** The site's mail goes to a test mail server that takes $microseconds of the test clock per mail. */
    private function useTestMailServer(int $microseconds, bool $refuses = false): void
    {
        $this->mailTakes = $microseconds;
        $this->mailRefuses = $refuses;
        $test = $this;

        $this->app['mail.manager']->extend('timed', static fn () => new class($test) extends AbstractTransport
        {
            private bool $open = false;

            public function __construct(private readonly ForgotPasswordSameTimeTest $test)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                $this->open = true;
                $this->test->clock += $this->test->mailTakes;

                if ($this->test->mailRefuses) {
                    throw new TransportException('Connection refused by the test mail server.');
                }

                foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                    $this->test->mailedTo[] = $recipient->getAddress();
                }
            }

            /** Like SmtpTransport::stop(): goodbye to the mail server (QUIT and its answer), once per connection. */
            public function stop(): void
            {
                if ($this->open) {
                    $this->open = false;
                    $this->test->clock += $this->test->quitTakes;
                    $this->test->goodbyes++;
                }
            }

            public function __toString(): string
            {
                return 'timed://';
            }
        });
        config(['mail.default' => 'timed', 'mail.mailers.timed' => ['transport' => 'timed']]);
    }
}
