<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\Upload18Service;
use Illuminate\Console\Command;

class SyncUpload18Metadata extends Command
{
    protected $signature = 'videos:sync-upload18-metadata {--limit=500} {--dry-run}';

    protected $description = 'Sync missing Upload18 metadata onto local video rows';

    public function handle(Upload18Service $upload18): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $videos = Video::query()
            ->whereNotNull('embed_code')
            ->missingDuration()
            ->select(['id', 'title', 'duration', 'upload18_vid', 'embed_code'])
            ->limit($limit)
            ->get();

        if ($videos->isEmpty()) {
            $this->info('No Upload18 videos need metadata sync.');
            return self::SUCCESS;
        }

        $byDid = $videos->whereNotNull('upload18_vid')->keyBy(fn (Video $video) => (string) $video->upload18_vid);
        $byVid = $videos->whereNotNull('embed_code')->keyBy(fn (Video $video) => (string) $video->embed_code);
        $matched = [];
        $updated = 0;
        $page = 1;
        $totalPages = 1;

        do {
            $response = $upload18->listVideos($page, 100);
            $totalPages = max($totalPages, (int) ($response['total_page'] ?? 1));

            foreach (($response['data'] ?? []) as $remoteVideo) {
                $remoteDid = (string) ($remoteVideo['id'] ?? '');
                $remoteVid = (string) ($remoteVideo['vid'] ?? '');
                $video = $byDid->get($remoteDid) ?: $byVid->get($remoteVid);

                if (!$video || isset($matched[$video->id])) {
                    continue;
                }

                $matched[$video->id] = true;
                $seconds = Upload18Service::durationToSeconds($remoteVideo['duration'] ?? null);

                if (!$seconds) {
                    continue;
                }

                if (!$this->option('dry-run')) {
                    $video->forceFill(['duration' => $seconds])->save();
                }

                $updated++;
                $this->line("{$video->id}: {$video->title} => {$seconds}s");
            }

            $page++;
        } while ($page <= $totalPages && count($matched) < $videos->count());

        $this->info(($this->option('dry-run') ? 'Would update ' : 'Updated ') . $updated . ' video(s).');

        return self::SUCCESS;
    }
}
