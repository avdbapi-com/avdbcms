<?php

namespace App\Support\Updater;

use RuntimeException;

/**
 * SHA-256 of a release download. Each zip has a ".sha256" asset in sha256sum format: one line "<64 hex>  <name>"
 * (text mode) or "<64 hex> *<name>" (binary mode), or the bare hash; GitHub also reports a "digest" per asset
 * ("sha256:<hex>"). Files are hashed in 8 MB reads, so a big zip never sits in memory.
 */
final class Checksum
{
    /** A .sha256 file is one short line; anything bigger is not one. */
    public const MAX_BYTES = 1024;

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

    /** True when $actual equals the expected hash (constant-time compare, case-insensitive hex). */
    public static function matches(string $expected, string $actual): bool
    {
        return strlen($expected) === 64 && hash_equals(strtolower($expected), strtolower($actual));
    }
}
