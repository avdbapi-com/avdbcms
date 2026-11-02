<?php

namespace App\Notifications\Activity;

use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A channel the member follows published a video. A channel's subscribers are told in batches by
 * App\Notifications\Support\NotificationTasks::announceVideo(), with this same data and the same rule as shouldSend():
 * the member's bell for this channel wins ("none": never, "highlights": not its Shorts, "all": always); without a choice
 * the member's "New videos from channels I follow" decides.
 */
class NewVideo extends Notification
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

    public function shouldSend(User $notifiable, string $channel): bool
    {
        $bell = null;

        if (NotificationSetting::bellReady()) {
            try {
                $bell = DB::table('subscriptions')
                    ->where('subscriber_id', $notifiable->getKey())
                    ->where('user_id', $this->video->user_id)
                    ->value('notify');
            } catch (Throwable) {
                $bell = null;
            }
        }

        return self::wanted($bell, NotificationSetting::allows($notifiable, 'new_videos'), self::isHighlight($this->video));
    }

    /**
     * The rule of the per-channel bell and the global switch: an explicit bell wins, else the switch.
     */
    public static function wanted(?string $bell, bool $globalSwitch, bool $highlight): bool
    {
        return match ($bell) {
            NotificationSetting::BELL_NONE => false,
            NotificationSetting::BELL_HIGHLIGHTS => $highlight,
            NotificationSetting::BELL_ALL => true,
            default => $globalSwitch,
        };
    }

    /** A highlight of a channel: one of its videos, not one of its Shorts. */
    public static function isHighlight(Video $video): bool
    {
        return ! (bool) ($video->is_short ?? false);
    }

    /**
     * The bell's data: the text and its values (translated when shown), the video's link, the channel and the video (the
     * row shows the channel's avatar and the video's picture).
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('new_video', [
            'name' => $this->video->user?->username,
            'title' => $this->video->title,
        ], [
            'url' => $this->video->route,
            'actor_id' => $this->video->user_id !== null ? (int) $this->video->user_id : null,
            'video_id' => (int) $this->video->getKey(),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
