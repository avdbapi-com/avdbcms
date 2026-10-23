<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Support\AppearanceSettings;
use App\Support\ThemeTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Theme collection v2 delivery (App\Support\ThemeCss::themeTags + the head of components/layout.blade.php):
 * the active theme's token file and its colour style's file are linked as hashed build files before the theme
 * CSS that reads them; with AVD_THEME_CSS_INLINE (or a build without the entry) only the blocks the page uses
 * are inlined; ?theme=, ?theme_flavor= and ?theme_mode= previews switch the tokens for one request; owner
 * colours and fonts follow; the side menu attributes match the menu the page renders (public and back office);
 * guests get the home feed's first page with the page (the layout's early feed: no request, first row of pictures).
 * The pages render against a throwaway build directory, so the test does not depend on npm run build.
 */
class ThemeCssServingTest extends TestCase
{
    use RefreshDatabase;

    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The base TestCase fakes Vite; these tests need the real tags against a throwaway build.
        $this->withVite();
        $this->publicPath = storage_path('framework/testing/theme-css-'.uniqid());
        $this->writeBuild();
        $this->app->usePublicPath($this->publicPath);

        config([
            'site.smart_discovery_rail_enabled' => false,
            'site.creator_spotlight_enabled' => false,
            'site.trending_collections_enabled' => false,
        ]);
        // No custom colours: a seed migration stores the v1 default accent, which the theme catalogue v2 upgrade
        // deletes as an untouched factory value.
        $colourKeys = array_keys(ThemeTokens::OWNER_COLOURS);
        Setting::query()->where('group', 'appearance')->whereIn('key', $colourKeys)->delete();
        Setting::clearCache($colourKeys, ['appearance']);
        $this->setAppearance(['theme_preset' => 'tokyo_neon', 'theme_flavor' => 'signature', 'theme' => 'dark']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    public function test_public_page_links_the_theme_token_file_before_the_theme_css(): void
    {
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-avd-theme="tokyo_neon"', $html);
        $this->assertStringContainsString('data-avd-flavor="signature"', $html);
        $this->assertStringContainsString('data-avd-theme-family="studio"', $html);
        $this->assertStringContainsString('data-avd-hero-deco="ticker"', $html);
        $this->assertStringContainsString('data-avd-card-frame="framed"', $html);
        $this->assertStringContainsString('data-avd-backdrop="dawn_glow"', $html);
        $this->assertStringContainsString('data-avd-sidebar-style="full"', $html);

        $tokens = strpos($html, '<link rel="stylesheet" href="'.url('build/assets/theme-tokyo_neon-test.css').'"');
        $themeA = strpos($html, 'build/assets/layout-theme-a-test.css');
        $this->assertNotFalse($tokens);
        $this->assertNotFalse($themeA);
        $this->assertLessThan($themeA, $tokens, 'the token file comes before the theme CSS that reads it');
        // The signature colour style is in the theme file: no colour style file.
        $this->assertStringNotContainsString('theme-tokyo_neon--', $html);
        $this->assertStringNotContainsString('id="avd-theme-tokens"', $html);
        $this->assertStringNotContainsString('id="avd-theme-owner"', $html);
        // Compatibility aliases resolve from the tokens instead of the old colour settings.
        $this->assertStringContainsString('--avd-theme-primary: var(--avd-primary);', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#ff0055">', $html);
        // Bootstrap colours plain links through the -rgb triplets: they follow the fitted --bs-primary.
        $this->assertStringContainsString('--bs-link-color-rgb: var(--bs-primary-rgb);', $html);
        $this->assertStringContainsString('--bs-link-hover-color-rgb: var(--bs-primary-rgb);', $html);
    }

    public function test_guests_get_the_home_feeds_first_page_with_the_page(): void
    {
        // The API's own guest cache entry for the home feed's first page (Api\Public\VideoController::index).
        $perPage = max(1, min(AppearanceSettings::HOME_FEED_MAX_ITEMS, (int) config('site.per_page_videos', 24)));
        $apiKey = "feed:index:newest:c0:p{$perPage}:cur".md5('').':en';
        $page = ['data' => [
            ['id' => 1, 'title' => 'One </script><b>', 'thumbnail' => '/images/one.jpg'],
            ['id' => 2, 'is_private' => true],
            ['id' => 3, 'title' => 'Three', 'thumbnail' => '/images/three.jpg'],
        ], 'links' => [], 'meta' => ['next_cursor' => null, 'per_page' => $perPage]];
        Cache::put($apiKey, $page, 60);

        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        // The page carries it (escaped for a script element) and the <head> script hands it to VideosList instead
        // of requesting it.
        $this->assertSame(1, preg_match('#<script type="application/json" id="avdFeedEarlyPage">(.*?)</script>#s', $html, $shipped));
        $this->assertSame($page, json_decode($shipped[1], true));
        $this->assertStringContainsString("(w.__avdFeedShipped = w.__avdFeedShipped || {})[url] = page;", $html);
        // Under the ranked lists (no hero photo) the first row's pictures load with the page: preloaded (2 below
        // 768px, 4 above) and shown by the skeleton's first cards; the private item has none.
        $this->assertStringContainsString('<link rel="preload" as="image" href="/images/one.jpg" fetchpriority="high">', $html);
        $this->assertStringContainsString('<link rel="preload" as="image" href="/images/three.jpg" fetchpriority="high">', $html);
        $this->assertStringContainsString('<div class="avd-placeholder avd-placeholder-thumb avd-has-photo"><img src="/images/one.jpg" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async"></div>', $html);
        $this->assertStringContainsString('<div class="avd-placeholder avd-placeholder-thumb avd-has-photo"><img src="/images/three.jpg" alt="" width="1280" height="720" loading="eager" decoding="async"></div>', $html);
        // The private item (no picture) keeps a plain placeholder in its place.
        $one = (int) strpos($html, '<img src="/images/one.jpg"');
        $three = (int) strpos($html, '<img src="/images/three.jpg"');
        $this->assertStringContainsString('<div class="avd-placeholder avd-placeholder-thumb"></div>', substr($html, $one, $three - $one));

        // A cold cache: the page ships without the first page (the <head> script's early request loads it, which also
        // warms the API's entry for the next guests) and carries only the first row's pictures, from the feed query
        // itself (four rows and their thumbnails): shown by the skeleton, preloaded, handed to VideosList's skeleton.
        Cache::flush();
        $category = Category::query()->create(['title' => 'Cold', 'slug' => 'cold', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);
        $video = Video::factory()->for($category)->for(User::factory()->create(), 'user')->withStatus(VideoStatus::PUBLIC)
            ->create(['poster_url' => '/images/cold-first.jpg', 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $thumb = (string) $video->refresh()->thumbnail_url;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $feedQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "videos"') && str_contains($query['query'], 'limit 4'));
        DB::disableQueryLog();
        $this->assertCount(1, $feedQueries, 'one first-row query, no first page');
        $this->assertStringNotContainsString('id="avdFeedEarlyPage"', $html);
        $this->assertStringContainsString('<div class="avd-placeholder avd-placeholder-thumb avd-has-photo"><img src="'.e($thumb).'"', $html);
        $this->assertStringContainsString('<link rel="preload" as="image" href="'.e($thumb).'" fetchpriority="high">', $html);
        [$feeds, , , $warm] = $this->earlyFeedArguments($html);
        $this->assertSame([[url('/api/videos?source=newest&per_page='.$perPage.'&locale=en'), null, null, [$thumb]]], $feeds);
        $this->assertTrue($warm);
        // VideosList's skeleton gets them with the server skeleton's loading rule (eager: no hero photo here).
        $this->assertStringContainsString('(w.__avdFeedThumbs = w.__avdFeedThumbs || {})[url] = {thumbs: thumbs, eager: !!warm && !media};', $html);
        $this->assertStringContainsString('var registry = w.__avdFeedEarly = w.__avdFeedEarly || {};', $html);
        $this->assertFalse(Cache::has($apiKey));
        $this->assertFalse(Cache::has('page:'.$apiKey));
        $this->getJson('/api/videos?source=newest&per_page='.$perPage)->assertOk()->assertHeader('X-Feed-Cache', 'MISS');
        $this->assertTrue(Cache::has($apiKey));
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="avdFeedEarlyPage">(.*?)</script>#s', $html, $shipped));
        $this->assertSame(Cache::get($apiKey), json_decode($shipped[1], true));

        // Signed-in members keep the early request (the API does not cache their pages).
        $html = $this->actingAs(User::factory()->create())->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="avdFeedEarlyPage"', $html);
        $this->assertStringNotContainsString('avd-has-photo', $html);
        $this->assertStringContainsString('var registry = w.__avdFeedEarly = w.__avdFeedEarly || {};', $html);
    }

    public function test_strip_card_feeds_reserve_a_phone_screen_of_skeleton_cards(): void
    {
        // Strip cards stand one per row on phones and are a quarter as tall: the home main feed's server skeleton
        // holds 12 of them below 768px (phone-strip-screen, VideosList PHONE_STRIP_SKELETON_ITEMS), 4 rows from 768px.
        $this->setAppearance(['video_card_layout_style' => 'card_horizontal_strip', 'mobile_video_columns' => '1']);
        $feed = $this->feedSkeleton($this->get(route('pages.home'))->assertOk()->getContent(), 'source=newest');
        $this->assertStringContainsString(' phone-strip-screen ', $feed['tag']);
        $this->assertSame(12, $feed['phone'], 'home, phone');
        $this->assertSame(8, $feed['tablet'], 'home, 768px: 4 rows of 2');

        // Any other feed keeps 4 rows on phones: the trend page's feed is often shorter than a phone screen, and a
        // 12-card skeleton shrank by half a screen when its 6 cards arrived (the footer moved into view: 0.09 CLS).
        $feed = $this->feedSkeleton($this->get(route('pages.trend'))->assertOk()->getContent(), '/api/videos/trend');
        $this->assertStringNotContainsString('phone-strip-screen', $feed['tag']);
        $this->assertSame(4, $feed['phone'], 'trend, phone');

        // A standard card keeps 4 rows (2 columns on phones), also on the home page.
        $this->setAppearance(['video_card_layout_style' => 'card_standard', 'mobile_video_columns' => '2']);
        $feed = $this->feedSkeleton($this->get(route('pages.home'))->assertOk()->getContent(), 'source=newest');
        $this->assertSame(8, $feed['phone'], 'home, standard cards');
    }

    public function test_the_visible_main_feed_is_the_early_feed_on_phones_when_the_rails_are_hidden_there(): void
    {
        // grid_modern under a list hero (ticker): the discovery rails start at md (hidden on phones). From 768px the first
        // rail's feed stays the early one (its first row is the largest picture of the first screen there); below 768px
        // the main feed, the one a phone shows, is fetched with the page instead of waiting for the load event.
        config(['site.smart_discovery_rail_enabled' => true, 'site.smart_discovery_rail_videos_enabled' => '1']);
        $category = Category::query()->create(['title' => 'Rails', 'slug' => 'rails', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);
        $video = Video::factory()->for($category)->for(User::factory()->create(), 'user')->withStatus(VideoStatus::PUBLIC)
            ->create(['poster_url' => '/images/rails-first.jpg', 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        $thumb = (string) $video->refresh()->thumbnail_url;
        $this->setAppearance(['home_layout_mode' => 'grid_modern', 'hero_layout_mode' => 'live_ticker_stream']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringContainsString('class="avd-home-rails d-none d-md-block"', $html);
        $this->assertSame(2, preg_match_all('#<videos-area\s[^>]*\bearly priority-row\b#', $html), 'one early feed per width');
        $this->assertSame(1, preg_match('#data-avd-widget-feed="smart-discovery">\s*(<videos-area\s[^>]*>)#', $html, $rail));
        $this->assertStringContainsString(' early priority-row', $rail[1]);
        $this->assertStringContainsString('early-media="(min-width: 768px)"', $rail[1], 'the rail feed: from 768px');
        $this->assertSame(1, preg_match('#<section class="avd-home-feed[^>]*>.*?(<videos-area\s[^>]*>)#s', $html, $main));
        $this->assertStringContainsString('source=newest', $main[1]);
        $this->assertStringContainsString(' early priority-row', $main[1]);
        $this->assertStringContainsString('early-media="(max-width: 767.98px)"', $main[1], 'the main feed: below 768px');
        // Early at one width only: their skeleton pictures load lazily; the <head> preloads them for their width only
        // (2 of the main feed's first row below 768px, the rail feed's row from 768px).
        $this->assertStringNotContainsString('loading="eager"', $html);
        $this->assertStringContainsString('<link rel="preload" as="image" href="'.e($thumb).'" fetchpriority="high" media="(max-width: 767.98px)">', $html);
        $this->assertStringContainsString('<link rel="preload" as="image" href="'.e($thumb).'" fetchpriority="high" media="(min-width: 768px)">', $html);
        [$feeds] = $this->earlyFeedArguments($html);
        $urlOf = fn (string $tag): string => preg_match('#\surl="([^"]*)"#', $tag, $url) === 1 ? html_entity_decode($url[1]) : '';
        $this->assertSame(
            [[$urlOf($rail[1]), '(min-width: 768px)'], [$urlOf($main[1]), '(max-width: 767.98px)']],
            array_map(fn (array $feed): array => [$feed[0], $feed[1]], $feeds),
            'the <head> script requests each feed at its own width'
        );

        // A text banner keeps the rails on phones: the page's first feed (the first rail's) is the early one, everywhere.
        $this->setAppearance(['hero_layout_mode' => 'classic_banner']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="avd-home-rails d-none d-md-block"', $html);
        $this->assertSame(1, preg_match_all('#<videos-area\s[^>]*\bearly priority-row\b#', $html));
        $this->assertSame(1, preg_match('#data-avd-widget-feed="smart-discovery">\s*(<videos-area\s[^>]*>)#', $html, $rail));
        $this->assertStringContainsString(' early priority-row', $rail[1]);
        $this->assertStringNotContainsString('early-media="', $html);
        $this->assertCount(1, $this->earlyFeedArguments($html)[0]);
    }

    /**
     * The arguments the layout passes to the <head> early-feed script: [[url, media, page id, first-row pictures], ...],
     * locale, CSRF token, warm.
     */
    private function earlyFeedArguments(string $html): array
    {
        $this->assertSame(1, preg_match('#\}\)\.apply\(null, \[window\]\.concat\((.*?)\)\);\s*</script>#s', $html, $call), 'the early-feed script');

        return json_decode($call[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The opening tag of the <videos-area> whose URL contains $urlPart, and how many skeleton cards its server
     * skeleton shows below 768px ('d-none' hides a card from the phone tier up) and from 768px ('d-md-none' hides it).
     *
     * @return array{tag: string, phone: int, tablet: int}
     */
    private function feedSkeleton(string $html, string $urlPart): array
    {
        $this->assertSame(1, preg_match('#(<videos-area\s+url="[^"]*'.preg_quote($urlPart, '#').'[^"]*"[^>]*>)(.*?)</videos-area>#s', $html, $feed));
        preg_match_all('#<div class="(col[^"]*)"><div class="avd-video-card">#', $feed[2], $columns);
        $shown = function (string $class, string $tier): bool {
            $classes = explode(' ', $class);
            $hiddenFrom = ['d-none' => 0, 'd-md-none' => 1, 'd-xl-none' => 2, 'd-xxl-none' => 3];
            $shownFrom = ['d-md-block' => 1, 'd-xl-block' => 2, 'd-xxl-block' => 3];
            $level = $tier === 'phone' ? 0 : 1;
            $visible = true;
            foreach ([0, 1] as $step) {
                if ($step > $level) {
                    break;
                }
                foreach ($classes as $one) {
                    if (($hiddenFrom[$one] ?? null) === $step) {
                        $visible = false;
                    }
                    if (($shownFrom[$one] ?? null) === $step) {
                        $visible = true;
                    }
                }
            }

            return $visible;
        };

        return [
            'tag' => preg_replace('/\s+/', ' ', $feed[1]),
            'phone' => count(array_filter($columns[1], fn (string $class): bool => $shown($class, 'phone'))),
            'tablet' => count(array_filter($columns[1], fn (string $class): bool => $shown($class, 'tablet'))),
        ];
    }

    public function test_a_colour_style_links_its_own_file_after_the_theme_file(): void
    {
        $this->setAppearance(['theme_flavor' => 'coral']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $theme = strpos($html, '<link rel="stylesheet" href="'.url('build/assets/theme-tokyo_neon-test.css').'"');
        $style = strpos($html, '<link rel="stylesheet" href="'.url('build/assets/theme-tokyo_neon--coral-test.css').'"');
        $this->assertNotFalse($theme);
        $this->assertNotFalse($style);
        $this->assertLessThan($style, $theme);
        $this->assertLessThan(strpos($html, 'build/assets/layout-theme-a-test.css'), $style);
        $this->assertSame(1, substr_count($html, '<link rel="stylesheet" href="'.url('build/assets/theme-tokyo_neon--')), 'only the active colour style');

        // Previews load the previewed colour style; an old id brings its own.
        $this->assertStringContainsString('theme-tokyo_neon--electric-test.css', $this->get('/?theme_flavor=electric')->assertOk()->getContent());
        $this->assertStringContainsString('theme-tokyo_neon--coral-test.css', $this->get('/?theme=cyberpunk_neon')->assertOk()->getContent());
        $this->assertSame(['resources/css/themes/tokyo_neon.css'], \App\Support\ThemeCss::themeEntries('tokyo_neon', 'signature'));
        $this->assertSame([], \App\Support\ThemeCss::themeEntries('../x', 'coral'));
    }

    public function test_previews_switch_the_theme_for_one_request(): void
    {
        $preview = $this->get('/?theme=daylight_paper')->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-theme="daylight_paper"', $preview);
        $this->assertStringContainsString('build/assets/theme-daylight_paper-test.css', $preview);
        $this->assertStringNotContainsString('theme-tokyo_neon-test.css', $preview);

        // An old id previews its alias theme + colour style.
        $old = $this->get('/?theme=cyberpunk_neon')->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-theme="tokyo_neon"', $old);
        $this->assertStringContainsString('data-avd-flavor="coral"', $old);

        $flavor = $this->get('/?theme_flavor=electric')->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-theme="tokyo_neon"', $flavor);
        $this->assertStringContainsString('data-avd-flavor="electric"', $flavor);

        $mode = $this->get('/?theme=tokyo_neon&theme_mode=light')->assertOk()->getContent();
        $this->assertStringContainsString('data-bs-theme="light"', $mode);

        $saved = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-avd-theme="tokyo_neon"', $saved);
        $this->assertStringContainsString('data-avd-flavor="signature"', $saved);
        $this->assertStringContainsString('data-bs-theme="dark"', $saved);
    }

    public function test_inline_mode_prints_only_the_blocks_the_page_uses(): void
    {
        config(['app.theme_css_inline' => true]);
        $this->setAppearance(['theme_flavor' => 'coral', 'sidebar_theme_switcher_enabled' => '0', 'js_theme_memory_enabled' => '0']);
        config(['site.footer_theme_switcher_enabled' => false]);

        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $inline = $this->inlineTokens($html);

        $this->assertStringContainsString('html[data-bs-theme="dark"]{--avd-bg: #0b0e15;', $inline);
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor="coral"]{', $inline);
        $this->assertStringNotContainsString('data-bs-theme="light"', $inline);
        $this->assertStringNotContainsString('data-avd-flavor="electric"', $inline);
        $this->assertStringNotContainsString('theme-tokyo_neon-test.css', $html);
        $this->assertLessThan(4096, strlen((string) gzencode($inline, 9)), 'active mode + flavor block, gzip');

        // A page whose visitor can switch the mode carries both modes.
        $this->setAppearance(['sidebar_theme_switcher_enabled' => '1']);
        $both = $this->inlineTokens($this->get(route('pages.home'))->assertOk()->getContent());
        $this->assertStringContainsString('html[data-bs-theme="light"]{', $both);
        $this->assertStringContainsString('html[data-bs-theme="light"][data-avd-flavor="coral"]{', $both);

        // RTL pages add the mirrored shapes of themes that have them.
        config(['site.rtl_support' => true]);
        $this->setAppearance(['theme_preset' => 'shibuya_rain', 'theme_flavor' => 'signature']);
        $rtl = $this->inlineTokens($this->get(route('pages.home'))->assertOk()->getContent());
        $this->assertStringContainsString('html[data-bs-theme="dark"][dir="rtl"]{', $rtl);
        $this->assertStringNotContainsString('data-avd-hero-deco', $rtl);

        // A Phase B theme brings its own hero decoration (resources/css/themes/hero-decos, appended to its token file)
        // with its blocks, phone rules included; no other decoration comes along.
        $this->setAppearance(['theme_preset' => 'neo_pop', 'theme_flavor' => 'signature', 'theme' => 'light']);
        $pop = $this->inlineTokens($this->get(route('pages.home'))->assertOk()->getContent());
        $this->assertStringContainsString('html[data-avd-hero-deco="banner_pop"][data-avd-theme] .avd-pop-card{', $pop);
        $this->assertStringContainsString('@media (max-width: 767.98px){', $pop);
        $this->assertSame(1, preg_match_all('/data-avd-hero-deco="([a-z_]+)"/', $pop, $decos) > 0 ? count(array_unique($decos[1])) : 0);
    }

    public function test_a_build_without_the_entry_falls_back_to_inline(): void
    {
        $manifest = json_decode((string) file_get_contents($this->publicPath.'/build/manifest.json'), true);
        unset($manifest['resources/css/themes/tokyo_neon.css']);
        file_put_contents($this->publicPath.'/build/manifest.json', json_encode($manifest));

        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringContainsString('html[data-bs-theme="dark"]{--avd-bg: #0b0e15;', $this->inlineTokens($html));

        // A missing colour style entry inlines the theme and the colour style blocks.
        $manifest = json_decode((string) file_get_contents($this->publicPath.'/build/manifest.json'), true);
        $manifest['resources/css/themes/tokyo_neon.css'] = ['file' => 'assets/theme-tokyo_neon-test.css', 'src' => 'resources/css/themes/tokyo_neon.css', 'isEntry' => true];
        unset($manifest['resources/css/themes/tokyo_neon/coral.css']);
        file_put_contents($this->publicPath.'/build/manifest.json', json_encode($manifest));
        $this->setAppearance(['theme_flavor' => 'coral']);

        $inline = $this->inlineTokens($this->get(route('pages.home'))->assertOk()->getContent());
        $this->assertStringContainsString('html[data-bs-theme="dark"]{--avd-bg: #0b0e15;', $inline);
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor="coral"]{--avd-brand: #ff3d61;', $inline);
    }

    public function test_side_menu_style_matches_the_menu_the_page_renders(): void
    {
        // broadsheet's own side menu is 'hidden' (a drawer).
        $this->setAppearance(['theme_preset' => 'broadsheet', 'theme_flavor' => 'signature', 'front_sidebar_pinned_enabled' => '1']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        if (class_exists(\App\Support\ThemeStructure::class)) {
            // WP5's resolver owns the structure and the menu markup: has-sidebar only for a pinned menu.
            $this->assertSame($this->sideMenuStyle($html) !== 'hidden', $this->hasPinnedMenu($html));

            return;
        }

        // Without it the v1 menu markup stays (sidebars/front, show_sidebar), so body.has-sidebar keeps the v1 rule:
        // the theme CSS makes the 'hidden' style a drawer with a 0px offset, older theme CSS keeps the menu pinned.
        $this->assertSame('hidden', $this->sideMenuStyle($html));
        $this->assertTrue($this->hasPinnedMenu($html));
        $this->assertStringContainsString('--avd-sidebar-w: 0px;', (string) file_get_contents(resource_path('css/themes/broadsheet.css')));
        $this->assertSame('full', $this->sideMenuStyle($this->get('/?theme=tokyo_neon')->assertOk()->getContent()));
        $this->assertSame('rail', $this->sideMenuStyle($this->get('/?theme=shibuya_rain')->assertOk()->getContent()));

        // No has-sidebar where the v1 menu is not pinned: the watch page, and every page with pinning off.
        $this->setAppearance(['front_sidebar_pinned_enabled' => '0']);
        $drawer = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame('hidden', $this->sideMenuStyle($drawer));
        $this->assertFalse($this->hasPinnedMenu($drawer));
    }

    public function test_back_office_pages_keep_their_pinned_menu_whatever_the_theme(): void
    {
        // The v1 admin (kept for one release) renders through components/layout.
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();
        $this->setAppearance(['theme_preset' => 'obsidian_gold', 'theme_flavor' => 'signature', 'header_style' => 'solid_dark', 'front_sidebar_pinned_enabled' => '1']);

        foreach ([route('admin.settings.index'), route('admin.settings.index').'?theme=broadsheet'] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertSame('full', $this->sideMenuStyle($html), $url);
            $this->assertStringContainsString('data-avd-header-style="solid_dark"', $html, $url);
            $this->assertStringContainsString('avd-admin', $html, $url);
            $this->assertTrue($this->hasPinnedMenu($html), $url);
            // Not the theme's 0px (hidden) or rail width, after the token file.
            $menu = strpos($html, '<style id="avd-back-office-menu">html[data-bs-theme][data-avd-theme]{--avd-sidebar-w:260px}</style>');
            $this->assertNotFalse($menu, $url);
            // The theme CSS keys the back-office menu width on this marker (no page-wide :has() test).
            $this->assertMatchesRegularExpression('/<html[^>]*\sdata-avd-back-office[\s>]/', $html, $url);
            $this->assertLessThan($menu, strpos($html, 'build/assets/theme-obsidian_gold-test.css') ?: strpos($html, 'build/assets/theme-broadsheet-test.css'), $url);
        }

        // Public pages keep the theme's own side menu width.
        $public = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertStringNotContainsString('avd-back-office-menu', $public);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*\sdata-avd-back-office[\s>]/', $public);
    }

    public function test_query_parameters_that_are_not_single_values_are_ignored(): void
    {
        foreach (['sidebar_style', 'surface_style', 'typography_pairing', 'radius_scale', 'motion_level', 'content_density', 'header_style', 'effects_enabled', 'theme', 'theme_flavor'] as $key) {
            $html = $this->get('/?'.$key.'[]=x')->assertOk()->getContent();
            $this->assertStringContainsString('data-avd-theme="tokyo_neon"', $html, $key);
        }
    }

    public function test_the_layout_prints_no_blur_of_its_own(): void
    {
        $this->setAppearance(['effects_enabled' => '1', 'css_blur_effects_enabled' => '1']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        // layout-theme-a/b blur only the allowed surfaces under the rich tier; the layout keeps only the
        // reduced-transparency kill switch (the balanced-tier cap and the hover frost of the v1 CSS are gone).
        $this->assertStringNotContainsString('html[data-avd-tier="balanced"][data-bs-theme] #responsive-sidebar.avd-sidebar {', $html);
        $this->assertStringNotContainsString('backdrop-filter: blur(8px) saturate(160%) !important;', $html);
        $this->assertMatchesRegularExpression('/<style id="avd-theme-tail">\s*@media \(prefers-reduced-transparency: reduce\) \{/', $html);
        $this->assertStringNotContainsString('backdrop-filter: blur(', substr($html, (int) strpos($html, '<style id="avd-theme-tail">'), 4000));
    }

    public function test_owner_colours_are_fitted_and_printed_after_the_token_file(): void
    {
        $this->setAppearance(['primary_color' => '#2563eb']);

        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $owner = strpos($html, '<style id="avd-theme-owner">');

        $this->assertNotFalse($owner);
        $this->assertLessThan($owner, strpos($html, 'theme-tokyo_neon-test.css'));
        $this->assertLessThan(strpos($html, 'layout-theme-a-test.css'), $owner);
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor]{--avd-brand:#2563eb;', $html);
        $this->assertStringContainsString('--avd-primary:#2563eb;', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#2563eb">', $html);

        // The colour style's own value is not an override.
        $this->setAppearance(['primary_color' => '#ff0055']);
        $this->assertStringNotContainsString('id="avd-theme-owner"', $this->get(route('pages.home'))->assertOk()->getContent());
    }

    public function test_pure_black_is_derived_by_the_colour_engine(): void
    {
        // "Pure black in dark mode" on a theme with its own dark colours: the dark tokens are derived again on black.
        $this->setAppearance(['theme_preset' => 'obsidian_gold', 'theme_flavor' => 'signature', 'theme_oled_black_enabled' => '1']);
        $html = $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-avd-oled="on"', $html);
        $this->assertSame(1, preg_match('#<style id="avd-theme-owner">(.*?)</style>#s', $html, $owner));
        $this->assertStringContainsString('html[data-bs-theme="dark"][data-avd-flavor]{--avd-bg:#000000;--avd-bg-2:#000000;', $owner[1]);
        $this->assertStringContainsString('--bs-body-bg:#000000', $owner[1]);
        $this->assertStringContainsString('--avd-page-backdrop:none', $owner[1]);
        $this->assertStringNotContainsString('html[data-bs-theme="light"]', $owner[1]);

        // A theme that is black already needs no colour block; off, none is printed (font tokens may still be).
        $this->setAppearance(['theme_preset' => 'pure_oled_black']);
        $this->assertStringNotContainsString('[data-avd-flavor]{--avd-bg', $this->get(route('pages.home'))->assertOk()->getContent());
        $this->setAppearance(['theme_preset' => 'obsidian_gold', 'theme_oled_black_enabled' => '0']);
        $this->assertStringNotContainsString('[data-avd-flavor]{--avd-bg', $this->get(route('pages.home'))->assertOk()->getContent());
    }

    public function test_fonts_follow_the_theme_pairing_within_the_budget(): void
    {
        $this->setAppearance(['typography_pairing' => 'modern_tech', 'typography_controls_enabled' => '1', 'font_family' => 'display']);

        $html = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame(
            ['https://fonts.googleapis.com/css2?family=Outfit:wght@400..800&amp;family=Plus+Jakarta+Sans:wght@400..800&amp;display=swap'],
            $this->fontUrls($html)
        );

        // Another pairing: its families, Poppins with its static weights, and the font tokens after the token file.
        $this->setAppearance(['typography_pairing' => 'playful_pop']);
        $pop = $this->get(route('pages.home'))->assertOk()->getContent();
        $this->assertSame(
            ['https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&amp;family=Quicksand:wght@500;700&amp;display=swap'],
            $this->fontUrls($pop)
        );
        $this->assertStringContainsString("html[data-bs-theme]{--avd-font-display:'Poppins', system-ui", $pop);

        // A theme preview uses that theme's own pairing and styles (italic Playfair only).
        $this->assertSame(
            ['https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@1,500..900&amp;family=Inter:wght@400..800&amp;display=swap'],
            $this->fontUrls($this->get('/?theme=silver_screen')->assertOk()->getContent())
        );
    }

    public function test_admin_pages_keep_every_v1_family_with_poppins_fixed(): void
    {
        // The v1 admin (kept for one release) renders through components/layout.
        config(['admin.ui' => 'v1']);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->getContent();
        $urls = $this->fontUrls($html);

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('family=Poppins:wght@400;500;600;700', $urls[0]);
        $this->assertStringContainsString('family=Rajdhani:wght@500;600;700', $urls[0]);
        $this->assertStringNotContainsString('Poppins:wght@400..700', $urls[0]);
        $this->assertStringContainsString('data-avd-theme="tokyo_neon"', $html);
    }

    private function setAppearance(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set($key, $value, 'appearance');
        }

        // What the next request's provider boot does (the theme catalogue v2 binds AppearanceSettings::current() there).
        if (method_exists(AppearanceSettings::class, 'applyToConfig')) {
            AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
        }

        foreach ($values as $key => $value) {
            config(['site.'.$key => $value]);
        }
    }

    private function sideMenuStyle(string $html): string
    {
        $this->assertSame(1, preg_match('/<html[^>]*\sdata-avd-sidebar-style="([a-z]+)"/', $html, $m), 'data-avd-sidebar-style');

        return $m[1];
    }

    private function hasPinnedMenu(string $html): bool
    {
        $this->assertSame(1, preg_match('/<body class="([^"]*)"/', $html, $m));

        return in_array('has-sidebar', explode(' ', $m[1]), true);
    }

    private function inlineTokens(string $html): string
    {
        $this->assertSame(1, preg_match('#<style id="avd-theme-tokens">(.*?)</style>#s', $html, $m), 'inline token CSS');

        return $m[1];
    }

    /** @return list<string> */
    private function fontUrls(string $html): array
    {
        preg_match_all('#<link href="(https://fonts\.googleapis\.com/css2\?[^"]+)" rel="stylesheet"#', $html, $m);

        return $m[1];
    }

    /** A minimal Vite build: the app entry, layout-theme-a/b and one hashed file per token file (themes and colour styles). */
    private function writeBuild(): void
    {
        File::ensureDirectoryExists($this->publicPath.'/build/assets');
        $manifest = [
            'resources/js/app.ts' => ['file' => 'assets/main-test.js', 'src' => 'resources/js/app.ts', 'isEntry' => true],
            'resources/css/layout-theme-a.css' => ['file' => 'assets/layout-theme-a-test.css', 'src' => 'resources/css/layout-theme-a.css', 'isEntry' => true],
            'resources/css/layout-theme-b.css' => ['file' => 'assets/layout-theme-b-test.css', 'src' => 'resources/css/layout-theme-b.css', 'isEntry' => true],
        ];
        file_put_contents($this->publicPath.'/build/assets/layout-theme-a-test.css', '.a{}');
        file_put_contents($this->publicPath.'/build/assets/layout-theme-b-test.css', '.b{}');

        foreach ([...glob(resource_path('css/themes/*.css')), ...glob(resource_path('css/themes/*/*.css'))] as $source) {
            // <id>.css -> theme-<id>, <id>/<flavor>.css -> theme-<id>--<flavor> (vite.config.ts).
            $path = substr(str_replace('\\', '/', $source), strlen(str_replace('\\', '/', resource_path('css/themes/'))));
            $name = 'theme-'.str_replace('/', '--', substr($path, 0, -4));
            $entry = 'resources/css/themes/'.$path;
            $manifest[$entry] = ['file' => 'assets/'.$name.'-test.css', 'src' => $entry, 'isEntry' => true];
            copy($source, $this->publicPath.'/build/assets/'.$name.'-test.css');
        }

        file_put_contents($this->publicPath.'/build/manifest.json', json_encode($manifest));
    }
}
