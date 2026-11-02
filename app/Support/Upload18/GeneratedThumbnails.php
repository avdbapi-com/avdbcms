<?php

namespace App\Support\Upload18;

use App\Data\UploadSettings;
use App\Enums\ThumbnailStatus;
use App\Models\Thumbnail;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The generated thumbnails of a member's upload: the frames the member's browser captured (base64 JPEG) and the ones the
 * server makes (ffmpeg frames, poster cards). Every picture passes JpegImage before it is stored (security plan S20).
 */
final class GeneratedThumbnails
{
    /** At most this many frames ride along with one upload's finish. */
    public const MAX_FRAMES = 5;

    /** The JPEG bytes of one base64 frame ("data:image/jpeg;base64,..." or plain base64), or null when it is no picture. */
    public static function decode(string $payload, int $maxDecodedBytes): ?string
    {
        $base64 = self::normalize($payload);

        if ($base64 === '' || self::estimatedBytes($base64) > $maxDecodedBytes) {
            return null;
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false || $decoded === '' || strlen($decoded) > $maxDecodedBytes) {
            return null;
        }

        return JpegImage::sanitize($decoded, $maxDecodedBytes);
    }

    /** The decoded size of a base64 payload, without decoding it. */
    public static function estimatedBytes(string $payload): int
    {
        $base64 = self::normalize($payload);

        if ($base64 === '') {
            return 0;
        }

        $padding = substr_count(substr($base64, -2), '=');

        return max(0, (int) floor((strlen($base64) * 3) / 4) - $padding);
    }

    /**
     * The usable frames of an upload's finish (anything that is no JPEG picture is left out).
     *
     * @return list<string>
     */
    public static function fromPayload(mixed $rawThumbnails): array
    {
        if (! is_array($rawThumbnails) || $rawThumbnails === []) {
            return [];
        }

        $maxThumbnailBytes = UploadSettings::fromConfig()->maxThumbnailBytes();
        $images = [];

        foreach (array_slice(array_values($rawThumbnails), 0, self::MAX_FRAMES) as $base64) {
            if (! is_string($base64)) {
                continue;
            }

            $imageData = self::decode($base64, $maxThumbnailBytes);

            if ($imageData !== null) {
                $images[] = $imageData;
            }
        }

        return $images;
    }

    /**
     * Store $images as the video's generated thumbnails (the first one active), in place of its earlier generated and
     * pending ones. A member's own uploaded thumbnail stays. Pictures that did not come from decode() ($clean false:
     * the server's own ffmpeg frames and poster cards) pass JpegImage here; nothing else is ever stored.
     *
     * @param  list<string>  $images  JPEG bytes
     * @return list<array{id: int, url: string, status: int, is_active: bool}>
     */
    public static function replace(Video $video, array $images, bool $clean = false): array
    {
        $maxThumbnailBytes = UploadSettings::fromConfig()->maxThumbnailBytes();
        $pictures = [];

        foreach ($images as $imageData) {
            $jpeg = ! is_string($imageData) ? null : ($clean ? $imageData : JpegImage::sanitize($imageData, $maxThumbnailBytes));

            if ($jpeg !== null && $jpeg !== '') {
                $pictures[] = $jpeg;
            }
        }

        if ($pictures === []) {
            return [];
        }

        $video->thumbnails()
            ->where(function ($q) {
                $q->where('status', ThumbnailStatus::GENERATED)
                    ->orWhere('status', ThumbnailStatus::PENDING);
            })
            ->where('video_id', $video->id)
            ->each(function (Thumbnail $thumbnail) {
                if ($thumbnail->file) {
                    Storage::disk('local')->delete(Video::THUMBNAIL_FOLDER.'/'.$thumbnail->file);
                }

                $thumbnail->delete();
            });

        $generated = [];
        $isFirst = true;

        foreach ($pictures as $imageData) {
            $filename = Str::uuid().'.jpg';
            Storage::disk('local')->put(Video::THUMBNAIL_FOLDER.'/'.$filename, $imageData);

            $thumbnail = $video->thumbnails()->create([
                'file' => $filename,
                'status' => ThumbnailStatus::GENERATED,
                'is_active' => $isFirst,
            ]);

            if ($isFirst) {
                $video->thumbnails()
                    ->where('id', '!=', $thumbnail->id)
                    ->update(['is_active' => false]);
            }

            $generated[] = [
                'id' => $thumbnail->id,
                'url' => $thumbnail->url,
                'status' => $thumbnail->status->value,
                'is_active' => (bool) $thumbnail->is_active,
            ];

            $isFirst = false;
        }

        return $generated;
    }

    /** Waiting thumbnails of a draft whose pictures are not made yet (the first one active). */
    public static function addPending(Video $video): void
    {
        for ($i = 0; $i < Video::generatedThumbnailCount(); $i++) {
            $video->thumbnails()->create([
                'status' => ThumbnailStatus::PENDING,
                'is_active' => $i === 0,
            ]);
        }
    }

    public static function readyCount(Video $video): int
    {
        return $video->thumbnails()
            ->whereIn('status', [ThumbnailStatus::GENERATED, ThumbnailStatus::UPLOADED])
            ->count();
    }

    private static function normalize(string $payload): string
    {
        $base64 = preg_replace('/^data:image\/[A-Za-z0-9.+-]+;base64,/i', '', trim($payload)) ?? '';

        return preg_replace('/\s+/', '', $base64) ?? '';
    }
}
