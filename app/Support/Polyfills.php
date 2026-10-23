<?php

/**
 * AVDBCMS — Global Runtime Polyfills.
 *
 * Provides compatibility fallbacks for environments missing common PHP extensions
 * (e.g. ext-fileinfo on low-RAM aaPanel / CyberPanel / cPanel installations).
 */

if (!defined('FILEINFO_MIME_TYPE')) {
    define('FILEINFO_MIME_TYPE', 16);
}

if (!class_exists('finfo', false)) {
    class finfo
    {
        public function __construct(int $flags = FILEINFO_MIME_TYPE, ?string $magicFile = null)
        {
        }

        public function file(string $filename, int $flags = FILEINFO_MIME_TYPE, $context = null): string|false
        {
            if (!file_exists($filename)) {
                return false;
            }
            $header = @file_get_contents($filename, false, null, 0, 2048);
            return $header !== false ? $this->buffer($header) : false;
        }

        public function buffer(string $string, int $flags = FILEINFO_MIME_TYPE, $context = null): string|false
        {
            if (str_starts_with($string, "\xff\xd8\xff")) {
                return 'image/jpeg';
            }
            if (str_starts_with($string, "\x89PNG\r\n\x1a\n")) {
                return 'image/png';
            }
            if (str_starts_with($string, 'GIF87a') || str_starts_with($string, 'GIF89a')) {
                return 'image/gif';
            }
            if (str_starts_with($string, 'RIFF') && substr($string, 8, 4) === 'WEBP') {
                return 'image/webp';
            }
            if (str_starts_with($string, '%PDF')) {
                return 'application/pdf';
            }
            if (str_starts_with($string, 'ID3') || str_starts_with($string, "\xff\xfb") || str_starts_with($string, "\xff\xf3")) {
                return 'audio/mpeg';
            }
            if (str_contains(substr($string, 0, 32), 'ftyp')) {
                return 'video/mp4';
            }

            return 'application/octet-stream';
        }
    }
}

if (!function_exists('finfo_open')) {
    /**
     * Procedural fileinfo fallback.
     *
     * Symfony's MIME guesser (used by Laravel's `mimes:`/`mimetypes:` upload
     * validation) gates on `function_exists('finfo_open')`. Without these wrappers
     * the guesser is skipped and any avatar/banner/thumbnail upload throws
     * "Unable to guess the MIME type as no guessers are available" (HTTP 500) on
     * hosts that ship without ext-fileinfo (aaPanel / CyberPanel / cPanel default).
     * These delegate to the finfo class polyfill defined above.
     */
    function finfo_open(int $flags = FILEINFO_MIME_TYPE, ?string $magicFile = null): finfo
    {
        return new finfo($flags, $magicFile);
    }

    function finfo_file(finfo $finfo, string $filename, int $flags = FILEINFO_MIME_TYPE, $context = null): string|false
    {
        return $finfo->file($filename, $flags, $context);
    }

    function finfo_buffer(finfo $finfo, string $string, int $flags = FILEINFO_MIME_TYPE, $context = null): string|false
    {
        return $finfo->buffer($string, $flags, $context);
    }

    function finfo_close(finfo $finfo): bool
    {
        return true;
    }
}
