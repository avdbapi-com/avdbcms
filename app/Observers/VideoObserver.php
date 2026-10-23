<?php

namespace App\Observers;

use App\Enums\VideoStatus;
use App\Events\Video\VideoPublished;
use App\Helpers\File;
use App\Models\Video;

class VideoObserver
{
    /**
     * Handle the Video "updated" event.
     *
     * @param Video $video
     * @return void
     */
    public function updating(Video $video) : void
    {
        if ($video->status === VideoStatus::PUBLIC && is_null($video->getOriginal('published_at'))) {
            VideoPublished::dispatch($video);
        }
    }

    /**
     * Handle the Video "deleting" event.
     * Cascade-delete all related data and clean up files.
     * Every delete is permanent (forceDelete).
     *
     * @param Video $video
     * @return void
     */
    public function deleting(Video $video) : void
    {
        // Only run cascade on permanent delete
        if (!$video->isForceDeleting()) {
            return;
        }

        // Remove video file
        File::deleteIfExist($video->file, Video::VIDEO_FOLDER);

        // Remove video thumbnails and their files
        foreach ($video->thumbnails as $thumbnail) {
            File::deleteIfExist($thumbnail->file, Video::THUMBNAIL_FOLDER);
            $thumbnail->forceDelete();
        }

        // Cascade delete related data
        $video->comments()->forceDelete();
        $video->interactions()->delete();
        $video->viewsHistory()->delete();
        $video->viewDailyStats()->delete();
        $video->playlists()->detach();
        $video->reports()->delete();
    }
}
