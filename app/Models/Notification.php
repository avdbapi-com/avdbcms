<?php

namespace App\Models;

use App\Notifications\Support\NotificationText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin IdeHelperNotification
 */
class Notification extends DatabaseNotification
{
    protected $guarded = ['id'];

    /** The icon kind of each notification type (the bell shows one icon per kind). */
    private const KINDS = [
        'App\\Notifications\\Activity\\NewVideo' => 'video',
        'App\\Notifications\\Activity\\NewComment' => 'comment',
        'App\\Notifications\\Activity\\NewSubscriber' => 'subscriber',
        'App\\Notifications\\Video\\VideoUploaded' => 'ready',
        'App\\Notifications\\Video\\VideoError' => 'failed',
        'App\\Notifications\\Video\\VideoBanned' => 'suspended',
        'App\\Notifications\\Export\\ExportSuccess' => 'export',
        'App\\Notifications\\Export\\ExportError' => 'failed',
        'App\\Notifications\\Premium\\Invoice' => 'invoice',
        NotificationText::BROADCAST_TYPE => 'message',
    ];

    /**
     * Get the notifiable entity that the notification belongs to.
     *
     * @return MorphTo
     */
    public function user() : MorphTo
    {
        return $this->notifiable();
    }


    /**
     * -------------------- ATTRIBUTES --------------------
     */

    public function isRead() : Attribute {
        return Attribute::make(
            get: fn () => $this->read()
        );
    }

    /** The sentence in the current language (translated when shown; old rows too). */
    public function message() : Attribute {
        return Attribute::make(
            get: fn () => NotificationText::message($this->type, $this->data)
        );
    }

    /** The owner's title of a notification an administrator sent, else null. */
    public function title() : Attribute {
        return Attribute::make(
            get: fn () => NotificationText::title($this->type, $this->data)
        );
    }

    /** video, comment, subscriber, ready, failed, suspended, export, invoice, message or other. */
    public function kind() : Attribute {
        return Attribute::make(
            get: fn () => self::kindOf($this->type)
        );
    }

    /** The icon kind of a notification type (the list and the live push use the same). */
    public static function kindOf(?string $type) : string {
        return self::KINDS[(string) $type] ?? 'other';
    }

    public function url() : Attribute {
        return Attribute::make(
            get: fn () => is_string($this->data['url'] ?? null) ? $this->data['url'] : null
        );
    }
}
