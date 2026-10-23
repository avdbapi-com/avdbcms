<?php

namespace Tests\Unit;

use App\Data\NotificationBroadcastSettings;
use App\Data\UploadSettings;
use App\Models\Subscription;
use PHPUnit\Framework\TestCase;

class RuntimeSettingsValueObjectsTest extends TestCase
{
    public function test_upload_settings_are_bounded_and_expose_thumbnail_bytes(): void
    {
        $settings = UploadSettings::fromSettingsArray([
            'upload18_chunk_size_mb' => 999,
            'upload18_thumbnail_count' => 0,
            'max_thumbnail_size' => 256,
        ]);

        $this->assertSame(UploadSettings::MAX_CHUNK_SIZE_MB, $settings->chunkSizeMb);
        $this->assertSame(UploadSettings::MIN_THUMBNAIL_COUNT, $settings->thumbnailCount);
        $this->assertSame(256, $settings->maxThumbnailSizeKb);
        $this->assertSame(256 * 1024, $settings->maxThumbnailBytes());
    }

    public function test_broadcast_settings_are_bounded(): void
    {
        $low = NotificationBroadcastSettings::fromSettingsArray([
            'broadcast_notification_chunk_size' => 1,
        ]);
        $high = NotificationBroadcastSettings::fromSettingsArray([
            'broadcast_notification_chunk_size' => 999999,
        ]);

        $this->assertSame(NotificationBroadcastSettings::MIN_CHUNK_SIZE, $low->chunkSize);
        $this->assertSame(NotificationBroadcastSettings::MAX_CHUNK_SIZE, $high->chunkSize);
    }

    public function test_subscription_trial_accessor_returns_false_for_missing_trial_date(): void
    {
        $subscription = new Subscription();
        $subscription->forceFill(['trial_ends_at' => null]);

        $this->assertFalse($subscription->on_trial);
    }
}
