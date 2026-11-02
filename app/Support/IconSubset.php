<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The icons an owner types, on top of the main CSS's Font Awesome subset (perf-7, 1.2.3).
 *
 * The main CSS carries only the icons the site's code names (resources/sass/_fontawesome.scss, IconSubsetData::SUBSET);
 * all 1,895 rules were 58 KB of render-blocking CSS on every page. Three kinds of icon names come from data instead: a
 * category's icon (any solid icon, FontAwesomeIconRegistry), the menu links' icons (Appearance > Navigation: "Name |
 * address | icon") and the premium page's feature-box icons. The layout prints the rules for those that the subset lacks
 * (extraCss(): a few bytes, usually none), so they never wait for anything; an icon that still finds no rule makes
 * resources/js/layout/icons.ts load the full set once (resources/sass/fa-full.scss).
 */
final class IconSubset
{
    /**
     * The side menu's own cache of the menu categories (App\View\Composers\SidebarComposer: the same key and query,
     * forgotten by App\Observers\CategoryObserver whenever a category is saved or deleted). Reading the icons through it
     * costs no query of its own: whoever asks first fills it for the other (the home page's query count stays put).
     */
    public const CATEGORY_CACHE_KEY = 'categories';

    /** Request attribute of the icons a page prints from its own data (remember()). */
    public const PAGE_ICONS = 'avd.icon_subset.page_icons';

    /** @return array<string, true> */
    private static function subset(): array
    {
        static $subset;

        return $subset ??= array_fill_keys(explode('|', IconSubsetData::SUBSET), true);
    }

    /** @return array<string, string> name => code point (hex) */
    private static function glyphs(): array
    {
        static $glyphs;

        if ($glyphs === null) {
            $glyphs = [];

            foreach (explode('|', IconSubsetData::GLYPHS) as $pair) {
                [$name, $code] = explode('=', $pair, 2);
                $glyphs[$name] = $code;
            }
        }

        return $glyphs;
    }

    /** Whether the main CSS carries the icon (a name without "fa-"). */
    public static function inSubset(string $name): bool
    {
        return isset(self::subset()[$name]);
    }

    /**
     * The CSS rules of the given icons that the main CSS does not carry: ".fa-<name>{--fa:"\<code>"}", the same rule
     * Font Awesome's own CSS has (the style class picks the font). Names may carry "fa-" or a style class ("fa-brands
     * fa-x"); unknown names, the subset's own and anything that is not a plain icon name are skipped, so nothing typed
     * can reach the stylesheet but a known name and its hexadecimal code point.
     *
     * @param  iterable<mixed>  $names
     */
    public static function cssFor(iterable $names): string
    {
        $rules = [];

        foreach ($names as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            foreach (preg_split('/\s+/', strtolower(trim($raw))) ?: [] as $token) {
                $name = str_starts_with($token, 'fa-') ? substr($token, 3) : $token;

                if ($name === '' || preg_match('/^[a-z0-9-]+$/', $name) !== 1 || self::inSubset($name)) {
                    continue;
                }

                $code = self::glyphs()[$name] ?? null;

                if ($code !== null && preg_match('/^[0-9a-f]{2,6}$/', $code) === 1) {
                    $rules[$name] = '.fa-'.$name.'{--fa:"\\'.$code.'"}';
                }
            }
        }

        ksort($rules);

        return implode('', $rules);
    }

    /**
     * The rules for the icons owners choose that the main CSS lacks: every category's icon, the side-menu links' icons
     * and the premium page's feature boxes. '' when they are all in the subset (the usual case) or on any failure.
     */
    public static function extraCss(): string
    {
        try {
            return self::cssFor(array_merge(self::categoryIcons(), self::settingIcons(), self::pageIcons()));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Whether the rules extraCss() printed draw a glyph the cut solid font lacks (R1-071). The main CSS's fonts are cut
     * to the subset's icons (resources/fonts/fontawesome, IconSubsetData::FONT_SOLID); the full solid font serves every
     * other icon glyph, and the layout preloads it on the pages that show one, as it did on every page before.
     */
    public static function needsFullFont(string $extraCss): bool
    {
        static $cut;

        if ($extraCss === '' || preg_match_all('/--fa:"\\\\([0-9a-f]{2,6})"/', $extraCss, $codes) < 1) {
            return false;
        }

        $cut ??= array_fill_keys(explode('|', IconSubsetData::FONT_SOLID), true);

        foreach ($codes[1] as $code) {
            if (! isset($cut[$code])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Icons a page prints from its own data, registered while it renders (a page's content renders before the layout's
     * <head>): a category's own page, the home page's fallback rails. The category list shows every category, menu or
     * not; an icon there that is neither in the subset nor in the menu is drawn by the safety net.
     */
    public static function remember(mixed ...$names): void
    {
        try {
            $request = request();
            $icons = (array) $request->attributes->get(self::PAGE_ICONS, []);

            foreach ($names as $name) {
                if (is_string($name) && $name !== '' && strlen($name) <= 64) {
                    $icons[$name] = true;
                }
            }

            $request->attributes->set(self::PAGE_ICONS, $icons);
        } catch (Throwable) {
            // no request (a console render): nothing to remember
        }
    }

    /** @return list<string> */
    private static function pageIcons(): array
    {
        return array_keys((array) request()->attributes->get(self::PAGE_ICONS, []));
    }

    /**
     * The icons of the menu categories (the side menu, the footer and the home page's chips show them): the side menu's
     * own list when this request has read it already, else through its cache.
     *
     * @return list<string>
     */
    private static function categoryIcons(): array
    {
        $memo = request()->attributes->get('avd.sidebar_categories.'.app()->getLocale());
        $categories = $memo instanceof Collection
            ? $memo
            // the same list as the side menu's (1.2.3: no category named with a word that is always blocked)
            : Cache::rememberForever(self::CATEGORY_CACHE_KEY, static fn () => ContentSafety::visibleCategories(Category::query()->where('in_menu', true)->ordered()->get()));

        return collect($categories)
            ->map(static fn (mixed $category): string => FontAwesomeIconRegistry::normalize(is_object($category) ? ($category->icon ?? '') : ''))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private static function settingIcons(): array
    {
        $icons = [];

        foreach (['site.sidebar_primary_links', 'site.sidebar_auth_links'] as $key) {
            foreach (NavigationSettings::parseMenuLinks(config($key), 20) as $link) {
                $icons[] = (string) ($link['icon'] ?? '');
            }
        }

        foreach (['uploads', 'downloads', 'ad_free'] as $box) {
            $icons[] = (string) config('site.premium_feature_'.$box.'_icon', '');
        }

        return $icons;
    }
}
