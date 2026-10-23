<?php

namespace App\Console\Commands;

use App\Services\ViewHistoryRetentionService;
use Illuminate\Console\Command;

class PruneViewHistory extends Command
{
    protected $signature = 'views:prune-history {--chunk=5000 : Number of raw view rows to delete per batch}';

    protected $description = 'Delete raw view history rows older than the configured retention window';

    public function handle(ViewHistoryRetentionService $views): int
    {
        $deleted = $views->prune(now(), (int) $this->option('chunk'));

        $this->info("Deleted {$deleted} expired raw view row(s).");

        return Command::SUCCESS;
    }
}
