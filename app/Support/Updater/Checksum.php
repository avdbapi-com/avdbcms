<?php

namespace App\Support\Updater;

use RuntimeException;

/**
 * SHA-256 of a release download. Each zip has a ".sha256" asset in sha256sum format: one line "<64 hex>  <name>"
 * (text mode) or "<64 hex> *<name>" (binary mode), or the bare hash; GitHub also reports a "digest" per asset
 * ("sha256:<hex>"). Files are hashed in 8 MB reads, so a big zip never sits in memory; the update's verify step hashes
 * its zip one PIECE_BYTES piece per call of piece(), across requests where it has to (RP4-01).
 */
final class Checksum
{
    /** A .sha256 file is one short line; anything bigger is not one. */
    public const MAX_BYTES = 1024;

    /**
     * Bytes one call of piece() hashes: small enough that one piece always ends well inside the 8 seconds a step keeps
     * before PHP's time limit, even on a disk that reads 1 MB a second (a shared host under load, RP4), and few enough
     * pieces for a release zip (62 MB: 16) that a quiet host notices no difference.
     */
    public const PIECE_BYTES = 4 * 1024 * 1024;

    private const READ_BYTES = 8 * 1024 * 1024;

    /**
     * The hash in a .sha256 file (lower case), or null when the text is not exactly one valid line for $name:
     * a wrong file name, extra lines, a hash that is not 64 hex digits, or more than MAX_BYTES.
     */
    public static function parse(string $text, string $name): ?string
    {
        if (strlen($text) > self::MAX_BYTES) {
            return null;
        }

        // One line, with or without its line break (\n or \r\n).
        $line = preg_replace('/\r?\n\z/', '', $text);

        if (! is_string($line) || $line === '' || str_contains($line, "\n") || str_contains($line, "\r")) {
            return null;
        }

        if (preg_match('/^([0-9a-fA-F]{64})(?:(?: \*| {2})(\S(?:.*\S)?))?$/', $line, $match) !== 1) {
            return null;
        }

        if (isset($match[2]) && $match[2] !== $name) {
            return null;
        }

        return strtolower($match[1]);
    }

    /** "sha256:<hex>" (the API's asset digest) -> "<hex>"; anything else -> null. */
    public static function fromDigest(mixed $digest): ?string
    {
        if (! is_string($digest) || preg_match('/^sha256:([0-9a-fA-F]{64})$/', $digest, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    /** SHA-256 of a file, read in 8 MB pieces. */
    public static function file(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot read '.basename($path));
        }

        try {
            $context = hash_init('sha256');

            while (! feof($handle)) {
                if (hash_update_stream($context, $handle, self::READ_BYTES) === 0 && ! feof($handle)) {
                    throw new RuntimeException('Cannot read '.basename($path));
                }
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }

    /**
     * One PIECE_BYTES piece of a file's SHA-256, continued from $progress (what the call before returned; null: from
     * the first byte). The hash context travels serialized in 'context' (PHP 8.1+), so the pieces can be hashed by
     * different requests: the update's verify step saves the progress in plan.json after each piece and stops at its
     * deadline, and the next request goes on where it stopped (RP4-01: on a slow disk the hash of a 62 MB zip alone
     * took longer than a 30 s host limit, and each killed request started it from the first byte again). 'sha256' is
     * set once the last byte is in. A $progress of another file (its size or mtime differ, or the bytes done are not
     * within it) or one whose context cannot be read starts over from the first byte, never with an error.
     *
     * @param  array<string, mixed>|null  $progress  {context, done, size, mtime} of the call before
     * @return array{done: int, size: int, mtime: int, context?: string, sha256?: string}
     */
    public static function piece(string $path, ?array $progress): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        $mtime = @filemtime($path);
        $handle = $size === false || $mtime === false ? false : @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot read '.basename($path));
        }

        try {
            $context = null;
            $done = 0;

            if (is_array($progress) && ($progress['size'] ?? null) === $size && ($progress['mtime'] ?? null) === $mtime
                && is_int($progress['done'] ?? null) && $progress['done'] > 0 && $progress['done'] < $size) {
                $context = self::thaw($progress['context'] ?? null);
                $done = $context === null ? 0 : $progress['done'];
            }

            if ($done > 0 && fseek($handle, $done) !== 0) {
                $context = null;
                $done = 0;
            }

            $context ??= hash_init('sha256');
            $read = hash_update_stream($context, $handle, self::PIECE_BYTES);

            if ($read === 0 && $done < $size) {
                throw new RuntimeException('Cannot read '.basename($path));
            }

            $done += $read;
            $result = ['done' => $done, 'size' => $size, 'mtime' => $mtime];

            if ($done >= $size) {
                return $result + ['sha256' => hash_final($context)];
            }

            return $result + ['context' => base64_encode(serialize($context))];
        } finally {
            fclose($handle);
        }
    }

    /** The hash context piece() handed out in 'context', or null when the text is not one (then the hash starts over). */
    private static function thaw(mixed $context): ?\HashContext
    {
        if (! is_string($context) || $context === '') {
            return null;
        }

        $raw = base64_decode($context, true);

        // Only a serialized SHA-256 context of PHP's own HashContext class; anything else is not even unserialized.
        if (! is_string($raw) || preg_match('/^O:11:"HashContext":\d+:\{i:0;s:6:"sha256";/', $raw) !== 1) {
            return null;
        }

        try {
            $thawed = @unserialize($raw, ['allowed_classes' => [\HashContext::class], 'max_depth' => 4]);
        } catch (\Throwable) {
            return null;
        }

        return $thawed instanceof \HashContext ? $thawed : null;
    }

    /** True when $actual equals the expected hash (constant-time compare, case-insensitive hex). */
    public static function matches(string $expected, string $actual): bool
    {
        return strlen($expected) === 64 && hash_equals(strtolower($expected), strtolower($actual));
    }
}
