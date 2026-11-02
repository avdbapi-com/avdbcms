<?php

namespace App\Support\Upload18;

use App\Support\DurationParser;

/**
 * One video of the Upload18 account as GET /myvideo (or /getvideodetail) lists it, read defensively: Upload18 sends its
 * numbers as numbers or strings, and older answers lack some fields.
 *
 *  - did: Upload18's document id ("id"), what the upload answered as "did";
 *  - vid: the embed hash, empty until Upload18 made it;
 *  - zt: 0 pending, 1 converting, 2 done (API V2.1);
 *  - sizeBytes: the stored file's size in bytes (size_bytes; equals the uploaded file's size), null when not given;
 *  - durationSeconds: the length (duration_seconds, else the "1:58:09" text), null when not known yet;
 *  - duplicate: Upload18 says this is an item it already had (duplicate = 1) instead of a new one.
 */
final class Upload18Item
{
    public function __construct(
        public readonly string $did,
        public readonly string $vid,
        public readonly string $zt,
        public readonly ?int $sizeBytes,
        public readonly ?float $durationSeconds,
        public readonly bool $duplicate,
        public readonly mixed $duration = null,
    ) {}

    /** The item of an Upload18 answer, or null when it carries no id. */
    public static function fromArray(mixed $item): ?self
    {
        if (! is_array($item)) {
            return null;
        }

        $did = self::text($item['id'] ?? $item['did'] ?? null);

        if ($did === '') {
            return null;
        }

        return new self(
            did: $did,
            vid: self::text($item['vid'] ?? null),
            zt: self::text($item['zt'] ?? null) ?: '0',
            sizeBytes: self::bytes($item),
            durationSeconds: self::seconds($item),
            duplicate: self::flag($item['duplicate'] ?? null),
            duration: $item['duration'] ?? null,
        );
    }

    public function ready(): bool
    {
        return $this->vid !== '' && $this->zt === '2';
    }

    /** The length for the videos table (whole seconds), null when Upload18 does not know it yet. */
    public function durationForVideo(): ?int
    {
        return $this->durationSeconds !== null ? max(1, (int) round($this->durationSeconds)) : null;
    }

    /**
     * The status answer the upload page reads (the shape of Upload18Service::statusFromVideo()).
     *
     * @return array{found: bool, ready: bool, vid: string, zt: string, duration: mixed, duration_seconds: int|null}
     */
    public function status(): array
    {
        return [
            'found' => true,
            'ready' => $this->ready(),
            'vid' => $this->vid,
            'zt' => $this->zt,
            'duration' => $this->duration,
            'duration_seconds' => DurationParser::toSeconds($this->duration) ?? $this->durationForVideo(),
        ];
    }

    /**
     * Upload18's answers say yes as 1, "1", true or "true"; anything else (0, missing) is no.
     */
    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    private static function text(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }

        return is_string($value) ? trim($value) : '';
    }

    private static function bytes(array $item): ?int
    {
        foreach (['size_bytes', 'filesize', 'file_size', 'size'] as $field) {
            $value = $item[$field] ?? null;

            // "size" may be a text such as "3.16 MB" on some answers: only a whole number of bytes counts.
            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (is_string($value) && preg_match('/^\d{1,18}$/', trim($value)) === 1 && (int) trim($value) > 0) {
                return (int) trim($value);
            }

            if (is_float($value) && $value > 0 && floor($value) === $value) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function seconds(array $item): ?float
    {
        $value = $item['duration_seconds'] ?? null;

        if (is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }

        $parsed = DurationParser::toSeconds($item['duration'] ?? null);

        return $parsed !== null && $parsed > 0 ? (float) $parsed : null;
    }
}
