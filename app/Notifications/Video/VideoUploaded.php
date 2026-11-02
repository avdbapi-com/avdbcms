<?php

namespace App\Notifications\Video;

use App\Models\User;
use App\Models\Video;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;

class VideoUploaded extends Notification
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
     * The bell's data: "Your video is ready: <title>" (translated when shown) and where to finish or edit it.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('video_ready', [
            'title' => $this->video->title,
        ], [
            'video_id' => (int) $this->video->getKey(),
            'url' => $this->video->is_draft ? route('user.videos.create', $this->video) : route('user.videos.edit', $this->video),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
