<?php

namespace App\Support;

/**
 * Where list ads go, on the server: the wide ad box of the <x-videos-area> skeleton and the card ad of the channel
 * page grids. KEEP IN SYNC with resources/js/components/Videos/feedSlots.ts (VideosList), which follows the same
 * rule; tests/Fixtures/feed_ad_slots.json holds the cases both tests run.
 *
 * "Ad after every N" (Money > Ads) means N videos come before each ad:
 * - a card ad takes the place after N videos (place N+1, then every N videos again) and only stands between two
 *   videos, so a list of N videos or fewer shows none;
 * - a full-width row goes between two rows, after the row that holds video N, 2N, ... (one column: right after
 *   video N; the last video when that row is the list's last and not full).
 * "Most ads per list" (0 = no limit) caps both.
 */
final class FeedAdSlots
{
    /**
     * N: videos before each ad, from the ad_grid_position setting. 0 means no list ads (a stored 0 or junk).
     */
    public static function adEvery(mixed $position): int
    {
        if (is_int($position)) {
            return max(0, $position);
        }

        return is_string($position) && is_numeric(trim($position)) ? max(0, (int) trim($position)) : 0;
    }

    /**
     * "Most ads per list" (ad_grid_max_per_feed): 0 = no limit; a negative or non-numeric value counts as 1 (as
     * nonNegativeInteger() in VideosList.tsx reads window.AD_CONFIG.gridMaxPerFeed).
     */
    public static function maxAds(mixed $value): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : 1;
    }

    /**
     * Video numbers (1-based, ascending) after which a full-width ad row goes in a grid of $columns columns that
     * shows $videoCount videos. Mirrors wideAdVideoIndexes() in feedSlots.ts.
     *
     * @return list<int>
     */
    public static function wideAdAfter(int $videoCount, int $columns, int $adEvery, int $maxAds): array
    {
        $step = max(1, $adEvery);
        $after = [];

        for ($candidate = $step; $candidate <= $videoCount; $candidate += $step) {
            if ($maxAds > 0 && count($after) >= $maxAds) {
                break;
            }

            // The end of the row that holds video $candidate (1.2.1: the last full row before it).
            $position = $columns <= 1
                ? $candidate
                : min($videoCount, intdiv($candidate + $columns - 1, $columns) * $columns);
            $after[$position] = true;
        }

        $positions = array_keys($after);
        sort($positions);

        return $positions;
    }

    /**
     * The card ad of a fixed grid of $cells places (a channel page section): the number of the video it follows,
     * or 0 for no ad. The ad needs N videos before it, a video left to follow it ($available videos in the list)
     * and a free place in the grid, which then shows $cells - 1 videos and the ad.
     */
    public static function gridAdAfter(int $cells, int $available, int $adEvery): int
    {
        if ($adEvery < 1 || $cells <= $adEvery || $available <= $adEvery) {
            return 0;
        }

        return $adEvery;
    }

    /**
     * A list that shows one ad between its rows or items (search results, playlists, related videos): whether the
     * ad goes after item $iteration of $count (Blade's $loop->count; null when the list cannot be counted). Only
     * between two items: a list of N items or fewer shows none.
     */
    public static function listAdAfter(int $iteration, ?int $count, int $adEvery): bool
    {
        return $adEvery >= 1 && $iteration === $adEvery && ($count === null || $count > $adEvery);
    }
}
