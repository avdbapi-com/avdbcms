<?php

namespace App\Notifications\Export;

use App\Models\Export;
use App\Models\User;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;

class ExportError extends Notification
{
    use BellNotification;

    private Export $export;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Export $export)
    {
        $this->export = $export;
    }

    /**
     * The bell's data: "Your export failed." (translated when shown) and the exports page.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('export_failed', [], [
            'url' => route('admin.exports.index'),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
