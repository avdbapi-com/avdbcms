<?php

namespace App\Console\Commands;

use App\Models\Comment;
use App\Models\Playlist;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeTrashedVideos extends Command
{
    protected $signature = 'cleanup:purge-trashed {--chunk=100 : Number of records to process per batch}';

    protected $description = 'Permanently delete any lingering soft-deleted records across all tables (safety net cleanup)';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $totalPurged = 0;

        // 1. Purge soft-deleted videos
        $count = Video::onlyTrashed()->count();
        if ($count > 0) {
            $this->info("Purging {$count} soft-deleted videos...");
            Video::onlyTrashed()->chunkById($chunk, function ($videos) use (&$totalPurged) {
                foreach ($videos as $video) {
                    $video->forceDelete();
                    $totalPurged++;
                }
            });
        }

        // 2. Purge soft-deleted comments
        $count = Comment::onlyTrashed()->count();
        if ($count > 0) {
            $this->info("Purging {$count} soft-deleted comments...");
            Comment::onlyTrashed()->chunkById($chunk, function ($comments) use (&$totalPurged) {
                foreach ($comments as $comment) {
                    $comment->interactions()->delete();
                    $comment->forceDelete();
                    $totalPurged++;
                }
            });
        }

        // 3. Purge soft-deleted playlists
        $count = Playlist::onlyTrashed()->count();
        if ($count > 0) {
            $this->info("Purging {$count} soft-deleted playlists...");
            Playlist::onlyTrashed()->chunkById($chunk, function ($playlists) use (&$totalPurged) {
                foreach ($playlists as $playlist) {
                    $playlist->videos()->detach();
                    $playlist->forceDelete();
                    $totalPurged++;
                }
            });
        }

        // 4. Clean up orphan pivot records (playlist_has_videos with missing playlist or video)
        $orphanPivots = DB::table('playlist_has_videos')
            ->leftJoin('playlists', 'playlist_has_videos.playlist_id', '=', 'playlists.id')
            ->leftJoin('videos', 'playlist_has_videos.video_id', '=', 'videos.id')
            ->where(function ($q) {
                $q->whereNull('playlists.id')->orWhereNull('videos.id');
            })
            ->count();

        if ($orphanPivots > 0) {
            DB::table('playlist_has_videos')
                ->leftJoin('playlists', 'playlist_has_videos.playlist_id', '=', 'playlists.id')
                ->leftJoin('videos', 'playlist_has_videos.video_id', '=', 'videos.id')
                ->where(function ($q) {
                    $q->whereNull('playlists.id')->orWhereNull('videos.id');
                })
                ->delete();
            $this->info("Cleaned up {$orphanPivots} orphan pivot records.");
            $totalPurged += $orphanPivots;
        }

        if ($totalPurged === 0) {
            $this->info('No soft-deleted records found. Database is clean.');
        } else {
            $this->info("Total purged: {$totalPurged} records.");
        }

        return self::SUCCESS;
    }
}
