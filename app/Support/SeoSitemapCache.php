<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

final class SeoSitemapCache
{
    private const VERSION_KEY = 'seo.sitemap_cache_version';

    private const KEYS = [
        'seo.active_video_id_bounds',
        'seo.latest_active_video_updated_at',
        'seo.pages_sitemap_lastmod',
    ];

    public static function ttl(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public static function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, '0');
    }

    public static function clear(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget($key);
        }

        Cache::forever(self::VERSION_KEY, (string) now()->getTimestamp());
    }
}
