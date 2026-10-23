<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The theme CSS contract of the theme collection v2 (the rules of scripts/qa/css_token_lint): every colour of the site
 * chrome comes from the theme tokens, brand and accent colours are never used raw as text (they fail 4.5:1 on many
 * themes; the fitted --avd-brand-ink / --avd-accent-ink exist for that), the theme CSS uses logical sides so right-to-left
 * pages mirror, and blur exists only on the rich tier on the three surfaces the performance contract allows.
 */
class ThemeCssContractTest extends TestCase
{
    /** The theme CSS (served as cached files next to the active theme's token file). */
    private const THEME_CSS = ['resources/css/layout-theme-a.css', 'resources/css/layout-theme-b.css'];

    /**
     * The markup of pages that load main.css without a theme token file (embed shell, maintenance page, and the player
     * and its ads, which render there too): main.css rules that can match it must not depend on a theme token.
     */
    private const THEMELESS_MARKUP = [
        'resources/views/layouts/embed.blade.php', 'resources/views/videos/embed/*.blade.php', 'resources/views/maintenance.blade.php',
        'resources/js/components/Videos/Player.tsx', 'resources/js/components/Videos/Ad.tsx', 'resources/js/components/Videos/AdSlot.tsx',
        'resources/js/components/Commons/*.tsx',
    ];

    /**
     * Declarations that must stay physical, each paired with something physical it follows: Bootstrap's LTR build (the
     * select arrow, input-group corners), utilities named for a side (.left-N / .right-N) and effects moved by physical
     * transforms (the click-burst rays, the skeleton shimmer).
     */
    private const PHYSICAL_ALLOWED = [
        '/^\.form-select$/' => 'padding-right',
        '/^\.input-group > /' => 'border-(?:top|bottom)-(?:left|right)-radius',
        '/^\.(?:left|right)-x$/' => 'left|right',
        '/^\.avd-click-burst span$/' => 'left',
        '/^\.avd-placeholder::after$/' => 'left',
    ];

    /** Hands-off player menus and sheets keep the old blur rule (none on the lite tier). */
    private const PLAYER_BLUR_KEPT = ['.avd-cinema-dropdown-menu', '.avd-mobile-sheet', '.avd-mobile-sheet-fallback-backdrop'];

    /** Properties that paint a colour (custom property definitions and alpha masks are not among them). */
    private const COLOUR_PROPERTIES = '/^(?:-webkit-)?(?:background(?:-color|-image)?|border(?:-(?:top|right|bottom|left|block|inline)(?:-(?:start|end))?)?(?:-color)?|color|box-shadow|text-shadow|outline(?:-color)?|fill|stroke|caret-color|accent-color|column-rule(?:-color)?|text-decoration(?:-color)?|text-stroke(?:-color)?|text-fill-color)$/';

    /**
     * Rules this contract does not cover: the video player and its ads (hands-off, never restyled), the pointer halo
     * (placed at the physical cursor position by resources/js/motion/pointerFx.ts) and pages without a theme (the
     * embed shell keeps its fixed palette in dark.scss).
     */
    private const EXEMPT_SELECTOR = '/avd-player|#avdPlayerWrapper|#avdMainPlayerShell|avd-preroll|avd-pip|avd-ambilight|video-player|plyr|vjs-|avd-cinema-filter|avd-cursor-halo|ad-slot-preroll|avd-embed-shell|:not\(\.avd-app\)/';

    /** Keyframes the site ships (2026-10 performance contract: no new animation loops). */
    private const KEYFRAMES = [
        'avd-accent-shift-x', 'avd-atmosphere-drift', 'avd-aurora-wave', 'avd-border-beam-spin', 'avd-channel-float',
        'avd-channel-pulse-o', 'avd-channel-rise', 'avd-channel-sweep', 'avd-gradient-shift', 'avd-icon-breathe',
        'avd-liquid-shimmer-sweep', 'avd-matrix-rain-y', 'avd-modal-in', 'avd-popover-in', 'avd-scroll-progress',
        'avd-shimmer-sweep', 'avd-soft-pulse', 'avd-soft-pulse-replay', 'avd-solar-flare-pulse', 'avd-surface-scan',
        'avd-title-sheen', 'avd-title-sheen-replay', 'avd-toast-in', 'avdAmbientPulse', 'avdAmbilightPulse',
        'avdBorderBeamSpin', 'avdClickRippleCss', 'avdModalIn', 'avdUploadDialogIn', 'avdUploadFloat', 'avdUploadPulse',
        'avdUploadRing', 'avdUploadSweep',
    ];

    /**
     * What a feed page is inserted under, outermost first, on the pages with an infinite feed (home, trend, category,
     * subscriptions: components/layout.blade.php, the page's own wrappers, components/videos-area.blade.php,
     * VideosList.tsx): [tag, classes, attribute names, id]. A trailing * is a prefix. html and body also take the classes
     * scripts add (animeEnhancements.ts, layout/core.ts, app.ts).
     */
    private const FEED_ANCESTORS = [
        ['html', ['avd-anime-enabled', 'avd-reduced-motion'], ['lang', 'dir', 'class', 'data-bs-*', 'data-avd-*'], ''],
        ['body', ['avd-app', 'has-bottom-nav', 'avd-admin', 'has-sidebar', 'has-mobile-ad', 'has-hero-zone', 'theater-mode', 'avd-lights-out', 'avd-upload-modal-opening', 'modal-open'], ['class', 'style'], ''],
        ['div', ['page-wrapper'], ['class'], ''],
        ['main', ['avd-main', 'position-relative'], ['class'], ''],
        ['div', ['avd-shell', 'd-flex'], ['class'], ''],
        ['div', ['container-fluid', 'my-3', 'avd-feed-shell', 'px-0', 'px-sm-3', 'mt-0', 'mt-3', 'mt-sm-3'], ['id', 'class', 'style', 'data-avd-main'], 'main-container'],
        ['section', ['avd-home-feed', 'px-2', 'px-sm-0'], ['class', 'aria-labelledby'], ''],
        ['div', ['avd-widget-video-feed', 'px-3', 'px-sm-0', 'mb-4'], ['class', 'data-avd-widget-feed'], ''],
        ['div', ['container-fluid', 'px-0', 'px-sm-3', 'mt-4'], ['class'], ''],
        ['videos-area', [], ['url', 'columns', 'load-mode', 'max-items', 'max-rows', 'ad-context', 'early', 'priority-row', 'early-media', 'skeleton-items', 'phone-strip-screen'], ''],
        ['div', ['row', 'row-cols-*', 'avd-video-grid', 'avd-feed-grid'], ['class', 'style', 'data-avd-feed-columns', 'data-avd-card-layout', 'data-avd-visible-columns', 'data-avd-feed-waiting', 'data-avd-feed-deferred'], ''],
    ];

    /** The feed grid's own children (VideosList.tsx, Video.tsx, VideoSkeleton.tsx): cards, skeletons, ad and widget rows, the sentinel. */
    private const FEED_ITEMS = [
        ['article', ['col', 'avd-card-col', 'avd-feed-card--*'], ['class', 'data-card-layout', 'data-avd-card', 'data-avd-first-page'], ''],
        ['div', ['col', 'col-12', 'avd-grid-ad-col', 'avd-grid-ad-col--wide', 'avd-feed-widget-slot'], ['class', 'data-avd-ad-reserve', 'aria-hidden'], ''],
        ['span', [], [], ''],
    ];

    /**
     * Positional rules on the feed's items that stay: they count from the first item and a page of cards is appended
     * after the cards already there. Taking them out changed nothing measurable in the style work of an appended
     * page on demo1's ~300-card feed (weak phone, live server, 23 Oct 2026).
     */
    private const FEED_POSITIONAL_ALLOWED = [
        'html[data-avd-card-frame="hard"] .avd-video-grid > .col:nth-child(4n+1) .avd-video-card',
        'html[data-avd-card-frame="hard"] .avd-video-grid > .col:nth-child(4n+2) .avd-video-card',
        'html[data-avd-card-frame="hard"] .avd-video-grid > .col:nth-child(4n+3) .avd-video-card',
        'html[data-avd-card-frame="hard"] .avd-video-grid > .col:nth-child(4n+4) .avd-video-card',
        'html[data-avd-card-frame="column"] .avd-video-grid > .col:nth-child(4n+1) .avd-video-card',
        '.avd-channel-page[data-channel-video-layout="showcase"] #videos .avd-video-grid > .col:first-child',
    ];

    public function test_brand_and_accent_colours_are_never_raw_text_colours(): void
    {
        $violations = [];

        foreach ($this->ownedFiles() as $file) {
            foreach ($this->declarations($file) as [$selector, $property, $value]) {
                if (! in_array($property, ['color', '-webkit-text-fill-color', 'caret-color'], true) || $this->exempt($selector)) {
                    continue;
                }

                if (preg_match('/var\(--(?:avd-(?:brand|brand-2|brand-secondary|accent|cyan|primary|theme-primary)|bs-primary-raw)\)|rgba?\(\s*var\(--avd-(?:brand|brand-2|accent|cyan|primary|theme-primary)-rgb\)/', $this->withoutFallbacks($value))) {
                    $violations[] = "$file: $selector { $property: $value }";
                }
            }
        }

        $this->assertSame([], $violations, 'Brand-coloured text must use --avd-brand-ink / --avd-accent-ink (fitted to 4.5:1).');
    }

    public function test_the_chrome_has_no_hard_coded_hex_colours(): void
    {
        $violations = [];

        foreach ($this->ownedFiles() as $file) {
            foreach ($this->declarations($file) as [$selector, $property, $value]) {
                if (! preg_match(self::COLOUR_PROPERTIES, $property) || $this->exempt($selector)) {
                    continue;
                }

                if (preg_match('/#[0-9a-f]{3,8}\b/i', $this->withoutFallbacks($value))) {
                    $violations[] = "$file: $selector { $property: $value }";
                }
            }
        }

        $this->assertSame([], $violations, 'Chrome colours come from the theme tokens; literals belong in token definitions only.');
    }

    public function test_the_theme_css_uses_logical_sides(): void
    {
        $violations = [];

        foreach ($this->ownedFiles() as $file) {
            foreach ($this->declarations($file) as [$selector, $property, $value]) {
                if ($this->exempt($selector) || $this->physicalAllowed($selector, $property)) {
                    continue;
                }

                $physical = in_array($property, ['left', 'right'], true)
                    ? ! in_array(trim(str_replace('!important', '', $value)), ['auto', '50%'], true)
                    : (bool) preg_match('/^(?:margin|padding|border|scroll-margin|scroll-padding)-(?:left|right)\b|^border-(?:top|bottom)-(?:left|right)-radius$/', $property)
                        || (in_array($property, ['float', 'clear', 'text-align'], true) && preg_match('/^(?:left|right)\b/', trim($value)));

                if ($physical) {
                    $violations[] = "$file: $selector { $property: $value }";
                }
            }
        }

        $this->assertSame([], $violations, 'Use inset-inline / margin-inline / padding-inline / border-inline (start, end) so right-to-left pages mirror.');
    }

    public function test_backdrop_filter_exists_only_on_the_rich_tier_and_only_on_the_allowed_surfaces(): void
    {
        $violations = [];
        $surfaces = [];

        foreach ($this->ownedFiles() as $file) {
            foreach ($this->declarations($file) as [$selector, $property, $value, $media]) {
                if (! in_array($property, ['backdrop-filter', '-webkit-backdrop-filter'], true) || $this->exempt($selector)) {
                    continue;
                }

                if (trim(str_replace('!important', '', $value)) === 'none') {
                    continue;
                }

                foreach (array_map('trim', explode(',', $selector)) as $one) {
                    if (! str_contains($one, '[data-avd-tier="rich"]') || str_contains($media, 'prefers-reduced-transparency')) {
                        $violations[] = "$file: $one { $property: $value }";
                    }

                    $surfaces[] = preg_replace('/^.*?\s(?=[#.][^\s]*$)/', '', $one);
                }
            }
        }

        $this->assertSame([], $violations, 'backdrop-filter only under html[data-avd-tier="rich"] (performance contract).');
        $this->assertSame(['#responsive-sidebar.show_sidebar', '.avd-header', '.avd-split'], $this->sorted($surfaces));
    }

    public function test_reduced_transparency_drops_every_allowed_blur(): void
    {
        // Every allowed blur and its reduced-transparency override live in one file (layout-theme-a.css).
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-reduced-transparency:\s*reduce\)\s*\{\s*\.avd-header,\s*#responsive-sidebar\.avd-sidebar,\s*\.avd-split\s*\{[^}]*backdrop-filter:\s*none !important;/s', $this->read('resources/css/layout-theme-a.css'));
        $this->assertDoesNotMatchRegularExpression('/backdrop-filter:\s*blur/', $this->strip($this->read('resources/css/layout-theme-b.css')));

        $css = $this->read('resources/css/layout-theme-b.css');
        // A see-through bar that turns solid takes the solid surface's ink too (overlay inks are often white).
        $this->assertMatchesRegularExpression('/@media\s*\(prefers-reduced-transparency:\s*reduce\)\s*\{[^@]*\.avd-header\s*\{\s*--avd-header-text:\s*var\(--avd-text\) !important;\s*background:\s*var\(--avd-surface-solid\) !important;/s', $css);
        $this->assertMatchesRegularExpression('/html\[data-avd-header-style="transparent_overlay"\] \.avd-header\s*\{\s*--avd-header-text:\s*var\(--avd-text\);\s*background:\s*var\(--avd-bg\);/', $this->read('resources/css/layout-theme-a.css'));
    }

    public function test_feed_pages_restyle_only_their_own_cards(): void
    {
        // The browser re-checks a :has() rule, and everything it styles, whenever something is inserted under its
        // subject. With the subject on html or body (v7's transparent bar over the hero, the back-office menu width, the
        // phone ad strip) every feed page restyled the whole page: demo1's weak-phone scroll fell to ~5 frames a second.
        // The layout prints markers instead (body.has-hero-zone, html[data-avd-back-office]); the feed marks its first
        // cards (article[data-avd-first-page]) instead of counting them. A :has() on a card that looks only at the
        // card's own children (the glass caption's focus ring) is re-checked for that card alone and may stay.
        $this->assertSame([], $this->feedRestyleViolations($this->cssSources()), 'An appended feed page must restyle only its own cards (2026-10 performance contract).');

        $a = $this->read('resources/css/layout-theme-a.css');
        // Engines without :has() keep the solid bar over the hero, as they did when the bar tested the page with :has().
        $this->assertMatchesRegularExpression('/@supports selector\(:has\(a\)\) \{\s*html\[data-avd-header-style="transparent_overlay"\] body\.has-hero-zone \.avd-header \{/', $a);
        $this->assertStringContainsString('html[data-avd-theme][data-avd-sidebar-style][data-avd-back-office] {', $a);
        $this->assertStringContainsString('.avd-card-body > a.avd-card-avatar-link[href]', $a);
        $this->assertStringContainsString('.avd-card-body > a.avd-card-avatar-link[href]', $this->read('resources/sass/video.scss'));
        $this->assertStringContainsString('.avd-video-grid > article.col[data-avd-first-page] > .avd-video-card', $this->read('resources/sass/feed-skeleton.scss'));

        // The markup prints the markers these rules key on.
        $video = $this->read('resources/js/components/Videos/Video.tsx');
        $this->assertStringContainsString('className="avd-card-avatar-link"', $video);
        $this->assertStringContainsString("data-avd-first-page={firstPage ? '' : undefined}", $video);
        $list = $this->read('resources/js/components/Videos/VideosList.tsx');
        $this->assertStringContainsString('const FIRST_PAGE_CARDS = 24;', $list);
        $this->assertStringContainsString('firstPage={globalIdx <= FIRST_PAGE_CARDS}', $list);
        $this->assertStringContainsString('class="avd-card-avatar-link"', $this->read('resources/views/videos/card.blade.php'));
        $layout = $this->read('resources/views/components/layout.blade.php');
        $this->assertStringContainsString("'has-hero-zone' => \$pageHasHeroZone", $layout);
        $this->assertStringContainsString('@if($backOfficeMenu) data-avd-back-office @endif', $layout);
        $this->assertStringContainsString("@section(\\App\\Support\\ThemeStructure::HERO_ZONE_SECTION, '1')", $this->read('resources/views/pages/partials/hero.blade.php'));
    }

    public function test_no_new_animation_loops(): void
    {
        $names = [];

        foreach ($this->ownedFiles() as $file) {
            preg_match_all('/@(?:-webkit-)?keyframes\s+([\w-]+)/', $this->strip($this->read($file)), $matches);
            $names = array_merge($names, $matches[1]);
        }

        $this->assertSame([], array_values(array_diff(array_unique($names), self::KEYFRAMES)), 'No new @keyframes: identity comes from static tokens (performance contract).');
    }

    public function test_dead_preset_rules_and_the_unconditional_card_glow_are_gone(): void
    {
        $css = $this->strip($this->read('resources/css/layout-theme-a.css').$this->read('resources/css/layout-theme-b.css'));

        // G15: colour-grading and modal options that no longer exist.
        foreach (['warm_film', 'cyber_cool', 'vintage_retro', 'noir_monochrome', 'vivid_pop', 'spring_scale', 'slide_drawer', 'glassmorphism_fade'] as $dead) {
            $this->assertStringNotContainsString($dead, $css);
        }

        // G17: the card glow follows video_card_hover_glow_enabled; no unconditional :hover glow.
        foreach ($this->declarations('resources/css/layout-theme-a.css') as [$selector, $property]) {
            if ($property === 'box-shadow' && str_contains($selector, '.avd-video-card:hover') && str_contains($selector, 'glow')) {
                $this->assertStringContainsString('[data-avd-card-hover-glow="on"]', $selector);
            }
        }
        $this->assertStringNotContainsString('.avd-video-card:hover, .suggested_video:hover, .video-card:hover', $css);

        // G21: scrollbars are drawn from the theme (app.scss variables), never with fixed dark colours.
        foreach ($this->declarations('resources/css/layout-theme-b.css') as [$selector, $property, $value]) {
            if (str_contains($selector, 'scrollbar') && preg_match(self::COLOUR_PROPERTIES, $property)) {
                $this->assertDoesNotMatchRegularExpression('/rgba?\(\s*\d/', $value, $selector);
            }
        }
    }

    public function test_the_page_paints_from_the_theme_and_the_embed_shell_keeps_its_palette(): void
    {
        $a = $this->strip($this->read('resources/css/layout-theme-a.css'));
        $dark = $this->strip($this->read('resources/sass/dark.scss'));

        $this->assertMatchesRegularExpression('/body\.avd-app\s*\{[^}]*background:\s*var\(--avd-bg\)/', $a);
        $this->assertMatchesRegularExpression('/body\.avd-app::before\s*\{[^}]*position:\s*fixed;[^}]*background:\s*var\(--avd-backdrop-tile\),\s*var\(--avd-page-backdrop\)/', $a);
        $this->assertMatchesRegularExpression('/\[data-bs-theme="dark"\] body:not\(\.avd-app\)\s*\{[^}]*radial-gradient/', $dark);
        $this->assertStringNotContainsString('[data-bs-theme="dark"] body {', $dark);
    }

    public function test_bootstrap_brand_utilities_carry_the_fitted_label_and_ink(): void
    {
        $rules = [];
        foreach ($this->declarations('resources/css/layout-theme-a.css') as [$selector, $property, $value]) {
            foreach (array_map('trim', explode(',', $selector)) as $one) {
                $rules[$one][$property] = $value;
            }
        }

        foreach (['.bg-primary', '.text-bg-primary', '.badge.bg-primary'] as $fill) {
            $this->assertSame('var(--avd-brand-fill) !important', $rules[$fill]['background-color'] ?? null, $fill);
            $this->assertSame('var(--avd-on-brand) !important', $rules[$fill]['color'] ?? null, $fill);
        }
        $this->assertSame('var(--avd-brand-ink) !important', $rules['.text-primary']['color'] ?? null);

        foreach (['success', 'danger', 'info'] as $status) {
            $this->assertSame("var(--avd-$status-fill) !important", $rules[".bg-$status"]['background-color'] ?? null);
            $this->assertSame("var(--avd-on-$status) !important", $rules[".bg-$status"]['color'] ?? null);
        }
    }

    public function test_main_css_rules_that_reach_pages_without_a_theme_keep_their_old_values(): void
    {
        // The embed shell and the maintenance page load main.css but no token file: a theme token read there with no
        // fallback resolves to nothing (the player's "Try again" and the ad's call to action lost their button look).
        $classes = $this->themelessClasses();
        $defined = [];
        foreach ($this->ownedFiles() as $file) {
            if (str_starts_with($file, 'resources/sass/')) {
                preg_match_all('/(--avd-[\w-]+)\s*:/', $this->strip($this->read($file)), $matches);
                $defined += array_flip($matches[1]);
            }
        }

        $violations = [];
        foreach ($this->ownedFiles() as $file) {
            if (! str_starts_with($file, 'resources/sass/')) {
                continue;
            }

            foreach ($this->declarations($file) as [$selector, $property, $value]) {
                $reachable = array_filter(array_map('trim', explode(',', $selector)), fn ($one) => $this->matchesThemelessMarkup($one, $classes));
                if ($reachable === [] || $this->exempt($selector)) {
                    continue;
                }

                preg_match_all('/var\(\s*(--avd-[\w-]+)\s*\)/', $value, $bare);
                foreach ($bare[1] as $name) {
                    if (! isset($defined[$name])) {
                        $violations[] = "$file: ".implode(', ', $reachable)." { $property: $value } reads $name";
                    }
                }
            }
        }

        $this->assertArrayHasKey('btn-primary', $classes, 'The player markup is part of the theme-less pages.');
        $this->assertSame([], $violations, 'Give the theme token a fallback equal to the old value (pages without a theme file show it).');
    }

    public function test_blur_set_by_views_is_dropped_on_every_tier(): void
    {
        $neutraliser = null;
        foreach ($this->declarations('resources/css/layout-theme-b.css') as [$selector, $property, $value]) {
            if ($property === 'backdrop-filter' && $value === 'none !important' && str_contains($selector, '.avd-up-next-card')) {
                $neutraliser = array_map('trim', explode(',', $selector));
            }
        }
        $this->assertNotNull($neutraliser, 'layout-theme-b.css drops the blur that views still set.');

        $declarations = [];
        foreach ($this->publicViews() as $view) {
            preg_match_all('#<style[^>]*>(.*?)</style>#s', $this->read($view), $blocks);
            foreach ($blocks[1] as $css) {
                $this->walk($this->strip($css), [''], '', $declarations);
            }
        }

        $sources = [];
        foreach ($declarations as [$selector, $property, $value]) {
            if (in_array($property, ['backdrop-filter', '-webkit-backdrop-filter'], true) && ! str_starts_with($value, 'none')) {
                foreach (array_map('trim', explode(',', $selector)) as $one) {
                    $sources[$one] = true;
                }
            }
        }
        $this->assertNotEmpty($sources);

        // A view's <style> block comes after the theme CSS and is often !important: the neutraliser must out-rank it.
        $missing = [];
        foreach (array_keys($sources) as $source) {
            $target = preg_replace('/^.*\s/', '', $source);
            if (in_array($target, self::PLAYER_BLUR_KEPT, true)) {
                continue;
            }

            $covered = array_filter($neutraliser, fn ($one) => str_ends_with($one, ' '.$target) && $this->specificity($one) > $this->specificity($source));
            if ($covered === []) {
                $missing[] = $source;
            }
        }

        $this->assertSame([], $missing, 'Each blur a view sets is dropped by a selector that out-ranks it.');
        // Inline style attributes (the sticky watch bar, modal wrappers) and the player's menus on the lite tier.
        foreach (['html[data-bs-theme] #avdStickyWatchBar', '.modal[style*="backdrop-filter"]', '.modal-content[style*="backdrop-filter"]', 'html[data-avd-perf="lite"][data-bs-theme][data-avd-surface] .dropdown-menu', 'html[data-avd-perf="lite"][data-bs-theme] .offcanvas'] as $inline) {
            $this->assertContains($inline, $neutraliser);
        }
    }

    public function test_owner_settings_and_the_player_block_win_over_the_theme_recipe(): void
    {
        $a = $this->strip($this->read('resources/css/layout-theme-a.css'));
        $motion = $this->strip($this->read('resources/sass/motion.scss'));

        // sidebar_style is an owner setting: the pinned width follows the chosen style, not the theme's own recipe.
        $this->assertMatchesRegularExpression('/html\[data-avd-theme\]\[data-avd-sidebar-style\]\s*\{\s*--avd-sidebar-w:\s*232px;/', $a);
        $this->assertMatchesRegularExpression('/html\[data-avd-theme\]\[data-avd-sidebar-style="rail"\]\s*\{\s*--avd-sidebar-w:\s*76px;/', $a);
        $this->assertMatchesRegularExpression('/html\[data-avd-theme\]\[data-avd-sidebar-style="hidden"\]\s*\{\s*--avd-sidebar-w:\s*0px;/', $a);

        // Each control takes its own theme radius; the control radius is never the theme's (its 999px pills turned the
        // player's menus and every textarea into ovals).
        $this->assertDoesNotMatchRegularExpression('/--avd-radius-control\s*:/', $a);
        $this->assertStringContainsString('--avd-radius-field: min(calc(var(--avd-r-control) * var(--avd-radius-k)), calc(var(--avd-control-h) / 2));', $a);
        $this->assertMatchesRegularExpression('/html\[data-avd-radius\] \.btn\s*\{\s*border-radius:\s*var\(--avd-radius-button, var\(--avd-radius-control\)\) !important;/', $motion);
        $this->assertMatchesRegularExpression('/html\[data-avd-radius\] \.dropdown-menu,\s*html\[data-avd-radius\] \.avd-player-control\s*\{\s*border-radius:\s*var\(--avd-radius-control\) !important;/', $motion);
        $this->assertMatchesRegularExpression('/html\[data-avd-radius\] \.avd-player-frame\s*\{\s*border-radius:\s*var\(--avd-radius-frame\) !important;/', $motion);

        // The player block keeps the pre-theme buttons: custom properties are unset on it, plus the old theme CSS's
        // primary / active fills at their old specificity. Nothing else in the theme CSS styles it (a rule may only
        // exclude it, as the light-mode compatibility rules do).
        $this->assertMatchesRegularExpression('/#avdPlayerWrapper\s*\{(?:\s*--avd-[\w-]+:\s*initial;)+\s*\}/', $a);
        foreach ($this->declarations('resources/css/layout-theme-a.css') as [$selector, $property]) {
            if (str_contains(str_replace(':not(#avdPlayerWrapper *)', '', $selector), 'avdPlayerWrapper')) {
                $this->assertStringStartsWith(':where(#avdPlayerWrapper) .btn-primary', $selector);
                $this->assertContains($property, ['border-color', 'background-color', 'color']);
            }
        }

        // surface_style and text_contrast act on the tokens (theme_spec settings_changes.modifiers).
        $this->assertMatchesRegularExpression('/html\[data-avd-surface="solid"\]\[data-avd-theme\]\s*\{\s*--avd-surface-1:\s*var\(--avd-surface-solid\);\s*--avd-sidebar-bg:\s*var\(--avd-surface-solid\);/', $a);
        // A frameless card stays frameless and a designed colour band keeps its colour (ThemeTokens real-page tokens);
        // the transparent bar over a hero keeps its scrim and white ink (Gate A, light mode).
        $this->assertMatchesRegularExpression('/html\[data-avd-surface="solid"\]\[data-avd-theme\]\s*\{[^}]*--avd-card-bg:\s*var\(--avd-card-solid-bg, var\(--avd-surface-solid\)\);/', $a);
        $this->assertMatchesRegularExpression('/html\[data-avd-surface="solid"\]\[data-avd-theme\]:not\(\[data-avd-header-style="transparent_overlay"\]\)\s*\{\s*--avd-header-bg:\s*var\(--avd-header-solid-bg, var\(--avd-surface-solid\)\);\s*\}/', $a);
        // Over the hero the transparent bar reads the theme's overlay tokens (the bar's own tokens by default).
        $this->assertMatchesRegularExpression('/:where\(html\[data-avd-theme\]\)\s*\{\s*--avd-header-overlay-bg:\s*var\(--avd-header-bg\);\s*--avd-header-overlay-text:\s*var\(--avd-header-text\);\s*\}/', $a);
        $this->assertMatchesRegularExpression('/html\[data-avd-header-style="transparent_overlay"\] body\.has-hero-zone \.avd-header\s*\{\s*--avd-header-text:\s*var\(--avd-header-overlay-text\);[^}]*background:\s*var\(--avd-header-overlay-bg\);/', $a);
        // "Pure black in dark mode" is derived by the colour engine (ThemeTokens::ownerOverrides), not overridden here.
        $this->assertStringNotContainsString('data-avd-oled', $a);
        $this->assertMatchesRegularExpression('/html\[data-avd-surface="contrast"\]\[data-avd-theme\]\s*\{\s*--avd-border:\s*var\(--avd-border-strong\);\s*--avd-muted:\s*color-mix\(in oklab, var\(--avd-text\) 80%, var\(--avd-bg\)\);/', $a);
    }

    /** @return list<string> */
    private function ownedFiles(): array
    {
        $sass = array_map(fn ($path) => 'resources/sass/'.basename($path), glob($this->root().'/resources/sass/*.scss') ?: []);

        return array_merge(self::THEME_CSS, array_values(array_diff($sass, ['resources/sass/normalize.scss'])));
    }

    /**
     * Every declaration of a CSS/SCSS file with its full selector (SCSS nesting resolved) and the media/supports
     * conditions it sits in. Custom property definitions are left out: they are where colour literals belong.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function declarations(string $file): array
    {
        $css = $this->strip($this->read($file));
        $out = [];
        $this->walk($css, [''], '', $out);

        return $out;
    }

    private function walk(string $css, array $selectors, string $media, array &$out): void
    {
        $length = strlen($css);
        $buffer = '';
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ';' && $depth === 0) {
                $this->declare(trim($buffer), $selectors, $media, $out);
                $buffer = '';

                continue;
            }

            if ($char !== '{') {
                $buffer .= $char;

                continue;
            }

            $prelude = trim($buffer);
            $buffer = '';
            $end = $this->matchingBrace($css, $i);
            $body = substr($css, $i + 1, $end - $i - 1);
            $i = $end;

            if (preg_match('/^@(?:-webkit-)?keyframes|^@font-face|^@(?:mixin|function)/', $prelude)) {
                continue;
            }

            if (str_starts_with($prelude, '@')) {
                $this->walk($body, $selectors, trim($media.' '.$prelude), $out);

                continue;
            }

            $nested = [];
            foreach ($selectors as $parent) {
                foreach (array_map('trim', explode(',', $prelude)) as $child) {
                    $nested[] = $parent === '' ? $child : (str_contains($child, '&') ? str_replace('&', $parent, $child) : $parent.' '.$child);
                }
            }
            $this->walk($body, $nested, $media, $out);
        }

        $this->declare(trim($buffer), $selectors, $media, $out);
    }

    private function declare(string $declaration, array $selectors, string $media, array &$out): void
    {
        if (! preg_match('/^([a-z-]+)\s*:\s*(.+)$/s', $declaration, $match) || str_starts_with($match[1], '--')) {
            return;
        }

        $out[] = [implode(', ', $selectors), strtolower($match[1]), trim(preg_replace('/\s+/', ' ', $match[2])), $media];
    }

    /**
     * Every style sheet the site can serve, as text: the theme CSS and SCSS, the theme token files and the <style> blocks
     * of the views (Blade comments, echoes and directives taken out).
     *
     * @return array<string, string>
     */
    private function cssSources(): array
    {
        $sources = [];
        foreach ($this->ownedFiles() as $file) {
            $sources[$file] = $this->read($file);
        }

        foreach (['resources/css/themes' => '.css', 'resources/views' => '.blade.php'] as $directory => $extension) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), $extension)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));
                $text = (string) file_get_contents($file->getPathname());
                if ($extension === '.css') {
                    $sources[$relative] = $text;
                } elseif (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $text, $blocks)) {
                    $css = preg_replace(['/\{\{--.*?--\}\}/s', '/\{!!.*?!!\}|\{\{.*?\}\}/s'], ['', 'x'], implode("\n", $blocks[1]));
                    $sources[$relative] = preg_replace('/@(?!(?:-webkit-)?(?:media|supports|keyframes|font-face|import|layer|container|page|charset|property|counter-style)\b)[a-z]+\b(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/i', '', $css);
                }
            }
        }
        ksort($sources);

        return $sources;
    }

    /**
     * Rules that make an appended feed page restyle more than its own cards: a :has() whose subject can be an element
     * the page is inserted under (re-checked, with everything it styles, on every insertion), a :has() on a feed item
     * that looks at the items after it, and a positional rule on the feed's items (FEED_POSITIONAL_ALLOWED excepted).
     * Functional pseudo-classes (:is(), :where(), :not()) are ignored when matching, which only ever flags more.
     *
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private function feedRestyleViolations(array $sources): array
    {
        $violations = [];

        foreach ($sources as $label => $css) {
            foreach ($this->ruleSelectors($css) as $rule) {
                if (! preg_match('/:has\(|:(?:nth-|first-|last-|only-)/', $rule)) {
                    continue;
                }

                $selector = $this->normalizeSelector($rule);
                $parts = $this->splitTopLevel($selector, ' ');
                foreach ($parts as $index => $compound) {
                    if (in_array($compound, ['>', '+', '~'], true)) {
                        continue;
                    }

                    foreach ($this->hasArguments($compound) as $argument) {
                        if ($this->couldMatchAny($compound, self::FEED_ANCESTORS)) {
                            $violations[] = "$label: $selector (a :has() on an element feed pages are inserted under)";
                        } elseif (in_array($argument[0] ?? '', ['+', '~'], true) && $this->couldMatchAny($compound, self::FEED_ITEMS)) {
                            $violations[] = "$label: $selector (a :has() on a feed item that looks at the items after it)";
                        }
                    }

                    $positional = preg_match('/:(?:nth-|first-|last-|only-)/', $this->withoutHas($compound));
                    $inFeed = preg_match('/\.avd-(?:video|feed)-grid\b|(?:^| )videos-area\b/', implode(' ', array_slice($parts, 0, $index)));
                    if ($positional && $inFeed && $this->couldMatchAny($compound, self::FEED_ITEMS) && ! in_array($selector, self::FEED_POSITIONAL_ALLOWED, true)) {
                        $violations[] = "$label: $selector (a positional rule on the feed's items)";
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> the full selector of every style rule (SCSS nesting resolved), rules that only set tokens included */
    private function ruleSelectors(string $css): array
    {
        $out = [];
        $this->collectSelectors($this->strip($css), [''], $out);

        return $out;
    }

    private function collectSelectors(string $css, array $parents, array &$out): void
    {
        $buffer = '';
        $depth = 0;

        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ';' && $depth === 0) {
                $buffer = '';

                continue;
            }

            if ($char !== '{') {
                $buffer .= $char;

                continue;
            }

            $prelude = trim($buffer);
            $buffer = '';
            $end = $this->matchingBrace($css, $i);
            $body = substr($css, $i + 1, $end - $i - 1);
            $i = $end;

            if (preg_match('/^@(?:-webkit-)?keyframes|^@(?:font-face|mixin|function|page|property|counter-style)\b/', $prelude)) {
                continue;
            }

            if (str_starts_with($prelude, '@')) {
                $this->collectSelectors($body, $parents, $out);

                continue;
            }

            $nested = [];
            foreach ($parents as $parent) {
                foreach ($this->splitTopLevel($prelude, ',') as $child) {
                    $nested[] = $parent === '' ? $child : (str_contains($child, '&') ? str_replace('&', $parent, $child) : $parent.' '.$child);
                }
            }
            array_push($out, ...$nested);
            $this->collectSelectors($body, $nested, $out);
        }
    }

    /** @return list<string> the parts of $text split at $separator outside brackets and parentheses, trimmed, empty ones left out */
    private function splitTopLevel(string $text, string $separator): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }
        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }

    /** One space around each combinator and between compounds (outside brackets and parentheses); nothing else changes. */
    private function normalizeSelector(string $selector): string
    {
        $selector = (string) preg_replace('/\s+/', ' ', trim($selector));
        $out = '';
        $depth = 0;

        for ($i = 0, $length = strlen($selector); $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            $out .= $depth === 0 && in_array($char, ['>', '+', '~'], true) ? ' '.$char.' ' : $char;
        }

        return trim((string) preg_replace('/ {2,}/', ' ', $out));
    }

    /** @return list<string> the argument of every :has() of a compound selector, nested ones included */
    private function hasArguments(string $compound): array
    {
        $arguments = [];
        $offset = 0;

        while (($start = strpos($compound, ':has(', $offset)) !== false) {
            $end = $this->closingParenthesis($compound, $start + 4);
            $arguments[] = trim(substr($compound, $start + 5, $end - $start - 5));
            $offset = $start + 5;
        }

        return $arguments;
    }

    /** The compound without its :has() parts (what the compound itself has to match). */
    private function withoutHas(string $compound): string
    {
        while (($start = strpos($compound, ':has(')) !== false) {
            $compound = substr($compound, 0, $start).substr($compound, $this->closingParenthesis($compound, $start + 4) + 1);
        }

        return $compound;
    }

    private function closingParenthesis(string $text, int $open): int
    {
        $depth = 0;

        for ($i = $open, $length = strlen($text); $i < $length; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')' && --$depth === 0) {
                return $i;
            }
        }

        return strlen($text) - 1;
    }

    private function couldMatchAny(string $compound, array $elements): bool
    {
        foreach ($elements as $element) {
            if ($this->couldMatch($compound, $element)) {
                return true;
            }
        }

        return false;
    }

    /** Could the compound match the element [tag, classes, attribute names, id]? Functional pseudo-classes are ignored. */
    private function couldMatch(string $compound, array $element): bool
    {
        [$tag, $classes, $attributes, $id] = $element;
        $plain = (string) preg_replace('/\[\s*([\w-]+)[^\]]*\]/', '[$1]', $compound);

        while (preg_match('/:[\w-]+\(/', $plain, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1];
            $plain = substr($plain, 0, $start).substr($plain, $this->closingParenthesis($plain, $start + strlen($match[0][0]) - 1) + 1);
        }
        $plain = (string) preg_replace('/::[\w-]+/', '', $plain);

        if ((preg_match('/^([a-z][\w-]*)/i', $plain, $type) && strcasecmp($type[1], $tag) !== 0) || (str_contains($plain, ':root') && $tag !== 'html')) {
            return false;
        }

        preg_match_all('/\.([\w-]+)/', $plain, $names);
        preg_match_all('/\[([\w-]+)\]/', $plain, $attributeNames);
        preg_match_all('/#([\w-]+)/', $plain, $ids);

        foreach ($names[1] as $name) {
            if (! $this->listed($name, $classes)) {
                return false;
            }
        }

        foreach ($attributeNames[1] as $name) {
            if (! $this->listed($name, $attributes)) {
                return false;
            }
        }

        foreach ($ids[1] as $name) {
            if ($name !== $id) {
                return false;
            }
        }

        return true;
    }

    /** $name is in $list, where an entry ending in * is a prefix. */
    private function listed(string $name, array $list): bool
    {
        foreach ($list as $entry) {
            if ($entry === $name || (str_ends_with($entry, '*') && str_starts_with($name, substr($entry, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private function matchingBrace(string $css, int $open): int
    {
        $level = 0;

        for ($i = $open, $length = strlen($css); $i < $length; $i++) {
            if ($css[$i] === '{') {
                $level++;
            } elseif ($css[$i] === '}' && --$level === 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    /** Comments out (CSS block comments, SCSS line comments) and SCSS interpolations neutralised. */
    private function strip(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $css = preg_replace('#^\s*//.*$#m', '', $css);

        return preg_replace('/#\{[^}]*\}/', 'x', $css);
    }

    /** var(--x, fallback) -> var(--x): a fallback for pages without a theme file is not a chrome colour. */
    private function withoutFallbacks(string $value): string
    {
        do {
            $value = preg_replace('/var\(\s*(--[\w-]+)\s*,(?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', 'var($1)', $value, -1, $count);
        } while ($count > 0);

        return $value;
    }

    private function exempt(string $selector): bool
    {
        return (bool) preg_match(self::EXEMPT_SELECTOR, $selector);
    }

    private function physicalAllowed(string $selector, string $property): bool
    {
        foreach (self::PHYSICAL_ALLOWED as $pattern => $properties) {
            if (preg_match($pattern, $selector) && preg_match('/^(?:'.$properties.')$/', $property)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> every class-like token in the string literals of the theme-less markup (generous) */
    private function themelessClasses(): array
    {
        $classes = [];
        foreach (self::THEMELESS_MARKUP as $pattern) {
            foreach (glob($this->root().'/'.$pattern) ?: [] as $path) {
                preg_match_all('/["\'`]([^"\'`]*)["\'`]/', (string) file_get_contents($path), $matches);
                foreach ($matches[1] as $literal) {
                    foreach (preg_split('/[\s{}()]+/', $literal) as $token) {
                        if (preg_match('/^[a-z][\w-]*$/i', $token)) {
                            $classes[$token] = true;
                        }
                    }
                }
            }
        }

        return $classes;
    }

    /** A compound selector can match the theme-less markup when every class it names is one that markup carries. */
    private function matchesThemelessMarkup(string $selector, array $classes): bool
    {
        if (str_contains($selector, '.avd-app') || str_contains($selector, '[data-avd-theme')) {
            return false;
        }

        preg_match_all('/\.([\w-]+)/', $selector, $names);
        foreach ($names[1] as $name) {
            if (! isset($classes[$name])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> public views (not the admin, installer or embed views; the page head is the theme engine's) */
    private function publicViews(): array
    {
        $views = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));
            if (str_ends_with($relative, '.blade.php') && ! preg_match('#^resources/views/(?:admin/|install/|videos/embed/|layouts/embed\.blade\.php|components/layout\.blade\.php)#', $relative)) {
                $views[] = $relative;
            }
        }
        sort($views);

        return $views;
    }

    /** CSS specificity as one comparable number (ids, then classes / attributes / pseudo-classes, then types). */
    private function specificity(string $selector): int
    {
        $selector = preg_replace('/:where\([^)]*\)|::[\w-]+/', '', $selector);
        $ids = preg_match_all('/#[\w-]+/', $selector);
        $classes = preg_match_all('/\.[\w-]+|\[[^\]]*\]|:(?!not\b|is\b)[\w-]+/', $selector);
        $types = preg_match_all('/(?:^|[\s>+~])[a-z][\w-]*/i', $selector);

        return $ids * 10000 + $classes * 100 + $types;
    }

    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private function read(string $file): string
    {
        $path = $this->root().'/'.$file;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
