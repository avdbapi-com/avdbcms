<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Waiting for a child PHP process of a test to be ready (R1R-16): the child writes a marker file once it is ready, and
 * the test polls for that file, for a bounded time.
 *
 * Never Process::waitUntil() on a line of the child's output: Symfony Process (7.4) reads a child's output in two places,
 * waitUntil()'s own read (for its callback) and every updateStatus() - waitUntil()'s first line, and on Windows
 * isRunning() at the top of each pass of its loop - which keeps what it reads for getOutput() only. A line written
 * between the two is never seen by waitUntil()'s callback, which then waits until the child ends: FinishLockTest and
 * UpdateFinishStrictHostTest waited for their holder's sleep(120) and failed on a busy machine. A file is there or not,
 * whoever reads the output.
 */
trait WaitsForChildProcess
{
    /**
     * Wait until $marker exists while $process runs, at most $seconds. Fails at once with the child's exit code and
     * output when it ends first, and after $seconds with what it printed (the child is stopped).
     */
    protected function waitForMarker(Process $process, string $marker, float $seconds, string $who): void
    {
        $started = microtime(true);

        while (! is_file($marker)) {
            if (! $process->isRunning()) {
                $this->fail(sprintf('%s ended (exit %s) before it was ready: %s', $who, var_export($process->getExitCode(), true),
                    trim($process->getOutput().$process->getErrorOutput())));
            }

            if (microtime(true) - $started > $seconds) {
                $process->stop(0);
                $this->fail(sprintf('%s was not ready within %d s: %s', $who, (int) $seconds, trim($process->getOutput().$process->getErrorOutput())));
            }

            usleep(20_000);
        }
    }
}
