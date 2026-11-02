<?php

namespace App\Support;

use App\Models\Video;
use App\Services\VideoDatabaseSearchService;

/**
 * "Trending Searches" chips of the Ctrl+K spotlight modal.
 *
 * The suggestions are fixed, but each one is only shown when its search finds videos on this site:
 * catalogs differ per install (an XVIDAPI-only site has no "English Subtitle" videos), and a chip that
 * opens an empty result page reads as a broken link. Checked with the same database search the
 * /search page uses, current for an hour per locale. A page's check is bounded (look-around DB-X2):
 * where no index serves a match (the exact and LIKE fallbacks), it reads the newest videos only
 * (VideoDatabaseSearchService::findsVideos()), so a cold page never reads the whole catalogue for its
 * three chips; a chip it does not find there is checked against every video by the scheduler's task
 * (App\Support\DiscoverySnapshots) and shown from then on, so the chips are the ones 1.2.2 showed and
 * a chip never opens an empty page.
 */
class SpotlightChips
{
    private const CHIPS = [
        ['label' => 'Japanese', 'q' => 'Japanese', 'icon' => 'text-info'],
        ['label' => 'English Subtitle', 'q' => 'English Subtitle', 'icon' => 'text-warning'],
        ['label' => '4K Ultra HD', 'q' => '4K', 'icon' => 'text-danger'],
    ];

    /** How long the chips are current (seconds): an hour, as since 1.2.0. */
    public const CACHE_SECONDS = 3600;

    /**
     * @return array<int, array{label: string, q: string, icon: string}>
     */
    public static function all(): array
    {
        try {
            return DiscoverySnapshots::answer('chips', ['locale' => app()->getLocale()], self::CACHE_SECONDS, function (): array {
                $search = app(VideoDatabaseSearchService::class);
                $found = [];
                $settled = true;

                // 1.2.3 round 3: the search page's own rules, in the queries this check always ran (findsVideos()); each
                // row's creator checked as it comes (MySQL 8 otherwise read every video for it: DB-X2)
                foreach (self::CHIPS as $chip) {
                    if ($search->findsVideos(Video::query()->active(true)->forLocale(), $chip['q'])) {
                        $found[] = $chip;
                    } elseif ($search->lastCheckWasBounded()) {
                        $settled = false; // an older video may match: the background checks them all
                    }
                }

                return [$found, $settled];
            });
        } catch (\Throwable) {
            return self::CHIPS;
        }
    }

    /**
     * The chips for $params (their locale), each checked against every video (the scheduler's task,
     * App\Support\DiscoverySnapshots).
     *
     * @param  array<string, scalar>  $params
     * @return array<int, array{label: string, q: string, icon: string}>
     */
    public function exactChips(array $params = []): array
    {
        $search = app(VideoDatabaseSearchService::class);

        return array_values(array_filter(self::CHIPS, fn (array $chip): bool => $search
            ->findsVideos(Video::query()->active(true)->forLocale(), $chip['q'], false)));
    }
}
