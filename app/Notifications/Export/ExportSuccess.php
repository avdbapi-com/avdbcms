<?php

namespace App\Notifications\Export;

use App\Models\Export;
use App\Models\User;
use App\Notifications\Support\BellNotification;
use App\Notifications\Support\NotificationText;
use Illuminate\Notifications\Notification;

class ExportSuccess extends Notification
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
     * The bell's data: "Your export is ready: <file>" (translated when shown) and its download link.
     *
     * @param  User $notifiable
     * @return array
     */
    public function toArray(User $notifiable) : array
    {
        return NotificationText::data('export_ready', [
            'file' => $this->export->file,
        ], [
            'url' => route('admin.exports.download', $this->export),
            'created_at' => now(),
            'is_read' => false,
        ]);
    }
}
