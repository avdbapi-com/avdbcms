<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3: the site-wide style and script rules that have no server output to test, read from their sources.
 * Member design 4.2 (one hover rule, pointer magnet, page intro, chips, marquee), 4.4 (fixed layers), 9.2
 * (Font Awesome swap, tier check of weak PCs) and the QA ids named on each test.
 */
class FrontAssetsContractTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    /** auth-4, studio-7, account-3, watch-4, perf-1: hover changes colours only, nothing sweeps, lifts or loops. */
    public function test_one_hover_rule_for_every_button(): void
    {
        $motion = $this->source('resources/sass/motion.scss');
        $this->assertStringNotContainsString('avd-liquid-shimmer-sweep 1.25s', $motion);
        $this->assertDoesNotMatchRegularExpression('/\.btn[^{]*:hover[^{]*\{[^}]*translateY\(-2px\)/', $motion);
        $this->assertDoesNotMatchRegularExpression('/:hover[^{]*\{[^}]*scale\(1\.12\)/', $motion);

        $themeB = $this->source('resources/css/layout-theme-b.css');
        $this->assertStringContainsString('transition-property: color, background-color, border-color, box-shadow, opacity, outline-color !important;', $themeB);
        $this->assertStringContainsString('transition-duration: 120ms !important;', $themeB);
        // "Liquid shimmer" keeps a still sheen.
        $this->assertDoesNotMatchRegularExpression('/@keyframes\s+avd-liquid-shimmer-sweep[^}]*\}[^@]*infinite/', $themeB);

        // The active chip pulses once after load, a hover never replays it, a hovered chip or menu icon holds still.
        $this->assertMatchesRegularExpression('/\.avd-tag\.active\[data-avd-replay\] \{\s*animation: avd-soft-pulse 3\.6s ease-in-out 1 both;\s*\}/', $motion);
        $this->assertStringNotContainsString('avd-soft-pulse-replay 3.6s ease-in-out 1;', $motion);
        $this->assertMatchesRegularExpression('/\.avd-tag\.active:hover,\s*\.avd-tag\.active:focus-visible \{\s*animation-play-state: paused;/', $motion);
        $this->assertMatchesRegularExpression('/\.avd-sidebar-link:hover i,\s*\.avd-sidebar-link:focus-visible i \{\s*animation-play-state: paused;/', $motion);
        $this->assertStringContainsString('avd-icon-breathe 2.8s ease-in-out 1 both', $motion);
        // The thumbnail highlight fades in where it is (it swept across in 720 ms under the card's quick actions).
        $this->assertStringNotContainsString('transform 720ms var(--avd-ease-out)', $motion);
        $this->assertDoesNotMatchRegularExpression('/\.avd-channel-panel:hover,\s*\.avd-description-panel:hover,\s*\.avd-watch-sidebar-panel:hover \{\s*transform: translateY\(-3px\);/', $motion);

        $themeA = $this->source('resources/css/layout-theme-a.css');
        $this->assertMatchesRegularExpression('/\.btn-success:not\(:disabled\):not\(\.disabled\):hover/', $themeA, 'perf-20');
    }

    /** Member design 4.2: the magnet is off for buttons, calm in forms and member pages, 2px at most. */
    public function test_the_pointer_magnet_spares_buttons_forms_and_member_pages(): void
    {
        $fx = $this->source('resources/js/motion/pointerFx.ts');
        $this->assertMatchesRegularExpression('/const MAGNET_TARGETS = \'([^\']*)\'/', $fx);
        preg_match('/const MAGNET_TARGETS = \'([^\']*)\'/', $fx, $m);
        $this->assertNotContains('.btn', array_map('trim', explode(',', $m[1])));
        $this->assertStringContainsString("const CALM_ZONES = '.avd-mk, form, [data-avd-back-office]'", $fx);
        $this->assertStringContainsString('const MAGNET_PX = 2', $fx);
        $this->assertStringNotContainsString('scale(1.025)', $fx);
        // The cursor halo settles within 150 ms (the 0.14-per-frame easing moved under a hovered button for a second).
        $this->assertStringContainsString('const HALO_LEFT_PER_FRAME = 0.4;', $fx);
        // Panels that hold controls never tilt or lift (their Subscribe, Filters and sort buttons stay put).
        preg_match('/const SUBTLE_CARDS = \[(.*?)\]\.join/s', $fx, $subtle);
        $this->assertNotEmpty($subtle);
        foreach (['.avd-section-card', '.avd-channel-panel', '.avd-description-panel', '.avd-watch-sidebar-panel'] as $panel) {
            $this->assertStringNotContainsString("'".$panel."'", $subtle[1], $panel);
        }
        $this->assertStringNotContainsString('(x - haloX) * 0.14', $fx);
    }

    /**
     * auth-5, watch-5, perf-15: no intro over painted content, chips never move, the marquee idles.
     *
     * auth-5: the intro used to run when paint timing said "nothing painted yet", but Chromium posts the
     * first-contentful-paint entry only after the frame is presented, so the intro hid the painted header and menu in
     * most loads. Now nothing decides from paint timing: there is no intro on the server-rendered chrome, titles or
     * chips, the reveal observers leave every element that is on screen when observed alone (revealPolicy.ts, whose
     * cases tests/js/front_logic.test.ts runs), and the scripted re-entrances of shown dialogs and of the search panel
     * (both already on screen when they ran) are gone.
     */
    public function test_page_motion_never_hides_painted_content_and_goes_idle(): void
    {
        $anime = $this->source('resources/js/motion/animeEnhancements.ts');
        foreach (['runPageIntro', 'sinceFirstPaint', 'initIsTimely', 'first-contentful-paint', 'getEntriesByName(\'first', 'skipPainted', 'animateSearchPanels', 'installModalEffects', "'shown.bs.modal'", '.avd-header-actions > *', '.avd-sidebar-link'] as $gone) {
            $this->assertStringNotContainsString($gone, $anime, $gone);
        }
        $this->assertStringContainsString("import {imageMayFade, isOnScreen} from './revealPolicy';", $anime);
        // Both observers skip on-screen entries whenever they come (at init and for content added later).
        $this->assertSame(2, substr_count($anime, 'if (entryOnScreen(entry)) {'));
        $this->assertStringContainsString('if (!imageMayFade(downloadMs(image))) {', $anime);
        $tier = $this->source('resources/js/motion/tier.ts');
        $this->assertStringNotContainsString("'pageIntro'", $tier);
        $this->assertStringNotContainsString("'modalFx'", $tier);
        $this->assertStringNotContainsString('FIRST_PAINT_WINDOW_MS = 300', $anime);
        $this->assertStringContainsString("const NAVIGATION_CHIPS = '.avd-category-tabs, [data-avd-chips-scroller]';", $anime);
        $this->assertStringContainsString('const IDLE_STOP_MS = 20000;', $anime);
        $this->assertStringContainsString("window.addEventListener('blur', onWindowBlur);", $anime);
    }

    /** studio-17, perf-10, account-15, perf-4, watch-27, account-10: the select and preload fixes of app.ts. */
    public function test_app_script_select_and_preload_fixes(): void
    {
        $app = $this->source('resources/js/app.ts');
        $this->assertStringContainsString("'select.select-multiple'", $app);
        $this->assertStringContainsString('maxOptions: 50', $app);
        $this->assertMatchesRegularExpression("/addEventListener\\('vite:preloadError', \\(event\\) => \\{.*?event\\.preventDefault\\(\\);.*?\\n\\}\\);/s", $app);
        $this->assertStringContainsString('avdCropperReady', $app);
        // Only a stylesheet preload failure is handled: a module that failed to load keeps its rejection (handled, the
        // import resolved to undefined and the importer threw a TypeError).
        $this->assertStringContainsString('if (/^Unable to preload CSS/i.test(message)) {', $app);
    }

    /** Member design 4.4: one scale of fixed layers. */
    public function test_fixed_layers_follow_one_scale(): void
    {
        $themeA = $this->source('resources/css/layout-theme-a.css');
        $this->assertMatchesRegularExpression('/\.avd-mobile-bottom-nav \{[^}]*z-index: 1030;/', $themeA);
        $this->assertMatchesRegularExpression('/body\.has-bottom-nav \.ad-slot-mobile \{[^}]*z-index: 1028 !important;/', $themeA);
        $this->assertStringNotContainsString('z-index: 1040 !important', $this->between($themeA, '.avd-mobile-bottom-nav {', '}'));

        $chrome = $this->source('resources/sass/enhancements.scss');
        $pill = $this->between($chrome, '.avd-daily-pill {', '}');
        $this->assertStringContainsString('inset-inline-start:', $pill);
        $this->assertStringNotContainsString('left:', $pill);
        $this->assertStringContainsString('z-index: 1040', $pill);
        $this->assertMatchesRegularExpression('/html\[data-mk-toast\] \.avd-daily-pill \{[^}]*opacity: 0/', $chrome);
        // The slim footer leaves "Back to top" its room.
        $this->assertMatchesRegularExpression('/\.avd-footer--btt-room \.avd-footer-bottom \{[^}]*padding-inline-end:/', $chrome);

        $responsive = $this->source('resources/sass/responsive.scss');
        $this->assertMatchesRegularExpression('/\.back-to-top \{[^}]*var\(--avd-bottom-chrome, 0px\) \+ var\(--mk-bars-h, 0px\)/', $responsive);
        $this->assertStringNotContainsString('.avd-auth-page {', $responsive);
    }

    /** Member design 5.10 / 4.4: one toast system that marks the page while a toast shows. */
    public function test_the_toast_system(): void
    {
        $toasts = $this->source('resources/js/layout/toasts.ts');
        $this->assertStringContainsString('data-mk-toast', $toasts);
        $this->assertStringContainsString('mk-toasts--top', $toasts);
        foreach (['success: 4000', 'info: 5000', 'warning: 7000', 'error: 8000'] as $duration) {
            $this->assertStringContainsString($duration, $toasts);
        }
        $this->assertStringContainsString("showToast(String(message ?? ''), {type: 'error'})", $this->source('resources/js/functions/toast.ts'));
    }

    /** watch-2, watch-3, watch-14, watch-22: the comments. */
    public function test_comment_fixes(): void
    {
        $this->assertStringContainsString('keepPreviousData', $this->source('resources/js/components/Comments/CommentsList.tsx'));
        $comment = $this->source('resources/js/components/Comments/Comment.tsx');
        $this->assertStringContainsString('dropdown-menu-end', $comment);
        $this->assertStringContainsString('avd-comment-reply', $comment);
        $this->assertStringNotContainsString('text-info', $comment);
        $this->assertStringContainsString('_focustrap', $this->source('resources/js/components/Comments/sheetFocus.ts'));
        $this->assertStringContainsString('window.AVD_WATCH', $this->source('resources/js/components/Comments/ExpandComment.tsx'));
    }

    /** perf-21, perf-6, perf-12 (a), watch-31: fonts, tier, header. */
    public function test_fonts_tier_and_header(): void
    {
        $app = $this->source('resources/sass/app.scss');
        $this->assertStringNotContainsString('@fortawesome/fontawesome-free/css/all.css', $app);
        $this->assertStringContainsString('@import "fontawesome";', $app);
        $this->assertStringContainsString('$fa-font-display: swap;', $this->source('resources/sass/_fontawesome.scss'));

        $layout = $this->source('resources/views/components/layout.blade.php');
        $this->assertStringContainsString('return cpuFast() ? 2 : 1;', $layout);

        $header = $this->source('resources/sass/header.scss');
        $this->assertMatchesRegularExpression('/site-notifications[^{]*\{[^}]*(min-inline-size|inline-size|width): 42px/', $header);
        $this->assertStringContainsString('--bs-navbar-padding-y: 0;', $header);

        $this->assertStringContainsString('memberPage()', $this->source('resources/js/layout/decor.ts'));
    }

    /** channel-19, channel-30: the React cards count and date like the server cards. */
    public function test_card_numbers_and_days(): void
    {
        $format = $this->source('resources/js/utils/format.ts');
        $this->assertStringContainsString("[[12, 'T'], [9, 'B'], [6, 'M'], [3, 'k']]", $format);
        $this->assertStringContainsString('Math.trunc(seconds / unitSeconds)', $format);
        $this->assertStringContainsString('new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))', $format);
    }

    private function between(string $text, string $start, string $end): string
    {
        $from = strpos($text, $start);
        $this->assertNotFalse($from, 'missing '.$start);
        $to = strpos($text, $end, $from + strlen($start));

        return substr($text, $from, ($to === false ? strlen($text) : $to + 1) - $from);
    }
}
