<?php

namespace App\Notifications\Activity;

use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "x subscribed to your channel." Only when the creator wants it (Account settings > Notifications > New subscribers),
 * and once per subscriber in REPEAT_DAYS: subscribing, unsubscribing and subscribing again no longer lists the same
 * member three times (watch-17).
 */
class NewSubscriber extends Notification
{
    use BellNotification;

    /** A member who subscribes again within this many days is not announced again. */
    public const REPEAT_DAYS = 30;

    private User $subscriber;

    /** @var array<int, bool> the decision per notifiable (shouldSend() is asked once per channel) */
    private array $decided = [];

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(User $subscriber)
    {
        $this->subscriber = $subscriber;
    }

    /** Whether the creator gets it: their switch, and not announced for this subscriber in the last 30 days. */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        $id = (int) $notifiable->getKey();

        if (! array_key_exists($id, $this->decided)) {
            $this->decided[$id] = NotificationSetting::allows($notifiable, 'new_subscribers') && $this->firstInAWhile($notifiable);
        }

        return $this->decided[$id];
    }

    /**
     * The bell's data: who subscribed (translated when shown), their channel's link and their member number (the row
     * shows their avatar).
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('new_subscriber', [
            'name' => $this->subscriber->username,
        ], [
            'url' => route('user.show', $this->subscriber),
            'actor_id' => (int) $this->subscriber->getKey(),
            'is_read' => false,
            'created_at' => now(),
        ]);
    }

    private function firstInAWhile(User $notifiable): bool
    {
        try {
            return Cache::add(
                'notifications.new-subscriber.'.$notifiable->getKey().'.'.$this->subscriber->getKey(),
                1,
                now()->addDays(self::REPEAT_DAYS)
            );
        } catch (Throwable) {
            return true;
        }
    }
}
