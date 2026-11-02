<?php

namespace App\Support\Upload18;

/**
 * Only real JPEG pictures become thumbnails (security plan S20): bytes that merely start with the JPEG marker (FF D8)
 * and carry HTML or script are refused.
 *
 * With GD (almost every host) the picture is decoded and encoded again, so what is stored is pixels written by this
 * server, nothing of the sender's bytes. Without GD the bytes are kept only when PHP's own image reader sees a JPEG with
 * a sane size; the thumbnail addresses then still answer only as an image with nosniff (VideoController), so such
 * bytes can never run as a page.
 */
final class JpegImage
{
    /** Bigger pictures are refused: decoding one needs width x height x 4 bytes of memory. */
    public const MAX_PIXELS = 12_000_000;

    public const MAX_SIDE = 8192;

    /** Quality of the stored picture, then lower ones while it is larger than the site's thumbnail limit. */
    private const QUALITIES = [90, 82, 72];

    /** The stored JPEG, or null when $bytes is not a picture this site keeps. */
    public static function sanitize(string $bytes, int $maxBytes): ?string
    {
        if ($bytes === '' || $maxBytes < 1 || strlen($bytes) > $maxBytes || ! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);

        if (! is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        if (! self::canReencode()) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        try {
            foreach (self::QUALITIES as $quality) {
                ob_start();

                try {
                    $written = @imagejpeg($image, null, $quality);
                } finally {
                    $encoded = (string) ob_get_clean();
                }

                if ($written && $encoded !== '' && strlen($encoded) <= $maxBytes && str_starts_with($encoded, "\xFF\xD8")) {
                    return $encoded;
                }
            }

            return null;
        } finally {
            unset($image);
        }
    }

    public static function canReencode(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }
}
