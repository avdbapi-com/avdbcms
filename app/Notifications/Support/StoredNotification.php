<?php

namespace App\Notifications\Support;

use Illuminate\Notifications\Notification;

/**
 * A notification row written in a batch (a new video to the subscribers, an admin's notification to every member): the
 * live push of that row needs a notification with its id and type, as Laravel's own sending makes.
 */
final class StoredNotification extends Notification
{
    public function __construct(string $id, private readonly string $storedType)
    {
        $this->id = $id;
    }

    public function broadcastType(): string
    {
        return $this->storedType;
    }
}
