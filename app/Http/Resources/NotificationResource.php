<?php

namespace App\Http\Resources;

use App\Models\Notification;
use App\Notifications\Support\NotificationText;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

/**
 * One notification of the bell: its sentence in the reader's language (NotificationText, translated now, not when it
 * was sent), the owner's title for a notification an administrator sent, a kind for the icon, the link, read or not.
 *
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request) : array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'is_read' => $this->read_at !== null,
            'created_at' => $this->created_at
        ];
    }
}
