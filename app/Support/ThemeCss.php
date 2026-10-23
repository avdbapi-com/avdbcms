<?php

namespace App\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Perf plan step 24: the layout's static theme CSS lives in resources/css/layout-theme-*.css
 * and is served as cached build files instead of ~200 KB of inline CSS on every page.
 *
 * tags() prints the normal Vite tags for one of those files. It prints the same CSS inline
 * instead, at the same place in the cascade, when config('app.theme_css_inline') is on
 * (AVD_THEME_CSS_INLINE=true in .env), or when the build has no entry for the file (e.g. an
 * older public/build next to a newer layout), so a page never renders without its theme.
 *
 * themeTags() does the same for the theme collection v2 token files of the active theme and colour
 * style (resources/css/themes/<id>.css + <id>/<flavor>.css), except that its inline form carries only
 * the blocks the page uses.
 */
final class ThemeCss
{
    /**
     * A generated token file (`php artisan avd:themes:build`, App\Support\ThemeTokens::files): the theme's own
     * file, or with $flavor the file of one of its colour styles.
     */
    public static function themeEntry(string $themeId, ?string $flavor = null): ?string
    {
        if (preg_match('/^[a-z0-9_]+$/', $themeId) !== 1 || ($flavor !== null && preg_match('/^[a-z0-9_]+$/', $flavor) !== 1)) {
            return null;
        }

        return 'resources/css/themes/'.$themeId.($flavor !== null ? '/'.$flavor : '').'.css';
    }

    /**
     * The active theme's token CSS: <link>s to the hashed build files of the theme (both modes of the signature
     * colour style) and of the active colour style when it has one (the signature has none), cached as immutable
     * like every /build/assets file. With AVD_THEME_CSS_INLINE, or when the build has no entry for a file, only
     * the blocks this page can use are printed inline (#avd-theme-tokens): the active mode with the active colour
     * style, the other mode too when the visitor can switch it ($bothModes; the switch only flips
     * html[data-bs-theme]), and the mirrored shapes on RTL pages.
     */
    public static function themeTags(string $themeId, string $mode, string $flavor, bool $bothModes = true, bool $rtl = false): HtmlString
    {
        $entries = self::themeEntries($themeId, $flavor);

        if ($entries === []) {
            return new HtmlString('');
        }

        $vite = app(Vite::class);

        if (! config('app.theme_css_inline')) {
            try {
                return $vite($entries);
            } catch (Throwable) {
                // No manifest entry for one of the files: inline them below.
                self::reportStaleBuild(implode(', ', $entries));
            }
        }

        $modes = $bothModes ? array_values(array_unique([$mode, $mode === 'dark' ? 'light' : 'dark'])) : [$mode];
        $source = implode("\n", array_map(fn (string $entry): string => self::source($vite, $entry), $entries));
        $css = self::themeBlocks($source, $modes, $flavor, $rtl);

        return new HtmlString($css === '' ? '' : '<style id="avd-theme-tokens">'.str_ireplace('</style', '<\/style', $css).'</style>');
    }

    /**
     * The token files a page of this theme and colour style loads: the theme's, then the colour style's when it
     * changes any token (a source file exists for it).
     *
     * @return list<string>
     */
    public static function themeEntries(string $themeId, string $flavor): array
    {
        $entry = self::themeEntry($themeId);

        if ($entry === null) {
            return [];
        }

        $flavorEntry = self::themeEntry($themeId, $flavor);

        return $flavorEntry !== null && is_file(base_path($flavorEntry)) ? [$entry, $flavorEntry] : [$entry];
    }

    /**
     * The blocks of a token file that apply to the given modes, flavor and direction, in file order:
     * html[data-bs-theme="<mode>"], ...[data-avd-flavor="<flavor>"] and ...[dir="rtl"], plus the theme's hero
     * decoration rules (every top-level rule that is not a token block, @media included). Works on the source file
     * and on the whitespace-minified build file (which drops the selector quotes).
     *
     * @param  list<string>  $modes
     */
    public static function themeBlocks(string $css, array $modes, string $flavor, bool $rtl = false): string
    {
        $wanted = [];

        foreach ($modes as $mode) {
            foreach ([ThemeTokens::selector($mode), ThemeTokens::selector($mode, $flavor), ...($rtl ? [ThemeTokens::selector($mode, rtl: true)] : [])] as $selector) {
                $wanted[str_replace('"', '', $selector)] = $selector;
            }
        }

        $out = '';
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $length = strlen($css);
        $start = 0;

        // Top-level blocks: token blocks are flat, a hero decoration may nest rules in @media.
        while (($open = strpos($css, '{', $start)) !== false) {
            $depth = 1;
            $close = $open + 1;

            while ($close < $length && $depth > 0) {
                $depth += $css[$close] === '{' ? 1 : ($css[$close] === '}' ? -1 : 0);
                $close++;
            }

            $selector = trim(substr($css, $start, $open - $start));
            $body = substr($css, $open + 1, $close - $open - 2);
            $key = str_replace(['"', "'", ' '], '', $selector);
            $start = $close;

            if (isset($wanted[$key])) {
                $declarations = preg_replace('/\s*;\s*/', ';', trim((string) preg_replace('/\s*\n\s*/', '', $body)));
                $out .= $wanted[$key].'{'.rtrim((string) $declarations, ';').'}';
            } elseif ($selector !== '' && ! str_starts_with($key, 'html[data-bs-theme=')) {
                $out .= $selector.'{'.trim((string) preg_replace('/\s+/', ' ', $body)).'}';
            }
        }

        return $out;
    }

    /** The CSS of a Vite entry: the built file when there is one, else the source file. */
    private static function source(Vite $vite, string $entry): string
    {
        if (! $vite->isRunningHot()) {
            try {
                return (string) $vite->content($entry);
            } catch (Throwable) {
            }
        }

        $source = base_path($entry);

        return is_file($source) ? (string) file_get_contents($source) : '';
    }

    public static function tags(string $entry, string $inlineId): HtmlString
    {
        $vite = app(Vite::class);

        if (! config('app.theme_css_inline')) {
            try {
                return $vite($entry);
            } catch (Throwable) {
                // No manifest entry for this file: inline it below.
                self::reportStaleBuild($entry);
            }
        }

        $css = null;

        if (! $vite->isRunningHot()) {
            try {
                $css = $vite->content($entry);
            } catch (Throwable) {
                $css = null;
            }
        }

        if ($css === null) {
            // The source file is the same CSS before minification.
            $source = base_path($entry);
            $css = is_file($source) ? (string) file_get_contents($source) : '';
        }

        return new HtmlString(
            '<style id="'.e($inlineId).'">'.str_ireplace('</style', '<\/style', $css).'</style>'
        );
    }

    /**
     * A build without the theme CSS entries predates these views. The CSS has its inline fallback above, but
     * the layout's scripts (resources/js/layout, in the same build) have none: theater mode, lights out,
     * search and watch later stay off until public/build is uploaded too. Logged as an error, which the
     * admin settings' health check shows, at most once an hour.
     */
    private static function reportStaleBuild(string $entry): void
    {
        try {
            $flag = storage_path('framework/avd-stale-build');

            if (is_file($flag) && filemtime($flag) > time() - 3600) {
                return;
            }

            @touch($flag);
            Log::error('AVD: public/build has no entry for '.$entry.', so it is older than resources/views. The theme CSS is printed inline instead, but the layout scripts (theater mode, lights out, search, watch later) are missing: upload public/build together with resources/views.');
        } catch (Throwable) {
            // Never break a page over this notice.
        }
    }
}
