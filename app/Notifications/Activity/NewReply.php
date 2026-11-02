<?php

namespace App\Notifications\Activity;

use App\Models\Comment;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;

/**
 * "x replied to your comment on <title>" (member design 6.14, "Replies to my comments"): to the author of the comment
 * that was answered, when it is not their own reply and not their own video (the video's owner gets NewComment). Only
 * when the member wants it (Account settings > Notifications > Replies to my comments).
 *
 * Sent by App\Observers\CommentObserver::created() for a reply; NewReply::for() says
 * whom to send it to.
 */
class NewReply extends Notification
{
    use BellNotification;

    private Comment $reply;

    public function __construct(Comment $reply)
    {
        $this->reply = $reply;
    }

    /**
     * The member to tell about a new comment when it is a reply: the parent comment's author, unless they wrote the reply
     * or own the video (the owner hears about every comment already), or the parent is gone.
     */
    public static function recipient(Comment $comment): ?User
    {
        if (empty($comment->parent_id) || $comment->user_id === null) {
            return null;
        }

        $parent = $comment->parent()->with('user')->first();
        $author = $parent?->user;

        if (! $author instanceof User || (int) $author->getKey() === (int) $comment->user_id) {
            return null;
        }

        if ((int) ($comment->video?->user_id ?? 0) === (int) $author->getKey()) {
            return null;
        }

        return $author;
    }

    /** The member's switch "Replies to my comments". */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return NotificationSetting::allows($notifiable, 'replies');
    }

    /**
     * The bell's data: who replied on which video (translated when shown), the video's link, the replier and the video.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('new_reply', [
            'name' => $this->reply->user?->username,
            'title' => $this->reply->video?->title,
        ], [
            'url' => $this->reply->video?->route,
            'actor_id' => $this->reply->user_id !== null ? (int) $this->reply->user_id : null,
            'video_id' => $this->reply->video_id !== null ? (int) $this->reply->video_id : null,
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
