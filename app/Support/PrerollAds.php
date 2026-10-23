<?php

namespace App\Support;

/**
 * Parses the "Pre-roll Video URLs" monetization setting.
 *
 * One ad per line: VIDEO_URL | CLICK_URL. The click URL is optional; lines without one use the
 * default click URL setting. Lines without "|" may still hold several comma-separated video URLs
 * (the original format), and lines starting with "#" are ignored.
 */
class PrerollAds
{
    /**
     * @return array<int, array{src: string, click: string}>
     */
    public static function parse(?string $value, ?string $defaultClick = null): array
    {
        $default = self::clickUrl($defaultClick);
        $ads = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '|')) {
                [$source, $click] = array_map('trim', explode('|', $line, 2));
                $sources = [$source];
            } else {
                $click = '';
                $sources = preg_split('/\s*,\s*/', $line) ?: [];
            }

            $click = self::clickUrl($click) ?: $default;

            foreach ($sources as $source) {
                $source = self::mediaUrl($source);
                if ($source !== '') {
                    $ads[] = ['src' => $source, 'click' => $click];
                }
            }
        }

        return $ads;
    }

    /**
     * The video URL as-is when it is an http(s) URL or a site-relative path.
     */
    public static function mediaUrl(?string $url): string
    {
        $url = trim((string) $url);

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return preg_match('#^https?://\S+$#i', $url) ? $url : '';
    }

    /**
     * Click-through target: only absolute http(s) URLs, so nothing like javascript: reaches the player.
     */
    public static function clickUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }
}
