<?php

namespace App\Observers;

use App\Models\Comment;
use App\Notifications\Activity\NewComment;

class CommentObserver
{
    /**
     * Handle the Comment "creating" event.
     *
     * @param Comment $comment
     * @return void
     */
    public function creating(Comment $comment) : void
    {
        $comment->ip = request()->getClientIp();
    }

    /**
     * Handle the Comment "creating" event.
     *
     * @param Comment $comment
     * @return void
     */
    public function created(Comment $comment) : void
    {
        if ($comment->video_id) {
            \App\Models\Video::where('id', $comment->video_id)->increment('comments_count');
        }

        if ($comment->video && $comment->user && $comment->user->isNot($comment->video->user)) {
            $comment->video->user->notify(new NewComment($comment));
        }
    }

    /**
     * Handle the Comment "deleted" event.
     */
    public function deleted(Comment $comment) : void
    {
        if ($comment->video_id) {
            \App\Models\Video::where('id', $comment->video_id)
                ->where('comments_count', '>', 0)
                ->decrement('comments_count');
        }
    }

    /**
     * Handle the Comment "restored" event.
     */
    public function restored(Comment $comment) : void
    {
        if ($comment->video_id) {
            \App\Models\Video::where('id', $comment->video_id)->increment('comments_count');
        }
    }
}
