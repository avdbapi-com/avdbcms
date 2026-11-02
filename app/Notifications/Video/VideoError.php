<?php

namespace App\Notifications\Video;

use App\Models\User;
use App\Models\Video;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;

class VideoError extends Notification
{
    use BellNotification;

    private Video $video;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    /**
     * The bell's data: "Your video could not be processed: <title>" (translated when shown) and the member's videos.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('video_failed', [
            'title' => $this->video->title,
        ], [
            'video_id' => (int) $this->video->getKey(),
            'url' => route('user.videos.index'),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
