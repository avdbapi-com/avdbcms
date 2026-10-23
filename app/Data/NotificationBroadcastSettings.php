<?php

namespace App\Data;

final readonly class NotificationBroadcastSettings
{
    public const int DEFAULT_CHUNK_SIZE = 500;
    public const int MIN_CHUNK_SIZE = 50;
    public const int MAX_CHUNK_SIZE = 5000;

    public function __construct(
        public int $chunkSize,
    ) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_CHUNK_SIZE);
    }

    public static function settingsDefaults(): array
    {
        return [
            'broadcast_notification_chunk_size' => self::DEFAULT_CHUNK_SIZE,
            'broadcast_notification_chunk_size_min' => self::MIN_CHUNK_SIZE,
            'broadcast_notification_chunk_size_max' => self::MAX_CHUNK_SIZE,
        ];
    }

    public static function fromSettingsArray(array $settings, ?self $fallback = null): self
    {
        $fallback ??= self::defaults();

        return new self(
            max(
                self::MIN_CHUNK_SIZE,
                min(self::MAX_CHUNK_SIZE, (int) ($settings['broadcast_notification_chunk_size'] ?? $fallback->chunkSize)),
            ),
        );
    }

    public static function fromConfig(): self
    {
        return self::fromSettingsArray([
            'broadcast_notification_chunk_size' => config('site.broadcast_notification_chunk_size', self::DEFAULT_CHUNK_SIZE),
        ]);
    }
}
