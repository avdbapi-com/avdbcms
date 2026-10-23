<?php

use App\Data\NotificationBroadcastSettings;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = NotificationBroadcastSettings::settingsDefaults();

        if (!Setting::query()->where('key', 'broadcast_notification_chunk_size')->exists()) {
            Setting::set(
                'broadcast_notification_chunk_size',
                (string) $defaults['broadcast_notification_chunk_size'],
                'notifications',
            );
        }

        Setting::clearCache(['broadcast_notification_chunk_size'], ['notifications']);
    }

    public function down(): void
    {
        Setting::query()
            ->where('key', 'broadcast_notification_chunk_size')
            ->where('group', 'notifications')
            ->delete();

        Setting::clearCache(['broadcast_notification_chunk_size'], ['notifications']);
    }
};
