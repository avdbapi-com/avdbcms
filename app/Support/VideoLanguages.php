<?php

namespace App\Support;

use App\Models\Video;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The languages of a video's watch page, two rules in one place (1.2.3 round 3: R3-017 and the orchestrator's decision
 * of 29 Oct, "the page follows the lists, and such pages are not indexed").
 *
 * VISIBLE (visible()): where the watch page opens for a visitor. Every video opens in every language of the site. The
 * lists of a language show an imported video there whether or not it has a translation of that language
 * (Video::scopeForLocale(), the search, the public API's feeds), so its page opens there too, with the video's own
 * texts where it has none; a member's upload always did. App\Policies\VideoPolicy::show() and its one-query twin
 * App\Support\ShownToMember::videos() (like, save, report, comment likes) therefore have no language condition; bans,
 * deleted channels, private videos and the admin's rule are theirs. (Up to RC2 an AVDBAPI import without a translation
 * answered with a 404 page (not available in this language) in that language, while its card was in the lists.)
 *
 * INDEXABLE (indexable(), constrainIndexable(), indexableIn()): where search engines are offered that page. An imported
 * AVDBAPI video is indexable in English, in the site's fallback language (its default language) and in each language
 * it has a stored translation of (video_translations); every other video in every language. The video sitemaps
 * (App\Http\Controllers\SeoController) list only indexable pages, the language alternates (the sitemaps' and the
 * watch page's <link rel="alternate" hreflang>) name only those, and a watch page that opens where it is not indexable
 * answers "X-Robots-Tag: noindex, follow" and says the same in its robots meta tag (robots(),
 * App\Http\Controllers\VideoController::showVideo()).
 *
 * An administrator with the admin's powers here sees every video in every language (the callers apply that,
 * App\Support\AdminIpLock); that changes nothing of what is indexable.
 */
final class VideoLanguages
{
    /**
     * The request attribute with the languages a watch page is indexable in (an AVDBAPI import's only): the layout prints
     * its hreflang links for those (App\Http\Controllers\VideoController::showVideo()).
     */
    public const HREFLANG = 'video.languages';

    /**
     * The request attribute with the robots words of a watch page that opens where it is not indexable (robots()): the
     * layout prints them in the page's robots meta tag, the controller sends them as X-Robots-Tag.
     */
    public const ROBOTS = 'video.robots';

    /**
     * Whether the video's watch page opens in this language for a visitor: always (the page follows the lists, see the
     * class). The policy and ShownToMember carry no language condition because of this rule; the tests pin them to it.
     */
    public static function visible(Video $video, string $locale): bool
    {
        return true;
    }

    /** Whether an AVDBAPI import needs its own translation to be indexable in this language (any but English and the fallback). */
    public static function needsTranslation(string $locale): bool
    {
        return $locale !== 'en' && $locale !== (string) config('app.fallback_locale', 'en');
    }

    /** Whether this video's indexable languages depend on its translations at all (an AVDBAPI import). */
    public static function translated(Video $video): bool
    {
        return (bool) $video->getAttribute('avdb_id');
    }

    /**
     * Whether the video's watch page in this language is offered to search engines (sitemaps, hreflang, no noindex).
     *
     * @param  list<string>|null  $translatedIn  the languages the video has a stored translation of, when the caller read
     *                                           them already (languagesOf()); null: one query now
     */
    public static function indexable(Video $video, string $locale, ?array $translatedIn = null): bool
    {
        if (! self::translated($video) || ! self::needsTranslation($locale)) {
            return true;
        }

        // the languages read already, or a query: never the loaded relation (Video's global scope loads only the request
        // language's translations)
        return $translatedIn !== null
            ? in_array($locale, $translatedIn, true)
            : $video->translations()->where('locale', $locale)->exists();
    }

    /**
     * The robots words of the video's watch page in this language when it opens there but is not indexable(): "noindex,
     * follow" (search engines leave the page out of their results and still follow its links), or "noindex, nofollow"
     * where the site's own robots rule says nofollow (Settings > SEO: "Tell Google not to follow links", "Hide the whole
     * site from Google"). null: the page is indexable here and keeps the site's own robots rule.
     *
     * @param  list<string>|null  $translatedIn  as for indexable()
     */
    public static function robots(Video $video, string $locale, ?array $translatedIn = null): ?string
    {
        if (! self::visible($video, $locale) || self::indexable($video, $locale, $translatedIn)) {
            return null;
        }

        return config('site.robots_noindex') || config('site.robots_nofollow') ? 'noindex, nofollow' : 'noindex, follow';
    }

    /**
     * The languages this video has a stored translation of (one query), for an AVDBAPI import; null for any other video,
     * whose indexable languages do not depend on them.
     *
     * @return list<string>|null
     */
    public static function languagesOf(Video $video): ?array
    {
        if (! self::translated($video)) {
            return null;
        }

        return $video->translations()->pluck('locale')->map(fn ($locale): string => (string) $locale)->values()->all();
    }

    /**
     * Keeps the rows of $table (the videos table or an alias of it) whose watch page is indexable in this language: the
     * same rule as indexable(), as conditions of the caller's one query (an EXISTS on the (video_id, locale) index).
     */
    public static function constrainIndexable(EloquentBuilder|QueryBuilder $query, string $locale, string $table = 'videos'): void
    {
        if (! self::needsTranslation($locale)) {
            return;
        }

        $query->where(fn (EloquentBuilder|QueryBuilder $imported) => $imported
            ->whereNull($table.'.avdb_id')
            ->orWhere($table.'.avdb_id', 0)
            ->orWhereExists(fn (QueryBuilder $translations) => $translations->selectRaw('1')
                ->from('video_translations')
                ->whereColumn('video_translations.video_id', $table.'.id')
                ->where('video_translations.locale', $locale)));
    }

    /**
     * The languages of $locales in which this video's watch page is indexable, in the order given.
     *
     * @param  list<string>  $locales
     * @param  list<string>|null  $translatedIn  the languages the video has a stored translation of, when the caller read
     *                                           them already (translatedIn() for a page of videos, languagesOf() for
     *                                           one); null: read them now
     * @return list<string>
     */
    public static function indexableIn(Video $video, array $locales, ?array $translatedIn = null): array
    {
        if (! self::translated($video)) {
            return array_values($locales);
        }

        $translatedIn ??= self::languagesOf($video) ?? [];

        return array_values(array_filter($locales, fn (string $locale): bool => ! self::needsTranslation($locale)
            || in_array($locale, $translatedIn, true)));
    }

    /**
     * The languages each of these videos has a stored translation of, in one query (a page of a sitemap reads them for
     * its AVDBAPI videos only).
     *
     * @param  iterable<Video>  $videos
     * @return array<int, list<string>> video id => languages
     */
    public static function translatedIn(iterable $videos): array
    {
        $ids = [];

        foreach ($videos as $video) {
            if ($video instanceof Video && self::translated($video)) {
                $ids[] = (int) $video->getKey();
            }
        }

        $languages = array_fill_keys($ids, []);

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('video_translations')->whereIn('video_id', $chunk)->get(['video_id', 'locale']) as $row) {
                $languages[(int) $row->video_id][] = (string) $row->locale;
            }
        }

        return $languages;
    }
}
