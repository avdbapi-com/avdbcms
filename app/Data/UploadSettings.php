<?php

namespace App\Data;

final readonly class UploadSettings
{
    public const int DEFAULT_CHUNK_SIZE_MB = 90;
    public const int MIN_CHUNK_SIZE_MB = 5;
    public const int MAX_CHUNK_SIZE_MB = 200;

    public const int DEFAULT_THUMBNAIL_COUNT = 3;
    public const int MIN_THUMBNAIL_COUNT = 1;
    public const int MAX_THUMBNAIL_COUNT = 5;

    public const int DEFAULT_MAX_THUMBNAIL_SIZE_KB = 2048;
    public const int MIN_MAX_THUMBNAIL_SIZE_KB = 128;
    public const int MAX_MAX_THUMBNAIL_SIZE_KB = 20480;

    public function __construct(
        public int $chunkSizeMb,
        public int $thumbnailCount,
        public int $maxThumbnailSizeKb,
    ) {}

    public static function defaults(): self
    {
        return new self(
            chunkSizeMb: self::DEFAULT_CHUNK_SIZE_MB,
            thumbnailCount: self::DEFAULT_THUMBNAIL_COUNT,
            maxThumbnailSizeKb: self::DEFAULT_MAX_THUMBNAIL_SIZE_KB,
        );
    }

    public static function settingsDefaults(): array
    {
        return [
            'upload18_chunk_size_mb' => self::DEFAULT_CHUNK_SIZE_MB,
            'upload18_chunk_size_mb_min' => self::MIN_CHUNK_SIZE_MB,
            'upload18_chunk_size_mb_max' => self::MAX_CHUNK_SIZE_MB,
            'upload18_thumbnail_count' => self::DEFAULT_THUMBNAIL_COUNT,
            'upload18_thumbnail_count_min' => self::MIN_THUMBNAIL_COUNT,
            'upload18_thumbnail_count_max' => self::MAX_THUMBNAIL_COUNT,
            'max_thumbnail_size' => self::DEFAULT_MAX_THUMBNAIL_SIZE_KB,
            'max_thumbnail_size_min' => self::MIN_MAX_THUMBNAIL_SIZE_KB,
            'max_thumbnail_size_max' => self::MAX_MAX_THUMBNAIL_SIZE_KB,
        ];
    }

    public static function fromSettingsArray(array $settings, ?self $fallback = null): self
    {
        $fallback ??= self::defaults();

        return new self(
            chunkSizeMb: self::boundedInt(
                $settings['upload18_chunk_size_mb'] ?? $fallback->chunkSizeMb,
                self::MIN_CHUNK_SIZE_MB,
                self::MAX_CHUNK_SIZE_MB,
            ),
            thumbnailCount: self::boundedInt(
                $settings['upload18_thumbnail_count'] ?? $fallback->thumbnailCount,
                self::MIN_THUMBNAIL_COUNT,
                self::MAX_THUMBNAIL_COUNT,
            ),
            maxThumbnailSizeKb: self::boundedInt(
                $settings['max_thumbnail_size'] ?? $fallback->maxThumbnailSizeKb,
                self::MIN_MAX_THUMBNAIL_SIZE_KB,
                self::MAX_MAX_THUMBNAIL_SIZE_KB,
            ),
        );
    }

    public static function fromConfig(): self
    {
        return self::fromSettingsArray([
            'upload18_chunk_size_mb' => config('site.upload18_chunk_size_mb', self::DEFAULT_CHUNK_SIZE_MB),
            'upload18_thumbnail_count' => config('site.upload18_thumbnail_count', self::DEFAULT_THUMBNAIL_COUNT),
            'max_thumbnail_size' => config('site.max_thumbnail_size', self::DEFAULT_MAX_THUMBNAIL_SIZE_KB),
        ]);
    }

    public function maxThumbnailBytes(): int
    {
        return $this->maxThumbnailSizeKb * 1024;
    }

    private static function boundedInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
