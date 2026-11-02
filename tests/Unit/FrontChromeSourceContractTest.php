<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.2.3: the remaining site-wide fixes read from their sources (styles, scripts and the release's Blade
 * partials), one test per QA area. Each assertion names its id.
 */
class FrontChromeSourceContractTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 2).'/'.$path;
        $this->assertFileExists($file);

        return str_replace("\r\n", "\n", (string) file_get_contents($file));
    }

    public function test_header_brand_sign_in_and_bell(): void
    {
        $header = $this->source('resources/sass/header.scss');
        // account-25 / studio-37: with the Premium chip the brand grows instead of cutting the name.
        $this->assertMatchesRegularExpression('/\.navbar-brand\.avd-brand\.show_sidebar\.is-premium \{[^}]*width: auto;/', $header);
        // auth-27: the header Register button fits "Зарегистрироваться"; round 1 (R1-053) also "GUMAWA NG ACCOUNT" in the
        // capitals and wide tracking of Imperial Salon (about 17rem).
        $this->assertMatchesRegularExpression('/\.btn\.avd-auth-btn \{[^}]*max-width: 24rem/', $header);
        // account-49: the bell takes the header's icon colour.
        $this->assertMatchesRegularExpression('/site-notifications[^{]*\{[^}]*color: inherit/', $header);
        // watch-20: the drawer's title ends with an ellipsis before the close button.
        $this->assertMatchesRegularExpression('/\.avd-drawer-brand-name \{[^}]*text-overflow: ellipsis/', $header);
        // channel-32 / watch-46: the side menu's "See all" links are 44px targets apart from their heading.
        $this->assertMatchesRegularExpression('/\.avd-sidebar-heading__link \{[^}]*padding: 14px 8px;/', $header);

        // account-24 / auth-26: the brand zone is centred only from 992px, next to a pinned menu.
        $this->assertStringContainsString("'justify-content-lg-center' => \$sidebarPinned", $this->source('resources/views/layouts/menus/headers/brand.blade.php'));
        $this->assertStringNotContainsString('justify-content-md-center', $this->source('resources/views/layouts/menus/headers/brand.blade.php'));
        // studio-40: the back office prints the one-row bar (no masthead dateline or crest).
        $this->assertStringContainsString("(\$isBackOfficeLayout ?? false) && in_array(\$structure['header_deco'], ['masthead', 'crest'], true)", $this->source('resources/views/layouts/menus/header.blade.php'));

        // auth-8 / auth-18: the layout's sign-in entries come back to the page, in the visitor's language, through the
        // header's own link (round 3 R3-079: ReturnPath::loginUrl(), never the address as received).
        $layout = $this->source('resources/views/components/layout.blade.php');
        $this->assertStringContainsString("\$signInUrl = \$isAuthRoute || ! request()->isMethod('GET') ? locale_url(route('login')) : \\App\\Http\\Controllers\\Auth\\ReturnPath::loginUrl();", $layout);
        $this->assertStringNotContainsString("http_build_query(['redirect' => request()->getRequestUri()])", $layout);
    }

    public function test_focus_forms_and_cards(): void
    {
        $themeB = $this->source('resources/css/layout-theme-b.css');
        // watch-44 / auth-31: one focus ring, no Bootstrap blue glow next to it.
        $this->assertMatchesRegularExpression('/html\[data-avd-theme\] \.form-control:focus,\s*html\[data-avd-theme\] \.form-select:focus \{\s*box-shadow: none;/', $themeB);
        // auth-11 / watch-16: unchecked boxes and radios have a visible border; auth-31: error text in the danger ink.
        $this->assertStringContainsString('html[data-avd-theme] .form-check-input:not(:checked) {', $themeB);
        $this->assertStringContainsString('html[data-avd-theme] .invalid-feedback {', $themeB);

        $motion = $this->source('resources/sass/motion.scss');
        // channel-7: the card's own children keep their stacking, the "more" button its absolute place.
        $this->assertStringContainsString(':where(.avd-video-card, .video-card-modern', $motion);
        $this->assertDoesNotMatchRegularExpression('/^\.avd-video-card > \* \{/m', $motion);
        // channel-33: the title sheen runs once; account-41: the footer accent runs once and the footer scan never (its one
        // pass swept under the footer's links and buttons for five seconds).
        $this->assertStringContainsString('avd-title-sheen 4.5s ease-in-out 1 both', $motion);
        $this->assertStringNotContainsString('animation: avd-surface-scan', $motion);
        $this->assertStringNotContainsString('@keyframes avd-surface-scan', $motion);
        $this->assertStringContainsString('avd-accent-shift-x 8s ease 1 both', $this->source('resources/sass/enhancements.scss'));

        $video = $this->source('resources/sass/video.scss');
        // watch-46: the channel name under a card is a 24px target; watch-22: Reply in the look's accent.
        $this->assertMatchesRegularExpression('/a\.avd-channel-name \{[^}]*min-block-size: 24px;/', $video);
        $this->assertMatchesRegularExpression('/:root \.avd-comment-reply \{[^}]*var\(--avd-accent-ink/', $video);
        // watch-16: the report's success state is an icon tile in the look's colours.
        $this->assertStringContainsString(':root .avd-report-done__icon {', $video);
        // watch-20: the sticky "Back to Video" bar stays under the sheet backdrops.
        $this->assertMatchesRegularExpression('/#avdStickyWatchBar \{\s*z-index: 1039 !important;/', $video);

        // watch-19: rail labels take two lines before they cut.
        $this->assertStringContainsString('Rail labels take two lines', $this->source('resources/css/layout-theme-a.css'));
        // watch-31: the phone bottom bar is the 68px of 4.4.
        $this->assertMatchesRegularExpression('/:root \.avd-mobile-bottom-nav > div \{\s*min-height: 67px;\s*padding-block: 0 !important;/', $this->source('resources/css/layout-theme-a.css'));
    }

    public function test_components_of_the_lane(): void
    {
        // watch-45: one update per keystroke, the typed text highlighted.
        $search = $this->source('resources/js/components/Search/SearchBar.tsx');
        // (the helper lives in Search/highlight.ts, where tests/js/front_logic.test.ts runs it)
        $this->assertStringContainsString('export function highlightTyped(', $this->source('resources/js/components/Search/highlight.ts'));
        $this->assertStringContainsString('highlightTyped(result.title, query)', $search);
        $this->assertStringNotContainsString('onChange={handleInput}', $search);

        // watch-15: the Save dialog in the look's colours, its playlists kept, a new playlist private and ticked.
        $save = $this->source('resources/js/components/Playlists/Save.tsx');
        $create = $this->source('resources/js/components/Playlists/CreatePlaylist.tsx');
        $this->assertStringNotContainsString('btn-success', $save);
        $this->assertStringNotContainsString("color={'success'}", $create);
        $this->assertStringContainsString('saveQueryClient ??= new QueryClient', $save);
        $this->assertStringContainsString('defaultValue={String(PlaylistStatus.PRIVATE)}', $create);
        $this->assertStringContainsString('await savePlaylist({playlist_id: playlist.id, video_id: video});', $create);

        // watch-16: no stock picture, a short "Reported" state in place.
        $report = $this->source('resources/js/components/Actions/Report.tsx');
        $this->assertStringContainsString('avd-report-done__icon', $report);
        $this->assertStringNotContainsString('<img', $report);
        $this->assertStringContainsString("{t('Reported')}", $report);

        // studio-27: TomSelect's own words come translated from the layout's text block.
        $app = $this->source('resources/js/app.ts');
        $this->assertStringContainsString("document.getElementById('avd-i18n')", $app);
        $this->assertStringNotContainsString("'No results found for'", $app);

        // watch-13: the search filters stay native selects (no taller control replacing them).
        $this->assertSame(2, substr_count($this->source('resources/views/pages/search.blade.php'), 'data-native-select'));
    }

    /** The studio header is exactly its token tall; the page's end
     * keeps room for the floats. */
    public function test_back_office_header_height_and_end_room(): void
    {
        $header = $this->source('resources/sass/header.scss');
        $this->assertMatchesRegularExpression('/:root\[data-avd-member-back-office\] \.avd-header\.navbar \{\s*--bs-navbar-padding-y: 0;\s*box-sizing: border-box;\s*min-height: var\(--avd-header-h\);/', $header);
        $this->assertStringContainsString(':root[data-avd-member-back-office][data-avd-header-style="glass_island"] .avd-header.navbar {', $header);

        $chrome = $this->source('resources/sass/enhancements.scss');
        $this->assertMatchesRegularExpression('/:root \.avd-footer--btt-room \{\s*padding-block-end: 4\.5rem;/', $chrome);
        $this->assertStringContainsString('padding-block-end: max(4.5rem, var(--mk-toast-room, 6rem));', $chrome);
        $this->assertStringContainsString("root.style.setProperty('--mk-toast-room'", $this->source('resources/js/layout/toasts.ts'));
        // The footer ad comes after the footer: the room is below it then.
        $this->assertMatchesRegularExpression('/:root \.avd-footer--btt-room ~ #ad-slot-footer \{\s*margin-block-end: 4\.5rem;/', $chrome);
        $this->assertMatchesRegularExpression('/:root \.avd-footer:has\(~ #ad-slot-footer\) \{\s*padding-block-end: 0;/', $chrome);
        $this->assertStringContainsString("|| (bool) (\$showDailyPill ?? false);", $this->source('resources/views/layouts/menus/footer.blade.php'));
    }

    public function test_watch_page_dock_and_up_next_strip(): void
    {
        $show = $this->source('resources/views/videos/show.blade.php');
        // watch-7: the watch page's own rules are its cached stylesheet, no longer an inline block.
        $css = $this->source('resources/css/watch-page.css');
        // watch-21: from 768px the dock wraps; on phones the edge with more buttons fades.
        $this->assertMatchesRegularExpression('/@media \(min-width: 768px\) \{\s*\.avd-cinema-dock \{\s*flex-wrap: wrap;/', $css);
        $this->assertStringContainsString('.avd-cinema-dock[data-avd-overflow="end"]', $css);
        $this->assertStringContainsString("dock.setAttribute('data-avd-overflow', value);", $show);
        // watch-33: the Up next strip leaves room for two lines of title.
        $this->assertStringContainsString('grid-auto-columns: minmax(min(16rem, 78vw), 1fr);', $css);
        // perf-5: the action row keeps its place while its buttons load.
        $this->assertStringContainsString('.avd-action-row interaction-buttons:not(:defined)', $css);
        $this->assertStringNotContainsString('id="avdWatchEnhancedStyles"', $show);
    }
}
