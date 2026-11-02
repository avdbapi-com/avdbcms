<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Forgot password" in the same time for every address (R1R-14). The reset link is looked up, made and mailed after
 * the answer, in the web process (PasswordController::email). Whether the visitor waits for that work depends on how
 * the server runs PHP:
 *
 *  - PHP-FPM and LiteSpeed end the answer before it (fastcgi_finish_request / litespeed_finish_request), and PHP's
 *    built-in server passes the answer on as soon as PHP flushes it (its exact Content-Length tells the browser that
 *    it is complete). The answer has left: nothing more to do.
 *  - Other servers can hold the answer until PHP has finished: php-cgi behind Apache's mod_fcgid (Virtualmin's FCGId
 *    mode, Plesk's "FastCGI application by Apache"; it keeps up to 64 KB) or behind IIS (up to 4 MB), nginx's FastCGI
 *    buffering in front of php-cgi, and Apache's DEFLATE / Brotli filter in front of mod_php (its last chunk). There an
 *    address with an account answered one mail later than one without (measured: +0.8 s). On these servers only:
 *      1. the answer asks nginx not to buffer it (X-Accel-Buffering: no) and mod_php's Apache not to compress it
 *         (no-gzip, no-brotli), so it leaves at once where the server allows that;
 *      2. a request that mailed nothing (an address without an account, or the broker's one-link-a-minute throttle)
 *         waits as long as a link mail of this mail server recently took (the whole work, until the mail server's
 *         connection is closed): one of the last 20 measured, at random, at most 10 seconds and never past the
 *         request's time limit. Every address then takes as long as one mail.
 *    The mail itself is sent exactly as before (same process, the admin's mail settings, at once). Until the first
 *    link mail after a cache clear no time is known, and nothing is waited.
 */
class ResetLinkTiming
{
    /** How many recent link-mail times are kept (per mail server). */
    public const SAMPLES = 20;

    /** The longest wait of a request that mailed nothing, in microseconds. */
    public const MAX_WAIT_US = 10_000_000;

    /** Kept this long after the last link mail; a mail server the site does not use any more is forgotten. */
    public const KEEP_SECONDS = 90 * 86400;

    /** Never wait into the last part of the request's time limit (seconds). */
    private const TIME_LIMIT_MARGIN = 2.0;

    private readonly bool $answerLeavesFirst;

    /** Microseconds on a monotonic clock. */
    private readonly Closure $clock;

    public function __construct(?bool $answerLeavesFirst = null, ?Closure $clock = null)
    {
        $this->answerLeavesFirst = $answerLeavesFirst ?? self::serverEndsTheAnswerFirst();
        $this->clock = $clock ?? static fn (): int => intdiv(hrtime(true), 1000);
    }

    /**
     * Whether the visitor never waits for the work after the answer on this server: PHP-FPM and LiteSpeed end the
     * answer first (what Symfony's Response::send() calls), PHP's built-in server passes it on when PHP flushes it,
     * and the command line (the tests, the console) has no visitor.
     */
    public static function serverEndsTheAnswerFirst(): bool
    {
        return function_exists('fastcgi_finish_request')
            || function_exists('litespeed_finish_request')
            || in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true);
    }

    public function answerLeavesFirst(): bool
    {
        return $this->answerLeavesFirst;
    }

    /**
     * The answer's exact length on every server (its body is final here: a redirect's short page; PHP turns its own
     * output compression off for an answer that states its length), and, where the server may hold it, the requests
     * to pass it on at once.
     */
    public function prepare(Response $answer): void
    {
        $answer->headers->set('Content-Length', (string) strlen((string) $answer->getContent()));

        if ($this->answerLeavesFirst) {
            return;
        }

        $answer->headers->set('X-Accel-Buffering', 'no');
        $this->apacheSetenv('no-gzip', '1');
        $this->apacheSetenv('no-brotli', '1');
    }

    /**
     * Run the work after the answer. $work returns true when it mailed a link (sent, or refused by the mail server)
     * and false when it mailed nothing.
     */
    public function afterTheAnswer(Closure $work): void
    {
        $started = ($this->clock)();
        $mailed = (bool) $work();

        if ($this->answerLeavesFirst) {
            return;
        }

        if ($mailed) {
            // The mail server's connection stays open after the mail; Symfony says goodbye to it (QUIT and its answer:
            // one more round trip) only when PHP shuts down, after this measurement, and only for a request that mailed.
            // Close it now, inside the measured time (measured: +0.12 s per round trip otherwise).
            $this->closeMailConnection();
        }

        $elapsed = max(0, ($this->clock)() - $started);

        try {
            if ($mailed) {
                $this->remember($elapsed);
            } else {
                $this->waitLikeAMail($elapsed);
            }
        } catch (Throwable) {
            // no cache: no time to compare with
        }
    }

    /**
     * The link-mail times kept for the current mail server (microseconds, oldest first).
     *
     * @return list<int>
     */
    public function samples(): array
    {
        $samples = Cache::get($this->cacheKey());

        return is_array($samples)
            ? array_values(array_filter($samples, static fn (mixed $value): bool => is_int($value) && $value > 0))
            : [];
    }

    private function remember(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        $samples = $this->samples();
        $samples[] = $microseconds;

        Cache::put($this->cacheKey(), array_slice($samples, -self::SAMPLES), self::KEEP_SECONDS);
    }

    private function waitLikeAMail(int $elapsed): void
    {
        $samples = $this->samples();

        if ($samples === []) {
            return;
        }

        $target = min($samples[random_int(0, count($samples) - 1)], self::MAX_WAIT_US);
        $wait = min($target - $elapsed, $this->timeLeft());

        if ($wait > 0) {
            Sleep::usleep($wait);
        }
    }

    /** Microseconds left before the request's time limit, less a margin (PHP_INT_MAX without a limit). */
    protected function timeLeft(): int
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $left = $limit - (microtime(true) - $started) - self::TIME_LIMIT_MARGIN;

        return max(0, (int) ($left * 1_000_000));
    }

    /** Disconnect from the mail server now (an SMTP connection; other transports have nothing to close). */
    protected function closeMailConnection(): void
    {
        try {
            $transport = app('mail.manager')->mailer()->getSymfonyTransport();

            if (method_exists($transport, 'stop')) {
                $transport->stop();
            }
        } catch (Throwable) {
            // nothing to close
        }
    }

    /** mod_php only (Apache's own environment of this request): ignored by every other server. */
    protected function apacheSetenv(string $name, string $value): void
    {
        if (function_exists('apache_setenv')) {
            @apache_setenv($name, $value);
        }
    }

    /** One list per mail server: the times of one say nothing about another. */
    private function cacheKey(): string
    {
        $mailer = (string) config('mail.default', '');
        $host = config('mail.mailers.'.$mailer.'.host');
        $port = config('mail.mailers.'.$mailer.'.port');

        return 'password-reset:mail-times:'.hash('sha256', $mailer."\n".(is_scalar($host) ? (string) $host : '')
            ."\n".(is_scalar($port) ? (string) $port : ''));
    }
}
