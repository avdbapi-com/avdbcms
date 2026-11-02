<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The addresses announced to IndexNow in the last hour (round 1b judge R1B-026). A member flipping the visibility of
 * their videos in a loop ("Make public", "Make private", "Make public" ...) announced the same addresses every time,
 * each announcement holding a PHP worker for up to 8 seconds and flooding the site's IndexNow key; saving a video's
 * details again did the same for one address. An address is now announced at most once an hour (per IndexNow key: a
 * key the owner sets up again announces at once); the next change after that hour announces it again.
 *
 * One cache entry per address; Cache::add() is first-come, so two requests side by side announce an address once.
 * Without a working cache an address is announced as before.
 */
final class IndexNowMemory
{
    /** How long an announced address is remembered. */
    public const SECONDS = 3600;

    /**
     * The addresses of $urls that were not announced in the last hour (each once, empty ones left out), remembered as
     * announced from now on.
     *
     * @param  iterable<mixed>  $urls
     * @return list<string>
     */
    public static function fresh(iterable $urls): array
    {
        $key = (string) config('site.indexnow_key', '');
        $fresh = [];

        foreach ($urls as $url) {
            $url = is_string($url) ? trim($url) : '';

            if ($url === '' || in_array($url, $fresh, true)) {
                continue;
            }

            try {
                if (! Cache::add(self::cacheKey($key, $url), 1, self::SECONDS)) {
                    continue;
                }
            } catch (Throwable) {
                // no cache: announce as before
            }

            $fresh[] = $url;
        }

        return $fresh;
    }

    private static function cacheKey(string $indexNowKey, string $url): string
    {
        return 'indexnow:announced:'.hash('sha256', $indexNowKey."\n".$url);
    }
}
