<?php

namespace App\Support;

use App\Models\Video;
use App\Services\VideoDatabaseSearchService;
use Illuminate\Support\Facades\Cache;

/**
 * "Trending Searches" chips of the Ctrl+K spotlight modal.
 *
 * The suggestions are fixed, but each one is only shown when its search finds videos on this site:
 * catalogs differ per install (an XVIDAPI-only site has no "English Subtitle" videos), and a chip that
 * opens an empty result page reads as a broken link. Checked with the same database search the
 * /search page uses, cached per locale for an hour.
 */
class SpotlightChips
{
    private const CHIPS = [
        ['label' => 'Japanese', 'q' => 'Japanese', 'icon' => 'text-info'],
        ['label' => 'English Subtitle', 'q' => 'English Subtitle', 'icon' => 'text-warning'],
        ['label' => '4K Ultra HD', 'q' => '4K', 'icon' => 'text-danger'],
    ];

    /**
     * @return array<int, array{label: string, q: string, icon: string}>
     */
    public static function all(): array
    {
        try {
            return Cache::remember('spotlight_chips.' . app()->getLocale(), now()->addHour(), function (): array {
                $search = app(VideoDatabaseSearchService::class);

                return array_values(array_filter(self::CHIPS, fn (array $chip): bool => $search
                    ->apply(Video::query()->active()->forLocale(), $chip['q'], $search->hasTagMatch($chip['q']))
                    ->exists()));
            });
        } catch (\Throwable) {
            return self::CHIPS;
        }
    }
}
