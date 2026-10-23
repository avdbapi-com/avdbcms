<?php

namespace App\Console\Commands;

use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DeleteExpiredChunks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:delete_expired_chunks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired chunks';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() : int
    {
        $basePath = storage_path('app/private/'.Video::CHUNK_FOLDER);
        if (! is_dir($basePath)) {
            $this->info(now()->format('Y-m-d H:i:s').' - Delete 0 chunks path');

            return Command::SUCCESS;
        }

        $now = now();

        $count = 0;

        $folders = [];
        foreach (glob($basePath.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $candidate) {
            $name = basename($candidate);
            if (preg_match('/^\d{10,13}$/', $name)) {
                $folders[] = $candidate;
                continue;
            }

            foreach (glob($candidate.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $nested) {
                $folders[] = $nested;
            }
        }

        foreach ($folders as $folder) {
            $realFolder = realpath($folder);
            $realBase = realpath($basePath);
            if (! $realFolder || ! $realBase || ! str_starts_with($realFolder, $realBase.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $name = basename($realFolder);
            $date = preg_match('/^\d{10,13}$/', $name)
                ? Carbon::createFromTimestampMs($name)
                : Carbon::createFromTimestamp((int) (filemtime($realFolder) ?: 0));

            if ($date->addHours(1)->lt($now)) {
                $this->deleteDirectory($realFolder);
                $count++;
            }
        }

        $this->info(now()->format('Y-m-d H:i:s',).' - Delete ' .$count. ' chunks path');

        return Command::SUCCESS;
    }

    private function deleteDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}


