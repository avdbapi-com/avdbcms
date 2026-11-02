<?php

namespace App\Notifications\Activity;

use App\Models\Comment;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;

/** "x commented on your video: <title>". Only when the creator wants it (Account settings > Notifications > Comments). */
class NewComment extends Notification
{
    use BellNotification;

    private Comment $comment;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Comment $comment)
    {
        $this->comment = $comment;
    }

    /** The creator's switch "Comments on my videos" (replies under their videos included). */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return NotificationSetting::allows($notifiable, 'comments');
    }

    /**
     * The bell's data: who commented on which video (translated when shown), the video's link, the commenter and the
     * video (the row shows the avatar and the picture).
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('new_comment', [
            'name' => $this->comment->user?->username,
            'title' => $this->comment->video?->title,
        ], [
            'url' => $this->comment->video->route,
            'actor_id' => $this->comment->user_id !== null ? (int) $this->comment->user_id : null,
            'video_id' => $this->comment->video_id !== null ? (int) $this->comment->video_id : null,
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
