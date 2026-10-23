<?php

namespace App\Console\Commands;

use App\Support\AvdbDurationBackfill;
use App\Support\RuntimeEnv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class QueueAvdbDurationSync extends Command
{
    protected $signature = 'avdb:queue-duration-sync
        {--limit=100 : Maximum videos to queue per scheduler tick}
        {--chunk=100 : Videos per queued job}
        {--retry-minutes=15 : Wait this many minutes before retrying unresolved durations}
        {--max-runtime=55 : Maximum seconds to spend when the scheduler runs duration sync inline}
        {--remote-concurrency=0 : Override concurrent Upload18/player lookups; 0 uses the configured default}
        {--remote-timeout=0 : Override Upload18/player timeout seconds; 0 uses the configured default}';

    protected $description = 'Sync missing AVDB video durations from the Laravel scheduler';

    public function handle(): int
    {
        if ($this->durationBackfillMode() === 'auto') {
            return $this->runInlineSchedulerSync();
        }

        $result = AvdbDurationBackfill::start(
            (int) $this->option('limit'),
            (int) $this->option('chunk')
        );

        if (! $result['available']) {
            $this->warn($result['message']);
        } else {
            $this->info($result['message']);
        }

        return self::SUCCESS;
    }

    private function runInlineSchedulerSync(): int
    {
        $status = Artisan::call('avdb:sync-durations', [
            '--limit' => max(1, (int) $this->option('limit')),
            '--chunk' => max(1, min(200, (int) $this->option('chunk'))),
            '--until-empty' => true,
            '--sleep' => 0,
            '--retry-minutes' => max(0, (int) $this->option('retry-minutes')),
            '--max-runtime' => max(5, min(3600, (int) $this->option('max-runtime'))),
            '--remote-concurrency' => $this->remoteConcurrency(),
            '--remote-timeout' => $this->remoteTimeout(),
        ]);

        $output = trim(Artisan::output());
        if ($output !== '') {
            $this->line($output);
        }

        return (int) $status === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function durationBackfillMode(): string
    {
        $mode = strtolower(trim((string) RuntimeEnv::get('AVDB_DURATION_BACKFILL_MODE', 'auto')));

        return in_array($mode, ['auto', 'process', 'queue', 'off'], true) ? $mode : 'auto';
    }

    private function remoteConcurrency(): int
    {
        $override = (int) $this->option('remote-concurrency');

        return $override > 0 ? max(1, min(100, $override)) : AvdbDurationBackfill::remoteConcurrency();
    }

    private function remoteTimeout(): int
    {
        $override = (int) $this->option('remote-timeout');

        return $override > 0 ? max(2, min(30, $override)) : AvdbDurationBackfill::remoteTimeout();
    }
}
