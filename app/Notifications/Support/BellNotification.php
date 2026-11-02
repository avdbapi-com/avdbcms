<?php

namespace App\Notifications\Support;

use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * A notification of the member's bell: stored in the database (its text is translated when it is shown, see
 * NotificationText), and pushed live only when the site has a live broadcaster (Realtime). The live payload carries the
 * sentence already in the member's language (also in parts, the names in bold: NotificationText::parts) and the kind
 * (its icon), in the shape the bell's list uses.
 */
trait BellNotification
{
    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return Realtime::channels(['database']);
    }

    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        $data = $this->toArray($notifiable);
        $locale = Realtime::localeOf($notifiable);

        return new BroadcastMessage([
            'message' => NotificationText::message(static::class, $data, $locale),
            'parts' => NotificationText::parts(static::class, $data, $locale),
            'title' => NotificationText::title(static::class, $data),
            'kind' => BellResource::kindOf(static::class),
            'url' => $data['url'] ?? null,
            'is_read' => false,
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
