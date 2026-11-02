<?php

namespace App\Support\Member;

/**
 * The member danger tokens of one look (member design 3.1a; 1.2.3 round 1, R1-070).
 *
 * In 97 of the 348 theme token blocks the member kit's danger tokens are not the theme's own (its danger is too close
 * to its brand: scripts/member/danger_tokens.py decides, DangerMapData holds the result). The whole map used to be the
 * first part of the member CSS entry (resources/sass/member/_danger.scss, 11.6 KB of render-blocking CSS on every page
 * of a signed-in visitor); a page needs only its own look's rules: at most one per mode for its theme and one per mode
 * for its colour style. components/layout.blade.php prints them inline wherever the member CSS entry is printed, with
 * the stylesheet's selectors (and so its specificity: below the owner-colour block of ThemeTokens::ownerOverrides(),
 * above the defaults on :root in resources/sass/member/_shared.scss). Both modes: a visitor switches the mode
 * without a reload.
 */
final class DangerMap
{
    private const NAME = '/^[a-z0-9_]{1,64}$/';

    /** The rules of the look (theme id, colour style id); '' when it keeps the theme's own danger in both modes. */
    public static function css(?string $theme, ?string $flavor): string
    {
        $theme = (string) $theme;
        $flavor = (string) $flavor;

        if (preg_match(self::NAME, $theme) !== 1) {
            return '';
        }

        $hasFlavor = preg_match(self::NAME, $flavor) === 1;
        $css = '';

        foreach (['dark', 'light'] as $mode) {
            $own = DangerMapData::BLOCKS[$theme.'|'.$mode] ?? null;

            if ($own !== null) {
                $css .= 'html[data-avd-theme="'.$theme.'"][data-bs-theme="'.$mode.'"]{'.DangerMapData::BODIES[$own].'}';
            }

            $style = $hasFlavor ? (DangerMapData::BLOCKS[$theme.'/'.$flavor.'|'.$mode] ?? null) : null;

            if ($style !== null) {
                $css .= 'html[data-avd-theme="'.$theme.'"][data-avd-flavor="'.$flavor.'"][data-bs-theme="'.$mode.'"]{'.DangerMapData::BODIES[$style].'}';
            }
        }

        return $css;
    }
}
