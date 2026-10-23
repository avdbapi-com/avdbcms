<?php

namespace App\Queue;

use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;

/**
 * Queue worker that survives hosting panels which load ext-pcntl but blacklist its
 * functions through php.ini disable_functions (aaPanel, HestiaCP, CyberPanel defaults).
 *
 * The stock worker only checks extension_loaded('pcntl') before calling pcntl_signal()
 * and pcntl_async_signals(); on those hosts that turns every cron-driven
 * `queue:work --stop-when-empty` into "Call to undefined function pcntl_signal()",
 * so mail, duration sync and counters never drain. Without the signal handlers the
 * worker simply runs the same way it does on hosts without the extension.
 */
class HostSafeWorker extends Worker
{
    protected function supportsAsyncSignals()
    {
        return extension_loaded('pcntl')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_alarm');
    }

    /**
     * Same guard for the hard-exit path: posix_kill() sits in the same disable_functions
     * lists, and SIGKILL is only defined when ext-pcntl is loaded.
     */
    public function kill($status = 0, $options = null)
    {
        $this->events->dispatch(new WorkerStopping($status, $options));

        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), defined('SIGKILL') ? SIGKILL : 9);
        }

        exit($status);
    }
}
