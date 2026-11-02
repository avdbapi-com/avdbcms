<?php

namespace App\Notifications\Video;

use App\Notifications\Support\MemberMail;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use App\Notifications\Support\Realtime;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

class VideoBanned extends Notification
{
    use BellNotification;

    private Video $video;

    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    /**
     * Get the notification's channels: an e-mail and the bell (pushed live when the site has a live broadcaster).
     *
     * @param User $notifiable
     * @return array|string
     */
    public function via(User $notifiable): array|string
    {
        return Realtime::channels(['mail', 'database']);
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param User $notifiable
     * @return MailMessage
     */
    public function toMail(User $notifiable) : MailMessage
    {
        return $this->buildMailMessage($notifiable);
    }

    /**
     * Get the reset email notification mail message for the given URL.
     *
     * @param User $notifiable
     * @return MailMessage
     */
    protected function buildMailMessage(User $notifiable) : MailMessage
    {
        return (new MailMessage)
            ->subject(MemberMail::videoBan($notifiable, $this->video)['subject'])
            ->view(['mails.video.ban', 'mails.text.video.ban'], [
                'notifiable' => $notifiable,
                'video' => $this->video,
            ]);
    }

    /**
     * The bell's data: "Your video was suspended: <title>" (translated when shown) and the member's videos.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('video_suspended', [
            'title' => $this->video->title,
        ], [
            'video_id' => (int) $this->video->getKey(),
            'url' => route('user.videos.index'),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
