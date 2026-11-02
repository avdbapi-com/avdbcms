<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3, the review fixes: the style and script fixes that have no server output, read from their sources.
 * Each test names the finding it covers; it was also checked in browsers (Chromium and WebKit, on real pages).
 */
class FrontReviewRoundContractTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /** Every rule of a selector in a style sheet, joined (the selector exactly as written before its "{"). */
    private function rules(string $css, string $selector): string
    {
        preg_match_all('/(?:^|[}\n])\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css, $matches);

        return implode("\n", $matches[1]);
    }

    /** "Back to top" mirrors in Arabic: logical inset only, no physical left/right on it (it fell to the right edge). */
    public function test_back_to_top_is_placed_with_logical_insets_only(): void
    {
        foreach (['resources/sass/enhancements.scss', 'resources/sass/motion.scss', 'resources/sass/responsive.scss'] as $file) {
            $rules = $this->rules($this->source($file), '.back-to-top');
            $this->assertNotSame('', $rules, $file);
            $this->assertDoesNotMatchRegularExpression('/(^|[\s;])(left|right)\s*:/', $rules, $file.' sets a physical left/right on "Back to top"');
        }

        $this->assertStringContainsString('inset-inline-end: calc(1rem + env(safe-area-inset-left, 0px));', $this->rules($this->source('resources/sass/responsive.scss'), 'html[dir="rtl"] .back-to-top'));
    }

    /** On phones the floating pill is icon-only: the rule comes after the pill's own rules of the same weight. */
    public function test_the_phone_back_to_top_is_icon_only_after_the_pill_rules(): void
    {
        $themeA = $this->source('resources/css/layout-theme-a.css');
        $pill = strpos($themeA, 'html[data-avd-back-to-top-style="floating_pill"] .back-to-top::after {');
        $phone = strpos($themeA, "@media (max-width: 767.98px) {\n    html[data-avd-back-to-top-style=\"floating_pill\"] .back-to-top {\n        width: 40px !important;");

        $this->assertNotFalse($pill);
        $this->assertNotFalse($phone, 'the icon-only phone rule is in layout-theme-a.css');
        $this->assertGreaterThan($pill, $phone, 'the icon-only phone rule must follow the pill rules it overrides');
        $this->assertStringNotContainsString('floating_pill"] .back-to-top::after {', substr($this->source('resources/sass/responsive.scss'), 0), 'a second, losing copy in responsive.scss');
    }

    /** The front menu's drawer counts as an open panel for the toasts (they moved to the top for every panel but it). */
    public function test_the_front_menu_drawer_moves_the_toasts_to_the_top(): void
    {
        $toasts = $this->source('resources/js/layout/toasts.ts');

        $this->assertStringContainsString("export const PANEL_OPEN_SELECTOR = '.offcanvas.show, .offcanvas.showing';", $toasts);
        $this->assertStringContainsString('document.querySelector(PANEL_OPEN_SELECTOR)', $toasts);
        $this->assertStringNotContainsString(':not(.show_sidebar)', $toasts);
        $this->assertStringContainsString("['show.bs.offcanvas', 'shown.bs.offcanvas', 'hide.bs.offcanvas', 'hidden.bs.offcanvas']", $toasts);
    }

    /** The daily pill starts in the content column next to a pinned menu, and its buttons are 44px on touch screens. */
    public function test_the_daily_pill_keeps_off_the_pinned_menu_and_has_touch_targets(): void
    {
        $css = $this->source('resources/sass/enhancements.scss');

        $this->assertMatchesRegularExpression('/@media \(min-width: 992px\) \{\s*body\.has-sidebar \.avd-daily-pill \{\s*inset-inline-start: calc\(var\(--avd-sidebar-w, 232px\) \+ 24px\);/', $css);
        $this->assertStringContainsString('html[data-avd-sidebar-style="floating"] body.has-sidebar .avd-daily-pill {', $css);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\) \{\s*\.avd-daily-pill__open \{\s*min-block-size: 44px;\s*\}\s*\.avd-daily-pill__close \{\s*inline-size: 44px;\s*block-size: 44px;/', $css);
    }

    /** The search column fills the phone's width (it took its content's width beside an empty band). */
    public function test_the_search_column_fills_the_phone(): void
    {
        $css = $this->source('resources/sass/enhancements.scss');

        $this->assertMatchesRegularExpression('/#main-container\.avd-search-page \{[^}]*flex: 1 1 0%;[^}]*min-inline-size: 0;/', $css);
    }

    /** One focus ring on the side menu (and the menu button, pagination, accordions): no Bootstrap glow next to it. */
    public function test_one_focus_ring_on_menu_links(): void
    {
        $this->assertMatchesRegularExpression('/html\[data-avd-theme\] \.nav-link:focus-visible,\s*html\[data-avd-theme\] \.navbar-toggler:focus,\s*html\[data-avd-theme\] \.page-link:focus,\s*html\[data-avd-theme\] \.accordion-button:focus \{\s*box-shadow: none;/', $this->source('resources/css/layout-theme-b.css'));
    }

    /** The share dialog's Embed button draws its icons where the links beside it do. */
    public function test_the_share_embed_button_draws_its_icons_in_the_row(): void
    {
        $this->assertStringContainsString('fa-stack fa-2x avd-share-embed', $this->source('resources/views/modals/share.blade.php'));
        $this->assertMatchesRegularExpression('/\.modal \.avd-share-embed > \.fa-stack-1x,\s*\.modal \.avd-share-embed > \.fa-stack-2x \{\s*inset-block-start: 0;/', $this->source('resources/sass/app.scss'));
    }

    /** Rail labels break between words (with a hyphen where the language allows it); a long word gets a smaller size. */
    public function test_rail_labels_break_between_words(): void
    {
        $themeA = $this->source('resources/css/layout-theme-a.css');
        $label = $this->rules($themeA, 'html[data-avd-sidebar-style="rail"] .show_sidebar .avd-sidebar-link .text-sm');

        $this->assertStringContainsString('overflow-wrap: normal;', $label);
        $this->assertStringContainsString('hyphens: auto;', $label);
        $this->assertStringContainsString('line-height: 12.6px;', $label);
        $this->assertStringNotContainsString('anywhere', $label);
        $this->assertStringContainsString('.text-sm[data-avd-rail-fit="break"] {', $themeA);

        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringContainsString('const RAIL_MIN_FONT = 8.5;', $core);
        $this->assertStringContainsString('label.scrollWidth > label.clientWidth + 0.5 && size > RAIL_MIN_FONT', $core);
        $this->assertStringContainsString('safely(initRailLabels);', $core);

        // The Premium entry: a short rail label, the full name stays the link's name.
        $front = $this->source('resources/views/layouts/menus/sidebars/front.blade.php');
        $this->assertStringContainsString("'avd-sidebar-label-full' => \$premiumRailLabel !== \$premiumLabel", $front);
        $this->assertStringContainsString('<span class="text-sm avd-sidebar-label-rail" aria-hidden="true">', $front);
    }

    /** The phone ad strip steps aside while the watch page's action or channel row is under it, and on short phones. */
    public function test_the_ad_strip_steps_aside_for_the_watch_pages_rows(): void
    {
        $core = $this->source('resources/js/layout/core.ts');
        $this->assertStringContainsString("document.querySelectorAll<HTMLElement>('[data-avd-strip-yield]')", $core);
        $this->assertStringContainsString("const STRIP_SHORT_SCREEN = '(max-width: 767.98px) and (max-height: 699.98px)';", $core);
        $this->assertStringContainsString("strip.classList.toggle('is-yielding', yieldNow);", $core);
        $this->assertMatchesRegularExpression('/\.ad-slot-mobile\.is-yielding \{\s*opacity: 0;\s*visibility: hidden;\s*pointer-events: none;/', $this->source('resources/sass/app.scss'));

        $show = $this->source('resources/views/videos/show.blade.php');
        $this->assertSame(3, substr_count($show, ' data-avd-strip-yield>'));
    }

    /** The success state of a report is drawn in the look's success tone, not its accent (a red accent read as an error). */
    public function test_the_report_success_tile_uses_the_success_tone(): void
    {
        $tile = $this->rules($this->source('resources/sass/video.scss'), ':root .avd-report-done__icon');

        $this->assertStringContainsString('var(--avd-success-rgb', $tile);
        $this->assertStringContainsString('var(--avd-success,', $tile);
        $this->assertStringNotContainsString('--avd-accent', $tile);
    }

    /** The watch page's related cards and Up next card stay still on hover; a tilting card holds still on its controls. */
    public function test_related_cards_and_up_next_never_move_their_buttons(): void
    {
        $video = $this->source('resources/sass/video.scss');
        // watch-7: the watch page's own rules moved from show.blade.php into its cached stylesheet: both are read.
        $show = $this->source('resources/views/videos/show.blade.php').$this->source('resources/css/watch-page.css');
        $motion = $this->source('resources/sass/motion.scss');

        foreach (['video.scss' => $video, 'show.blade.php + watch-page.css' => $show] as $name => $css) {
            $this->assertDoesNotMatchRegularExpression('/avd-related-card:hover\s*\{[^}]*transform:\s*translate/', $css, $name.': the related card moves on hover');
        }
        $this->assertDoesNotMatchRegularExpression('/avd-related-card:hover \.avd-thumb img\s*\{[^}]*scale/', $show);
        $this->assertDoesNotMatchRegularExpression('/\.avd-up-next-card:hover\s*\{[^}]*transform/', $show);
        $this->assertDoesNotMatchRegularExpression('/\.avd-up-next-cta \.btn\s*\{[^}]*transform/', $show);
        $this->assertDoesNotMatchRegularExpression('/\.avd-up-next-card:hover \.avd-up-next-cta \.btn\s*\{[^}]*scale/', $show);
        $this->assertDoesNotMatchRegularExpression('/\.suggested_video\.avd-related-card:hover \.avd-duration\s*\{[^}]*transform/', $motion);

        $fx = $this->source('resources/js/motion/pointerFx.ts');
        $subtle = substr($fx, strpos($fx, 'const SUBTLE_CARDS = ['), 200);
        $this->assertStringNotContainsString('.suggested_video.avd-related-card', substr($subtle, 0, strpos($subtle, '].join')));
        $this->assertStringContainsString("const CARD_CONTROLS = 'button, .btn, [role=\"button\"], input, select, textarea, label, .dropdown-menu, a.avd-channel-name';", $fx);
        $this->assertStringContainsString('if (moved && !holding) {', $fx);
        $this->assertStringContainsString("card.style.transition = 'none';", $fx);
    }

    /**
     * Hover sweep, watch page and footer: nothing repaints under a resting pointer after 150 ms. The icons inside buttons
     * have no colour transition of their own (it chained with the button's and ran to 240 ms), the related cards'
     * picture shadow and length badge settle in 120 ms, and the footer's light scan no longer sweeps under its links.
     */
    public function test_nothing_repaints_under_a_resting_pointer(): void
    {
        $motion = $this->source('resources/sass/motion.scss');
        $icons = substr($motion, strpos($motion, '.back-to-top i {'), 80);
        $this->assertStringContainsString('transition: opacity 120ms ease;', $icons);
        $this->assertStringNotContainsString('color', $icons);
        $this->assertMatchesRegularExpression('/\.suggested_video\.avd-related-card \.avd-thumb,\s*\.suggested_video\.avd-related-card \.avd-duration \{\s*transition-duration: 120ms;/', $motion);
        $this->assertStringNotContainsString('animation: avd-surface-scan', $motion);

        $themeB = $this->source('resources/css/layout-theme-b.css');
        $this->assertMatchesRegularExpression('/\.back-to-top\):not\(#video_create \*\) i \{\s*transition-property: opacity !important;/', $themeB);
    }

    /**
     * The corner places on a card's picture are logical, so Arabic mirrors them: Bootstrap's start-0 / end-0 are physical
     * in this build, and in Arabic the related cards' quick actions sat on the player's side, under its breathing glow
     * (3-6 repaint frames while the pointer rested on them).
     */
    public function test_card_corner_places_mirror_in_arabic(): void
    {
        foreach (['resources/views/videos/card.blade.php', 'resources/views/videos/card-secondary.blade.php', 'resources/js/components/Videos/Video.tsx'] as $file) {
            $source = $this->source($file);
            $this->assertDoesNotMatchRegularExpression('/position-absolute top-0 (start|end)-0 m-\d/', $source, $file);
            $this->assertMatchesRegularExpression('/position-absolute top-0 avd-at-(start|end) m-\d/', $source, $file);
        }

        $themeA = $this->source('resources/css/layout-theme-a.css');
        $this->assertStringContainsString(".avd-at-start {\n    inset-inline-start: 0;\n}", $themeA);
        $this->assertStringContainsString(".avd-at-end {\n    inset-inline-end: 0;\n}", $themeA);
    }

    /**
     * The copy messages are calm system messages: no "!". R1-057: they are the share dialog's own feedback now (the
     * button's "Copied" / "Code copied" and its live region, avdShareModal.copy()), no tooltip: the dialog is drawn on
     * first use, so its tooltips were never made.
     */
    public function test_copy_messages_have_no_exclamation_mark(): void
    {
        $share = $this->source('resources/views/modals/share.blade.php');

        $this->assertStringContainsString("@js(__('Link copied'))", $share);
        $this->assertStringContainsString("@js(__('Code copied'))", $share);
        $this->assertStringNotContainsString("copied!')", $share);
        $this->assertStringNotContainsString('data-bs-toggle="tooltip"', $share);
    }

    /** The Save dialog reads its list again on every open and never keeps a failed tick. */
    public function test_the_save_dialog_reads_the_list_again_on_open(): void
    {
        $save = $this->source('resources/js/components/Playlists/Save.tsx');

        $this->assertStringNotContainsString('staleTime: Infinity', $save);
        $this->assertStringContainsString('staleTime: 0,', $save);
        $this->assertStringContainsString("refetchOnMount: 'always',", $save);
        $this->assertStringContainsString('disabled={checking}', $save);
        $this->assertMatchesRegularExpression('/onSettled: \(_data, error\) => \{\s*if \(error\) \{\s*flip\(\);\s*void queryClient\.invalidateQueries/', $save);
        $this->assertStringContainsString('saveQueryClient ??= new QueryClient', $save);
    }
}
