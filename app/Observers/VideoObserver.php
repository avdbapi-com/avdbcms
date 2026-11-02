<?php

namespace App\Observers;

use App\Enums\VideoStatus;
use App\Events\Video\VideoPublished;
use App\Helpers\File;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Support\VideoBans;
use Illuminate\Support\Facades\DB;

class VideoObserver
{
    /**
     * Handle the Video "creating" event: a video that starts public or scheduled has a publish date.
     *
     * @param Video $video
     * @return void
     */
    public function creating(Video $video) : void
    {
        $this->datePublication($video);
    }

    /**
     * Handle the Video "updating" event.
     *
     * @param Video $video
     * @return void
     */
    public function updating(Video $video) : void
    {
        if ($video->isDirty('status') || $video->isDirty('banned_at')) {
            $this->keepBanState($video);
        }

        if ($video->isDirty('status')) {
            $this->datePublication($video);
        }

        if ($video->status === VideoStatus::PUBLIC && is_null($video->getOriginal('published_at'))) {
            VideoPublished::dispatch($video);
        }
    }

    /**
     * A video that becomes public without a publish date gets the moment it becomes public, a scheduled one its planned
     * date (what the studio stores). The lists order by that date and page on it: a public video without one was never
     * listed under Newest, and as the last card of a Most viewed or Trending page it made "load more" fail (1.2.2: Unban).
     */
    private function datePublication(Video $video) : void
    {
        if ($video->published_at !== null) {
            return;
        }

        if ($video->status === VideoStatus::PUBLIC) {
            $video->published_at = now();
        } elseif ($video->status === VideoStatus::PLANNED && $video->scheduled_at !== null) {
            $video->published_at = $video->scheduled_at;
        }
    }

    /**
     * A ban by any way (Ban, the video's Visibility field) keeps the status the video had and the ban's date, for Unban;
     * a video that leaves the ban by any way is no longer banned (App\Support\VideoBans). A second Ban of a banned video
     * gives the ban a new date: the status this ban kept goes with it (one an earlier ban left behind does not).
     */
    private function keepBanState(Video $video) : void
    {
        $before = $video->getOriginal('status');
        $banned = $video->status === VideoStatus::BANNED;

        if ($banned !== ($before === VideoStatus::BANNED)) {
            // a ban starts now, unless the change gives its date (Ban does)
            $video->banned_at = $banned ? ($video->isDirty('banned_at') && $video->banned_at !== null ? $video->banned_at : now()) : null;

            if (VideoBans::available()) {
                $video->setAttribute(VideoBans::COLUMN, $banned && $before instanceof VideoStatus ? $before->value : null);
                $video->setAttribute(VideoBans::KEPT_AT, $banned ? $video->banned_at : null);
            }

            return;
        }

        if ($banned && $video->isDirty('banned_at') && VideoBans::available()
            && VideoBans::sameMoment($video->getOriginal(VideoBans::KEPT_AT), $video->getOriginal('banned_at'))) {
            $video->setAttribute(VideoBans::KEPT_AT, $video->banned_at);
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
        // for good, every one (also those in the bin): the bundled database's foreign key removes them, a site installed
        // by the migrations kept them for a video that no longer existed (judge R3-009)
        $video->viewsHistory()->forceDelete();
        $video->viewDailyStats()->delete();
        $video->playlists()->detach();
        $video->reports()->delete();
        // its subtitles too, on every site (judge R3-009: only the bundled database's foreign key removed them; a site
        // installed by the migrations kept them for a video that no longer existed)
        DB::table('subtitles')->where('video_id', $video->getKey())->delete();
    }

    /**
     * Handle the Video "deleted" event (soft and permanent): the home hero keeps its list for 3 minutes, and a deleted
     * video leaves it at once instead of staying linked from the home page.
     *
     * @param Video $video
     * @return void
     */
    public function deleted(Video $video) : void
    {
        PublicVideoFeedService::forgetHeroVideos();
    }
}
