<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Round 2a, read from the stylesheets (the browser proof is the lane's sweep).
 *
 * R2A-002: R1-142 gave the phone brand link (only its 32px mark shows below 576px) a 44px floor and centred the mark,
 * but the Premium chip's older rule (.show_sidebar.is-premium: min-width 240px, more specific) still applied there: the
 * mark sat centred in a 240px link, under the search button (a tap on the logo opened the search). The chip's room
 * applies only where the name and the chip show.
 *
 * R2A-048: Imperial Salon's crest gives its brand link a 24px floor for the 22px diamond (layout-theme-a.css,
 * html[data-avd-header-deco="crest"] .avd-brand, 0-2-1), which beat the phone rule's 44px (.navbar-brand.avd-brand,
 * 0-2-0): the link stayed 24px wide, narrower than its drawn diamond. The phone rule is more specific now.
 *
 * R2A-047: the account panel's "View your channel" was a 16px target with a mouse (1024-1920px).
 */
class HeaderPhoneBrandAndPanelLinkCssTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /** The SCSS without its // and block comments. */
    private function scss(string $path): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->source($path));

        return (string) preg_replace('#^\s*//.*$#m', '', $css);
    }

    /** The body of the first top-level @media block with this exact prelude. */
    private function media(string $css, string $prelude): string
    {
        $at = strpos($css, '@media '.$prelude.' {');
        $this->assertNotFalse($at, '@media '.$prelude);
        $depth = 0;
        for ($i = strpos($css, '{', (int) $at); $i < strlen($css); $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return substr($css, (int) $at, $i - (int) $at + 1);
            }
        }
        $this->fail('unclosed @media '.$prelude);
    }

    /** [ids, classes + attributes + pseudo-classes, elements] of a simple selector list item. */
    private function specificity(string $selector): array
    {
        $s = (string) preg_replace('/"[^"]*"/', '""', $selector);
        $ids = preg_match_all('/#[\w-]+/', $s);
        $classes = preg_match_all('/\.[\w-]+|\[[^\]]+\]|:(?!:)[\w-]+/', $s);
        $elements = preg_match_all('/(?:^|[\s>+~])(?:[a-z][\w-]*)/i', $s);

        return [$ids, $classes, $elements];
    }

    /** The body of the top-level @media block with this exact prelude that contains $needle. */
    private function mediaWith(string $css, string $prelude, string $needle): string
    {
        $offset = 0;
        while (($at = strpos($css, '@media '.$prelude.' {', $offset)) !== false) {
            $block = $this->media(substr($css, (int) $at), $prelude);
            if (str_contains($block, $needle)) {
                return $block;
            }
            $offset = (int) $at + strlen($block);
        }
        $this->fail('no @media '.$prelude.' with '.$needle);
    }

    public function test_the_premium_chips_room_only_where_the_chip_shows(): void
    {
        $header = $this->scss('resources/sass/header.scss');

        // the 240px floor is inside @media (min-width: 660px) and nowhere else
        $wide = $this->media($header, '(min-width: 660px)');
        $this->assertMatchesRegularExpression('/\.navbar-brand\.avd-brand\.show_sidebar\.is-premium \{\s*width: auto;\s*min-width: 240px;\s*\}/', $wide);
        $this->assertSame(1, substr_count($header, 'min-width: 240px'), 'no other 240px floor');
        $capped = $this->mediaWith($header, '(min-width: 992px)', '.show_sidebar.is-premium');
        $outside = str_replace([$wide, $capped], '', $header);
        $this->assertStringNotContainsString('.show_sidebar.is-premium', $outside);

        // below 576px only the mark shows (the name and the chip are hidden there)
        $phone = $this->media($header, '(max-width: 575.98px)');
        $this->assertMatchesRegularExpression('/\.avd-brand-text \{\s*display: none;\s*\}/', $phone);
        $this->assertMatchesRegularExpression('/\.avd-premium-chip \{\s*display: none;\s*\}/', $phone);
        // the header's chip shows where the name and the chip fit: from 660px (second pass: at 640px Cinema Night in
        // Japanese and Space in Russian, English and German still lacked 1-8px), from 992px in Imperial Salon's crest bar
        $this->assertMatchesRegularExpression('/\.avd-header \.avd-premium-chip \{\s*display: none;\s*\}/', $this->media($header, '(max-width: 659.98px)'));
        $this->assertStringNotContainsString('639.98px', $header, 'no older chip threshold');
        $this->assertStringContainsString('html[data-avd-header-deco="crest"] .avd-header .avd-premium-chip', $header);
    }

    /**
     * Second pass (the reviewer's CC-RV-01): below 992px the header is the two-column grid (the brand zone, the actions),
     * and the Premium brand's 40vw cap cut the name next to the chip while the row had room: Space at 640-740px (44px of
     * "Sandbox Site" at 640 with 65px free beside the brand), Night City HUD at 640px. The link now grows into the room
     * the actions leave (as the brand of the looks without a pinned menu); the cap holds only in the one-row bar from
     * 992px, where the search shares the row.
     */
    public function test_below_992px_the_premium_brand_takes_the_rows_room(): void
    {
        $header = $this->scss('resources/sass/header.scss');

        $this->assertStringNotContainsString('max-width', $this->media($header, '(min-width: 660px)'), 'no cap where the chip shows');
        $capped = $this->mediaWith($header, '(min-width: 992px)', '.show_sidebar.is-premium');
        $this->assertMatchesRegularExpression('/^@media \(min-width: 992px\) \{\s*\.navbar-brand\.avd-brand\.show_sidebar\.is-premium \{\s*max-width: min\(26rem, 40vw\);\s*\}\s*\}$/', $capped);
        $this->assertSame(1, substr_count($header, '40vw'), 'no other 40vw cap');

        // what the growth relies on: below 992px the brand zone's column is all the room the actions leave
        $grid = $this->mediaWith($header, '(max-width: 991.98px)', 'grid-template-columns');
        $this->assertMatchesRegularExpression('/\.avd-header-inner,\s*\.avd-header-inner\[x-cloak\] \{\s*display: grid !important;\s*grid-template-columns: minmax\(0, 1fr\) auto;/', $grid);
        // and the name gives way with an ellipsis when the room is short
        $this->assertMatchesRegularExpression('/(?:^|\})\s*\.avd-brand-text \{\s*overflow: hidden;\s*text-overflow: ellipsis;\s*white-space: nowrap;/', $header);
    }

    public function test_the_phone_brand_link_is_a_44px_target_in_every_look(): void
    {
        $phone = $this->media($this->scss('resources/sass/header.scss'), '(max-width: 575.98px)');
        $this->assertMatchesRegularExpression('/:root \.navbar-brand\.avd-brand \{\s*max-width: 46vw;\s*min-height: 44px;\s*min-width: 44px;\s*display: inline-flex;\s*align-items: center;\s*justify-content: center;\s*\}/', $phone);

        // more specific than every look's own floor for the link (Imperial Salon's crest: 24px)
        $themeA = (string) preg_replace('#/\*.*?\*/#s', '', $this->source('resources/css/layout-theme-a.css'));
        preg_match_all('/([^{}]+)\{[^}]*\bmin-(?:inline-size|width):\s*\d+px[^}]*\}/', $themeA, $floors, PREG_SET_ORDER);
        $brandFloors = array_filter($floors, fn (array $m): bool => (bool) preg_match('/\.avd-brand\s*$/', trim($m[1])));
        $this->assertNotEmpty($brandFloors, 'the crest floor is still there to be beaten');
        $mine = $this->specificity(':root .navbar-brand.avd-brand');
        foreach ($brandFloors as $floor) {
            $theirs = $this->specificity(trim($floor[1]));
            $this->assertGreaterThan($theirs, $mine, trim($floor[1]));
        }
    }

    /**
     * The side menu's language picker next to the labelled light/dark switch (RC1 gave the switch its label): in the
     * drawers (phones, the hidden style) every language's name was cut at 360-412px ("Deutsch" 14px of 54, "Filipino"
     * 1px), as in the pinned menu before. The picker takes the whole line everywhere, the switch sits under it.
     */
    public function test_the_side_menus_language_picker_takes_the_whole_line(): void
    {
        $header = $this->scss('resources/sass/header.scss');

        $this->assertMatchesRegularExpression('/(?:^|\})\s*\.avd-sidebar-controls \{\s*display: grid;\s*grid-template-columns: minmax\(0, 1fr\);\s*gap: 0\.55rem;\s*align-items: center;\s*\}/', $header);
        $this->assertMatchesRegularExpression('/(?:^|\})\s*\.avd-sidebar-controls theme-switch \{\s*justify-self: end;\s*\}/', $header);
        // no other rule puts the switch back beside the picker (the two rules above are the only ones)
        $this->assertSame(2, substr_count($header, '.avd-sidebar-controls'), 'the controls\' only rules');
        foreach (['resources/css/layout-theme-a.css', 'resources/css/layout-theme-b.css'] as $look) {
            $this->assertStringNotContainsString('.avd-sidebar-controls', (string) preg_replace('#/\*.*?\*/#s', '', $this->source($look)), $look);
        }
    }

    public function test_view_your_channel_is_a_24px_target_with_a_mouse(): void
    {
        $chrome = $this->scss('resources/sass/member/_chrome.scss');

        // every pointer: the padding is given back by the margin (the head keeps its height, the text its place)
        $this->assertMatchesRegularExpression('/(?:^|\})\s*:root \.mk-account__channel \{\s*display: inline-block;\s*padding-block: \.3rem;\s*margin-block: -\.3rem;\s*font-size: \.8125rem;/', $chrome);
        // touch screens: 44px as before
        $coarse = $this->media($chrome, '(pointer: coarse)');
        $this->assertMatchesRegularExpression('/:root \.mk-account__channel \{\s*display: inline-block;\s*padding-block: \.9rem;\s*margin-block: -\.9rem;\s*\}/', $coarse);
    }
}
